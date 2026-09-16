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

function destroySessionCompletely() {
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION = [];
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params["path"] ?? '/',
                $params["domain"] ?? '',
                $params["secure"] ?? false,
                $params["httponly"] ?? true
            );
        }
        @session_unset();
        @session_destroy();
    }
}

function clearUserActiveSessionToken(string $identity, bool $isStudent = false) {
    if (empty($identity)) return;
    try {
        $pdo = Database::getInstance();
        if ($isStudent) {
            $stmt = $pdo->prepare("UPDATE `students` SET `active_session_token` = NULL WHERE LOWER(`id`) = LOWER(:u)");
        } else {
            $stmt = $pdo->prepare("UPDATE `station_users` SET `active_session_token` = NULL WHERE LOWER(`username`) = LOWER(:u)");
        }
        $stmt->execute(['u' => $identity]);
    } catch (Exception $e) {
        error_log('[SessionGuard::clearUserActiveSessionToken] ' . $e->getMessage());
    }
}

function validateSession(array $allowedRoles = []): array {
    initSession();

    $adminSession   = $_SESSION['gncp_admin_user'] ?? null;
    $stationSession = $_SESSION['gncp_station_user'] ?? null;
    $studentSession = $_SESSION['gncp_student'] ?? null;

    $userVal = $adminSession ?: ($stationSession ?: $studentSession);

    if (!$userVal) {
        return [
            'valid'      => false,
            'reason'     => 'no_session',
            'message'    => 'Authentication required. Please sign in.',
            'code'       => 401,
            'user'       => null,
            'is_student' => false
        ];
    }

    $user = is_array($userVal) ? $userVal : json_decode($userVal, true);

    if (!$user || (empty($user['role']) && empty($user['id']))) {
        return [
            'valid'      => false,
            'reason'     => 'invalid_state',
            'message'    => 'Invalid session state.',
            'code'       => 401,
            'user'       => null,
            'is_student' => false
        ];
    }

    $isStudent = (!empty($user['role']) && strtoupper($user['role']) === 'STUDENT') || (isset($user['id']) && !isset($user['role']));

    // 1. Idle Inactivity Timeout (2 Hours = 7200s)
    $maxIdleSec = 7200;
    $now = time();
    if (isset($_SESSION['last_activity']) && ($now - $_SESSION['last_activity']) > $maxIdleSec) {
        destroySessionCompletely();
        return [
            'valid'      => false,
            'reason'     => 'expired',
            'message'    => 'Your session has expired due to inactivity. Please sign in again.',
            'code'       => 401,
            'user'       => null,
            'is_student' => $isStudent
        ];
    }

    // 2. Strict Single-Active Session Verification against MariaDB
    $sessionToken = $user['session_token'] ?? null;
    $identity     = $user['username'] ?? ($user['id'] ?? null);

    if (empty($sessionToken) || empty($identity)) {
        destroySessionCompletely();
        return [
            'valid'      => false,
            'reason'     => 'superseded',
            'message'    => 'Your session has been invalidated because this account was signed in from another device or browser.',
            'code'       => 401,
            'user'       => null,
            'is_student' => $isStudent
        ];
    }

    try {
        $pdo = Database::getInstance();
        if ($isStudent) {
            $stmt = $pdo->prepare("SELECT `active_session_token`, `status` FROM `students` WHERE LOWER(`id`) = LOWER(:u) LIMIT 1");
        } else {
            $stmt = $pdo->prepare("SELECT `active_session_token`, `status` FROM `station_users` WHERE LOWER(`username`) = LOWER(:u) LIMIT 1");
        }
        $stmt->execute(['u' => $identity]);
        $dbRecord = $stmt->fetch();

        if (!$dbRecord) {
            destroySessionCompletely();
            return [
                'valid'      => false,
                'reason'     => 'no_user',
                'message'    => 'User record not found. Please sign in again.',
                'code'       => 401,
                'user'       => null,
                'is_student' => $isStudent
            ];
        }

        $accountStatus = strtoupper(trim($dbRecord['status'] ?? 'ACTIVE')) ?: 'ACTIVE';
        if ($accountStatus !== 'ACTIVE') {
            destroySessionCompletely();
            return [
                'valid'      => false,
                'reason'     => 'disabled',
                'message'    => 'Your account is inactive or disabled. Please contact the administrator.',
                'code'       => 403,
                'user'       => null,
                'is_student' => $isStudent
            ];
        }

        $activeDbToken = $dbRecord['active_session_token'] ?? null;
        if (empty($activeDbToken) || !hash_equals($activeDbToken, $sessionToken)) {
            // Superseded by another login!
            destroySessionCompletely();
            return [
                'valid'      => false,
                'reason'     => 'superseded',
                'message'    => 'Your session has been invalidated because this account was signed in from another device or browser.',
                'code'       => 401,
                'user'       => null,
                'is_student' => $isStudent
            ];
        }
    } catch (Exception $e) {
        error_log('[SessionGuard::validateSession] Exception: ' . $e->getMessage());
    }

    // 3. Role Authorization
    if (!empty($allowedRoles)) {
        $normalizedAllowed = array_map('strtoupper', $allowedRoles);
        $userRole = strtoupper($user['role'] ?? ($isStudent ? 'STUDENT' : ''));
        if (!in_array($userRole, $normalizedAllowed, true)) {
            return [
                'valid'      => false,
                'reason'     => 'forbidden',
                'message'    => "Forbidden: insufficient role permissions (Active role: '{$userRole}').",
                'code'       => 403,
                'user'       => $user,
                'is_student' => $isStudent
            ];
        }
    }

    $_SESSION['last_activity'] = $now;
    $user['identity'] = $identity;

    return [
        'valid'         => true,
        'authenticated' => true,
        'role'          => strtoupper($user['role'] ?? ($isStudent ? 'STUDENT' : '')),
        'identity'      => $identity,
        'reason'        => 'ok',
        'message'       => 'Authenticated',
        'code'          => 200,
        'user'          => $user,
        'is_student'    => $isStudent
    ];
}

