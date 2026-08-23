<?php
/**
 * Auth Controller — Handles user login, logout, forced password changes, and session check
 */
require_once __DIR__ . '/../models/UserModel.php';

class AuthController {
    private $userModel;

    public function __construct($pdo) {
        $this->userModel = new UserModel($pdo);
    }

    public function login($payload) {
        $username = trim($payload['username'] ?? '');
        $password = trim($payload['password'] ?? '');

        if (!$username || !$password) {
            return ['success' => false, 'message' => 'Username and password are required.', 'code' => 400];
        }

        $user = $this->userModel->findByUsername($username);
        if (!$user) {
            return ['success' => false, 'message' => 'Invalid username or password.', 'code' => 401];
        }

        // Password verification — bcrypt
        $isValidPassword = password_verify($password, $user['password']);

        // One-time legacy migration for unhashed passwords
        if (!$isValidPassword && !empty($user['password']) && substr($user['password'], 0, 4) !== '$2y$' && $password === $user['password']) {
            $isValidPassword = true;
            try {
                $this->userModel->changePassword($user['username'], $password);
            } catch (Exception $e) {}
        }

        if (!$isValidPassword) {
            return ['success' => false, 'message' => 'Invalid username or password.', 'code' => 401];
        }

        $userStatus = strtoupper(trim($user['status'] ?? 'ACTIVE')) ?: 'ACTIVE';
        if ($userStatus !== 'ACTIVE') {
            return ['success' => false, 'message' => 'Your account is pending activation. Please contact the Admin.', 'code' => 403];
        }

        // ── Single-Active Session Token Generation ──
        $activeSessionToken = bin2hex(random_bytes(32));
        $clientIp = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $clientIp = trim(explode(',', $clientIp)[0]);

        try {
            $pdo = Database::getInstance();
            $tokenStmt = $pdo->prepare("UPDATE `station_users` SET `active_session_token` = :token, `last_login_at` = NOW(), `last_login_ip` = :ip WHERE `id` = :id");
            $tokenStmt->execute(['token' => $activeSessionToken, 'ip' => $clientIp, 'id' => $user['id']]);
        } catch (Exception $e) {
            error_log('[AuthController::SessionTokenUpdate] Failed: ' . $e->getMessage());
        }

        require_once __DIR__ . '/../../shared/backend/utils/session_guard.php';
        initSession();
        session_regenerate_id(true);

        $_SESSION = [];
        $_SESSION['last_activity'] = time();

        $mustChangePassword = (bool)($user['must_change_password'] ?? false);
        $role = $user['role'];

        // Determine redirect URL based on role (mirrors login.php)
        $redirectUrlMap = [
            'SUPER_ADMIN' => 'admin/index.html',
            'ADMIN'       => 'admin/index.html',
            'REGISTRAR'   => 'registrar/index.html',
            'HELPDESK'    => 'stations/tlc-helpdesk/index.html',
            'MEDICAL'     => 'stations/medical-checkup/index.html',
            'CASHIER'     => 'stations/payment-processing/index.html',
            'IT_CENTER'   => 'stations/it-center/index.html',
        ];
        $redirectUrl = $redirectUrlMap[$role] ?? '';

        // Set session keys matching what all station frontends expect
        $userAvatar = $user['avatar'] ?? $user['photo'] ?? null;
        $sessionPayload = [
            'username'             => $user['username'],
            'name'                 => $user['name'],
            'email'                => $user['email'] ?? '',
            'role'                 => $role,
            'avatar'               => $userAvatar,
            'session_token'        => $activeSessionToken,
            'must_change_password' => $mustChangePassword
        ];
        if ($role === 'SUPER_ADMIN' || $role === 'ADMIN') {
            $_SESSION['gncp_admin_user'] = $sessionPayload;
        } else {
            $_SESSION['gncp_station_user'] = $sessionPayload;
        }
        session_write_close();

        return [
            'success' => true,
            'data' => [
                'username'             => $user['username'],
                'name'                 => $user['name'],
                'email'                => $user['email'] ?? '',
                'role'                 => $role,
                'avatar'               => $userAvatar,
                'must_change_password' => $mustChangePassword,
                'redirectUrl'          => $redirectUrl
            ],
            'message' => $mustChangePassword ? 'Password change required.' : 'Login successful.'
        ];

    }

