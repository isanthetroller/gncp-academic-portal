<?php
/**
 * User Admin Controller — Handles station user operator provisioning, user listing, and test user cleanup
 */
require_once __DIR__ . '/../models/UserModel.php';
require_once __DIR__ . '/../../shared/backend/services/EmailService.php';
require_once __DIR__ . '/../../shared/backend/utils/session_guard.php';

class UserAdminController {
    private $userModel;

    public function __construct(PDO $pdo) {
        $this->userModel = new UserModel($pdo);
    }

    public function getUsers(): array {
        requireAuth(['ADMIN', 'SUPER_ADMIN']);
        return ['success' => true, 'data' => $this->userModel->getAllUsers()];
    }

    public function saveUser(array $payload): array {
        requireAuth(['ADMIN', 'SUPER_ADMIN']);
        $userData = $payload['user'] ?? [];
        $userData['username'] = strtolower(trim($userData['username'] ?? ''));
        $userData['name'] = trim($userData['name'] ?? '');
        $userData['role'] = strtoupper(trim($userData['role'] ?? ''));
        $userData['email'] = trim($userData['email'] ?? '');
        $userData['status'] = strtoupper(trim($userData['status'] ?? 'ACTIVE')) ?: 'ACTIVE';

        if (empty($userData['username']) || empty($userData['name']) || empty($userData['role'])) {
            return ['success' => false, 'message' => 'Username, name, and station role are required.', 'code' => 400];
        }

        if (!preg_match('/^[a-z0-9_.-]{3,30}$/', $userData['username'])) {
            return ['success' => false, 'message' => 'Username must be 3-30 characters long and contain only lowercase letters, numbers, hyphens, or underscores.', 'code' => 400];
        }

        if (!empty($userData['email']) && !filter_var($userData['email'], FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'message' => 'Invalid email address format provided.', 'code' => 400];
        }

        $allowedRoles = ['REGISTRAR', 'HELPDESK', 'MEDICAL', 'CASHIER', 'IT_CENTER', 'ADMIN'];
        if (!in_array($userData['role'], $allowedRoles, true)) {
            return ['success' => false, 'message' => 'Invalid station role selected.', 'code' => 400];
        }

        // Auto-generate temp password if not explicitly supplied
        $rawPassword = !empty($userData['password']) ? $userData['password'] : 'Gncp#' . rand(1000, 9999) . '!';
        $userData['password'] = $rawPassword;
        $userData['must_change_password'] = 1;

        try {
            $userId = $this->userModel->createUser($userData);
            
            // Dispatch credentials email via Gmail/SMTP EmailService
            $mailResult = ['success' => false, 'message' => 'No email provided.'];
            if (!empty($userData['email'])) {
                $mailResult = EmailService::sendUserCredentials(
                    $userData['email'],
                    $userData['name'],
                    $userData['username'],
                    $rawPassword,
                    $userData['role']
                );
            }

            return [
                'success' => true,
                'data' => [
                    'userId'               => $userId,
                    'username'             => $userData['username'],
                    'email'                => $userData['email'] ?? null,
                    'emailSent'            => $mailResult['success'],
                    'emailMessage'         => $mailResult['message'] ?? '',
                    'must_change_password' => true
                ],
                'message' => 'User account created successfully.'
            ];
        } catch (PDOException $e) {
            // Check for duplicate username key violation
            if ($e->getCode() === '23000') {
                return ['success' => false, 'message' => "An operator account with username '" . ($userData['username'] ?? '') . "' already exists. Please choose a unique username.", 'code' => 400];
            }
            if (function_exists('logAppError')) {
                logAppError("Admin SaveUser Error: " . $e->getMessage(), ['user' => $userData]);
            }
            return ['success' => false, 'message' => 'Failed to create operator account. Please verify user details and try again.', 'code' => 500];
        }
    }

    public function cleanupTestUsers(array $payload): array {
        requireAuth(['ADMIN', 'SUPER_ADMIN']);
        $pattern = $payload['pattern'] ?? 'test_%_auto_%';
        $deleted = $this->userModel->deleteTestUsers($pattern);
        return ['success' => true, 'data' => ['deleted' => $deleted], 'message' => "Purged $deleted test user account(s)."];
    }
}