function requireAuth(array $allowedRoles = []): array {
    $validation = validateSession($allowedRoles);

    if (!$validation['valid']) {
        session_write_close();
        if ($validation['reason'] === 'superseded') {
            sendResponse(false, ['session_invalidated' => true, 'reason' => 'superseded'], $validation['message'], 401);
        }
        sendResponse(false, null, $validation['message'], $validation['code']);
    }

    // Enforce CSRF Origin Validation for Mutations
    if (!verifyCsrfOrigin()) {
        session_write_close();
        sendResponse(false, null, 'Forbidden: Cross-site request or origin validation failed.', 403);
    }

    session_write_close();
    return $validation['user'];
}

function requirePageAuth(array $allowedRoles = [], string $loginRedirect = ''): array {
    $validation = validateSession($allowedRoles);

    if (!$validation['valid']) {
        session_write_close();
        
        $base = $loginRedirect;
        if (empty($base)) {
            $base = $validation['is_student'] ? '../student-portal/login' : '../';
        }

        $currentUri = $_SERVER['REQUEST_URI'] ?? '';
        $sep = (strpos($base, '?') !== false) ? '&' : '?';

        if ($validation['reason'] === 'superseded') {
            $target = $base . $sep . 'clear=true&session_invalidated=1&reason=superseded&redirect=' . urlencode($currentUri);
        } elseif ($validation['reason'] === 'expired') {
            $target = $base . $sep . 'clear=true&session_expired=1&reason=expired&redirect=' . urlencode($currentUri);
        } elseif ($validation['reason'] === 'disabled') {
            $target = $base . $sep . 'clear=true&account_disabled=1';
        } elseif ($validation['reason'] === 'forbidden') {
            $target = $base . $sep . 'clear=true&forbidden=1';
        } else {
            $target = $base . $sep . 'clear=true&auth_required=1&redirect=' . urlencode($currentUri);
        }

        if (!headers_sent()) {
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');
            header('Location: ' . $target);
            exit;
        }
        echo '<script>window.location.replace(' . json_encode($target) . ');</script>';
        exit;
    }

    if (!headers_sent()) {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
    }

    session_write_close();
    return $validation['user'];
}


