<?php
/**
 * GNCP Unified Session Guard
 * Validates active session, verifies single-active session token against MariaDB,
 * enforces idle session timeouts, and enforces role-based access control.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/response.php';

function initSession() {
    if (session_status() === PHP_SESSION_NONE) {
        @ini_set('session.cookie_httponly', '1');
        @ini_set('session.use_strict_mode', '1');
        @ini_set('session.gc_maxlifetime', '86400');
        if (!headers_sent()) {
            session_set_cookie_params([
                'lifetime' => 86400,
                'path'     => '/',
                'domain'   => '',
                'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
                'httponly' => true,
                'samesite' => 'Lax'
            ]);
        }
        @session_start();
    }
}

function requireAuth(array $allowedRoles = []) {
    initSession();

    $adminSession   = $_SESSION['gncp_admin_user'] ?? null;
    $stationSession = $_SESSION['gncp_station_user'] ?? null;
    $studentSession = $_SESSION['gncp_student'] ?? null;

    $userVal = $adminSession ?: ($stationSession ?: $studentSession);

    if (!$userVal) {
        session_write_close();
        sendResponse(false, null, 'Authentication required. Please log in.', 401);
    }

    $user = is_array($userVal) ? $userVal : json_decode($userVal, true);

    if (!$user || (empty($user['role']) && empty($user['id']))) {
        session_write_close();
        sendResponse(false, null, 'Invalid session state.', 401);
    }

    // ── 1. Idle Inactivity Timeout (2 Hours = 7200s) ──
    $maxIdleSec = 7200;
    $now = time();
    if (isset($_SESSION['last_activity']) && ($now - $_SESSION['last_activity']) > $maxIdleSec) {
        $_SESSION = [];
        session_unset();
        session_destroy();
        sendResponse(false, null, 'Your session has expired due to inactivity. Please log in again.', 401);
    }
    $_SESSION['last_activity'] = $now;

    // ── 2. Single-Active Session Verification against MariaDB ──
    $sessionToken = $user['session_token'] ?? null;
    $username     = $user['username'] ?? ($user['id'] ?? null);

    if ($sessionToken && $username) {
        try {
            $pdo = Database::getInstance();
            $isStudent = (!empty($user['role']) && strtoupper($user['role']) === 'STUDENT') || (isset($user['id']) && !isset($user['role']));
            
            if ($isStudent) {
                $stmt = $pdo->prepare("SELECT `active_session_token`, `status` FROM `students` WHERE LOWER(`id`) = LOWER(:u) LIMIT 1");
                $stmt->execute(['u' => $username]);
                $dbRecord = $stmt->fetch();
            } else {
                $stmt = $pdo->prepare("SELECT `active_session_token`, `status` FROM `station_users` WHERE LOWER(`username`) = LOWER(:u) LIMIT 1");
                $stmt->execute(['u' => $username]);
                $dbRecord = $stmt->fetch();
            }

            if ($dbRecord && !empty($dbRecord['active_session_token']) && $dbRecord['active_session_token'] !== $sessionToken) {
                // Superseded by another login!
                $_SESSION = [];
                session_unset();
                session_destroy();
                sendResponse(false, null, 'Your session has expired because your account was logged in from another browser or device.', 401);
            }
        } catch (Exception $e) {
            // Non-blocking if DB query fails temporarily
            error_log('[SessionGuard::SingleSessionCheck] Failed: ' . $e->getMessage());
        }
    }

    // Release session lock so parallel API requests do not block
    session_write_close();

    // ── 3. Role Permission Verification ──
    if (!empty($allowedRoles)) {
        $normalizedAllowed = array_map('strtoupper', $allowedRoles);
        $userRole = strtoupper($user['role'] ?? ($isStudent ? 'STUDENT' : ''));
        if (!in_array($userRole, $normalizedAllowed, true)) {
            sendResponse(false, null, "Forbidden: insufficient role permissions (Active role: '{$userRole}').", 403);
        }
    }

    return $user;
}