    public function changePassword($payload) {
        $username = trim($payload['username'] ?? '');
        $currentPassword = trim($payload['current_password'] ?? '');
        $newPassword = trim($payload['new_password'] ?? '');

        if (!$username || !$currentPassword || !$newPassword) {
            return ['success' => false, 'message' => 'Username, current password, and new password are required.', 'code' => 400];
        }

        if (strlen($newPassword) < 6) {
            return ['success' => false, 'message' => 'New password must be at least 6 characters.', 'code' => 400];
        }

        $user = $this->userModel->findByUsername($username);
        if (!$user) {
            return ['success' => false, 'message' => 'User account not found.', 'code' => 404];
        }

        $isValidPassword = password_verify($currentPassword, $user['password']);
        if (!$isValidPassword) {
            return ['success' => false, 'message' => 'Current password is incorrect.', 'code' => 401];
        }
        $success = $this->userModel->changePassword($username, $newPassword);
        if ($success) {
            require_once __DIR__ . '/../../shared/backend/utils/session_guard.php';
            initSession();
            $sessionKeys = ['gncp_admin_user', 'gncp_station_user'];
            foreach ($sessionKeys as $key) {
                if (isset($_SESSION[$key])) {
                    $sessionUser = is_string($_SESSION[$key]) ? json_decode($_SESSION[$key], true) : $_SESSION[$key];
                    if (is_array($sessionUser) && ($sessionUser['username'] ?? '') === $username) {
                        $sessionUser['must_change_password'] = false;
                        $_SESSION[$key] = is_string($_SESSION[$key]) ? json_encode($sessionUser) : $sessionUser;
                        break;
                    }
                }
            }

            return ['success' => true, 'message' => 'Password updated successfully. You can now use your new password.'];
        }

        return ['success' => false, 'message' => 'Failed to update password. Please try again.', 'code' => 500];
    }

    public function logout() {
        require_once __DIR__ . '/../../shared/backend/utils/session_guard.php';
        initSession();
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
        session_unset();
        session_destroy();
        return ['success' => true, 'message' => 'Logged out successfully.'];
    }

    public function checkSession() {
        require_once __DIR__ . '/../../shared/backend/utils/session_guard.php';
        $user = requireAuth();
        return [
            'success' => true,
            'data' => [
                'username'             => $user['username'] ?? '',
                'name'                 => $user['name'] ?? '',
                'email'                => $user['email'] ?? '',
                'role'                 => $user['role'] ?? '',
                'avatar'               => $user['avatar'] ?? null,
                'must_change_password' => (bool)($user['must_change_password'] ?? false)
        ]
    ];
}

public function getProfile() {
        require_once __DIR__ . '/../../shared/backend/utils/session_guard.php';
        $sessionUser = requireAuth();
        $targetUser = !empty($_GET['username']) ? trim($_GET['username']) : ($sessionUser['username'] ?? ($sessionUser['id'] ?? ''));
        
        $role = strtoupper($sessionUser['role'] ?? '');
        $currentUsername = $sessionUser['username'] ?? ($sessionUser['id'] ?? '');
        if ($targetUser !== $currentUsername && !in_array($role, ['ADMIN', 'SUPER_ADMIN'], true)) {
            return ['success' => false, 'message' => 'Unauthorized access to user profile.', 'code' => 403];
        }

        $profile = $this->userModel->getProfile($targetUser);
        if (!$profile) {
            return ['success' => false, 'message' => 'User profile not found.', 'code' => 404];
        }

        return ['success' => true, 'data' => $profile];
    }

    public function updateProfile($payload) {
        require_once __DIR__ . '/../../shared/backend/utils/session_guard.php';
        $sessionUser = requireAuth();
        $currentUsername = $sessionUser['username'] ?? ($sessionUser['id'] ?? '');
        $role = strtoupper($sessionUser['role'] ?? '');

        $targetUsername = trim($payload['username'] ?? $currentUsername);
        if ($targetUsername !== $currentUsername && !in_array($role, ['ADMIN', 'SUPER_ADMIN'], true)) {
            return ['success' => false, 'message' => 'You are not authorized to update another user\'s profile.', 'code' => 403];
        }

        $name   = trim($payload['name'] ?? '');
        $email  = trim($payload['email'] ?? '');
        $avatar = $payload['avatar'] ?? ($payload['photo'] ?? null);

        if (!$name || !$email) {
            return ['success' => false, 'message' => 'Name and email are required fields.', 'code' => 400];
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'message' => 'Invalid email address format.', 'code' => 400];
        }

