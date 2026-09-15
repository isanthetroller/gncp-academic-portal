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

        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
            || (!empty($_SERVER['HTTP_FRONT_END_HTTPS']) && strtolower($_SERVER['HTTP_FRONT_END_HTTPS']) !== 'off')
            || (!empty($_SERVER['HTTP_CF_VISITOR']) && str_contains($_SERVER['HTTP_CF_VISITOR'], '"scheme":"https"'));

        // Enforce HTTPS redirection for live production domains
        $host = $_SERVER['HTTP_HOST'] ?? '';
        $rawHost = strtolower(explode(':', $host)[0]);
        $isLocalhost = in_array($rawHost, ['localhost', '127.0.0.1', '::1'], true);

        if (!$isLocalhost && !$isHttps && (php_sapi_name() !== 'cli' || defined('GNCP_TEST_HTTPS_CLI'))) {
            $redirectUrl = 'https://' . $host . ($_SERVER['REQUEST_URI'] ?? '/');
            if (!headers_sent()) {
                header('HTTP/1.1 301 Moved Permanently');
                header('Location: ' . $redirectUrl);
                header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
                if (!defined('GNCP_TEST_HTTPS_CLI')) {
                    exit;
                }
                return;
            }
        }

        if (!headers_sent()) {
            session_set_cookie_params([
                'lifetime' => 86400,
                'path'     => '/',
                'domain'   => '',
                'secure'   => $isHttps,
                'httponly' => true,
                'samesite' => 'Lax'
            ]);
        }
        @session_start();
    }
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
}

function getCsrfToken(): string {
    initSession();
    return $_SESSION['csrf_token'] ?? '';
}

function verifyCsrfOrigin(): bool {
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if (!in_array($method, ['POST', 'PUT', 'DELETE', 'PATCH'], true)) {
        return true;
    }

    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if (empty($origin) && !empty($_SERVER['HTTP_REFERER'])) {
        $origin = $_SERVER['HTTP_REFERER'];
    }

    $headerCsrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? '');
    $sessionCsrf = $_SESSION['csrf_token'] ?? '';

    if (!empty($origin)) {
        $parsed = parse_url($origin);
        $originHost = strtolower($parsed['host'] ?? '');
        $currentHost = strtolower(explode(':', $_SERVER['HTTP_HOST'] ?? 'localhost')[0]);

        $isAllowed = ($originHost === 'localhost' 
            || $originHost === '127.0.0.1' 
            || $originHost === $currentHost 
            || ($currentHost !== '' && str_ends_with($originHost, '.' . $currentHost))
            || str_ends_with($originHost, '.infinityfree.com')
            || str_ends_with($originHost, '.site.je'));

        if (!$isAllowed) {
            error_log("[CSRF] Blocked cross-origin mutation attempt from: {$originHost} targeting: {$currentHost}");
            return false;
        }
    } else {
        // If Origin and Referer are both omitted, require valid CSRF token on web requests
        $isPureCli = (php_sapi_name() === 'cli' || PHP_SAPI === 'cli') && !isset($_SERVER['REQUEST_METHOD']);
        if (!$isPureCli) {
            if (empty($headerCsrf) || empty($sessionCsrf) || !hash_equals($sessionCsrf, $headerCsrf)) {
                error_log("[CSRF] Blocked mutation with missing Origin/Referer and missing/invalid CSRF token.");
                return false;
            }
        }
    }

    // If explicit CSRF token is provided, verify against session
    if (!empty($headerCsrf)) {
        if (empty($sessionCsrf) || !hash_equals($sessionCsrf, $headerCsrf)) {
            error_log("[CSRF] Invalid CSRF token received.");
            return false;
        }
    }

    return true;
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

    // ── Enforce CSRF Origin Validation for Mutations ──
    if (!verifyCsrfOrigin()) {
        session_write_close();
        sendResponse(false, null, 'Forbidden: Cross-site request or origin validation failed.', 403);
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