        $success = $this->userModel->updateProfile($targetUsername, $name, $email, $avatar);
        if ($success) {
            initSession();
            $sessionKeys = ['gncp_admin_user', 'gncp_station_user', 'gncp_student'];
            foreach ($sessionKeys as $key) {
                if (isset($_SESSION[$key])) {
                    $sUser = is_string($_SESSION[$key]) ? json_decode($_SESSION[$key], true) : $_SESSION[$key];
                    if (is_array($sUser) && (($sUser['username'] ?? '') === $targetUsername || ($sUser['id'] ?? '') === $targetUsername)) {
                        $sUser['name'] = $name;
                        $sUser['email'] = $email;
                        if ($avatar !== null) {
                            $sUser['avatar'] = $avatar;
                            $sUser['photo'] = $avatar;
                        }
                        $_SESSION[$key] = is_string($_SESSION[$key]) ? json_encode($sUser) : $sUser;
                        break;
                    }
                }
            }
            session_write_close();
            return ['success' => true, 'message' => 'Profile details updated successfully.'];
        }

        return ['success' => false, 'message' => 'Failed to update profile.', 'code' => 500];
    }

    public function uploadAvatar($payload) {
        require_once __DIR__ . '/../../shared/backend/utils/session_guard.php';
        $sessionUser = requireAuth();
        $currentUsername = $sessionUser['username'] ?? ($sessionUser['id'] ?? '');
        $role = strtoupper($sessionUser['role'] ?? '');

        $targetUsername = trim($payload['username'] ?? $currentUsername);
        if ($targetUsername !== $currentUsername && !in_array($role, ['ADMIN', 'SUPER_ADMIN'], true)) {
            return ['success' => false, 'message' => 'You are not authorized to upload an avatar for another user.', 'code' => 403];
        }

        $base64Data = $payload['photoData'] ?? ($payload['avatarData'] ?? null);

        if (!$targetUsername || !$base64Data) {
            return ['success' => false, 'message' => 'Missing username or photo payload.', 'code' => 400];
        }

        // Clean base64 header
        if (strpos($base64Data, ',') !== false) {
            @list(, $base64Data) = explode(',', $base64Data);
        }
        $decoded = base64_decode($base64Data);
        if (!$decoded) {
            return ['success' => false, 'message' => 'Invalid image payload.', 'code' => 400];
        }

        // Validate image magic bytes to prevent non-image polyglot uploads
        $imgInfo = @getimagesizefromstring($decoded);
        if (!$imgInfo || !in_array($imgInfo[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP, IMAGETYPE_GIF], true)) {
            return ['success' => false, 'message' => 'Uploaded file is not a valid JPEG, PNG, WEBP, or GIF image.', 'code' => 400];
        }

        $filename = 'avatar_' . preg_replace('/[^a-zA-Z0-9_-]/', '', $targetUsername) . '_' . time() . '.jpg';

        // Target storage directories
        $dir1 = __DIR__ . '/../../shared/assets/uploads';
        $dir2 = __DIR__ . '/../../stations/it-center/assets/uploads';
        $dir3 = __DIR__ . '/../../uploads/avatars';

        if (!is_dir($dir1)) @mkdir($dir1, 0777, true);
        if (!is_dir($dir2)) @mkdir($dir2, 0777, true);
        if (!is_dir($dir3)) @mkdir($dir3, 0777, true);

        @file_put_contents($dir1 . '/' . $filename, $decoded);
        @file_put_contents($dir2 . '/' . $filename, $decoded);
        @file_put_contents($dir3 . '/' . $filename, $decoded);

        // Update database for station_users, students, and pre_enrollments
        $profile = $this->userModel->getProfile($targetUsername);
        if ($profile) {
            $name = $profile['name'] ?? $targetUsername;
            $email = $profile['email'] ?? ($targetUsername . '@gncp.edu.ph');
            $this->userModel->updateProfile($targetUsername, $name, $email, $filename);
        }

        return [
            'success' => true,
            'data' => [
                'avatar'   => $filename,
                'photo'    => $filename,
                'user'     => $targetUsername
            ],
            'message' => 'Profile picture updated and saved successfully.'
        ];
    }
}
