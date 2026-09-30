<?php
/**
 * User Admin Controller — Handles station user operator provisioning, user listing, and test user cleanup
 */
require_once __DIR__ . '/../models/UserModel.php';
require_once __DIR__ . '/../../shared/backend/services/EmailService.php';
require_once __DIR__ . '/../../shared/backend/utils/session_guard.php';

class UserAdminController {
    private $userModel;
    private $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
        $this->userModel = new UserModel($pdo);
    }

    public function getUsers(): array {
        requireAuth(['ADMIN', 'SUPER_ADMIN']);
        return ['success' => true, 'data' => $this->userModel->getAllUsers()];
    }

    public function getProfessors(): array {
        requireAuth(['ADMIN', 'SUPER_ADMIN', 'SCHEDULER', 'REGISTRAR']);
        $stmt = $this->pdo->query("
            SELECT p.*, d.code as department_code, d.name as department_name, u.username, u.avatar,
                   (SELECT COUNT(*) FROM `subject_sections` ss WHERE ss.professor_id = p.id) as assigned_classes_count
            FROM `professors` p
            JOIN `departments` d ON p.department_id = d.id
            JOIN `station_users` u ON p.user_id = u.id
            ORDER BY p.name ASC
        ");
        return ['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)];
    }

    public function getDepartments(): array {
        requireAuth(['ADMIN', 'SUPER_ADMIN', 'SCHEDULER', 'REGISTRAR']);
        $stmt = $this->pdo->query("SELECT * FROM `departments` ORDER BY name ASC");
        return ['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)];
    }

    public function saveUser(array $payload): array {
        requireAuth(['ADMIN', 'SUPER_ADMIN']);
        $userData = $payload['user'] ?? $payload;
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

        if ($userData['role'] === 'TEACHER') {
            $userData['role'] = 'PROFESSOR';
        }

        $allowedRoles = ['REGISTRAR', 'HELPDESK', 'MEDICAL', 'CASHIER', 'IT_CENTER', 'ADMIN', 'PROFESSOR', 'SCHEDULER'];
        if (!in_array($userData['role'], $allowedRoles, true)) {
            return ['success' => false, 'message' => 'Invalid station role selected.', 'code' => 400];
        }

        // Auto-generate temp password if not explicitly supplied
        $rawPassword = !empty($userData['password']) ? $userData['password'] : 'Gncp#' . random_int(1000, 9999) . '!';
        $userData['password'] = $rawPassword;
        $userData['must_change_password'] = 1;

        try {
            $userId = $this->userModel->createUser($userData);

            // If creating a PROFESSOR, ensure child record in professors table
            if ($userData['role'] === 'PROFESSOR') {
                $deptId = (int)($userData['department_id'] ?? 1);
                if ($deptId <= 0) {
                    $deptCode = trim($userData['department'] ?? 'CCS');
                    $dStmt = $this->pdo->prepare("SELECT id FROM `departments` WHERE code = :c OR name = :n LIMIT 1");
                    $dStmt->execute(['c' => $deptCode, 'n' => $deptCode]);
                    $deptId = (int)($dStmt->fetchColumn() ?: 1);
                }

                $empId = trim($userData['employee_id'] ?? ('FAC-' . date('Y') . '-' . str_pad((string)$userId, 3, '0', STR_PAD_LEFT)));
                $pStmt = $this->pdo->prepare("
                    INSERT INTO `professors` (`user_id`, `employee_id`, `name`, `department_id`, `email`, `status`)
                    VALUES (:uid, :empid, :name, :deptid, :email, :st)
                    ON DUPLICATE KEY UPDATE `name`=VALUES(`name`), `department_id`=VALUES(`department_id`), `email`=VALUES(`email`), `status`=VALUES(`status`)
                ");
                $pStmt->execute([
                    'uid'    => $userId,
                    'empid'  => $empId,
                    'name'   => $userData['name'],
                    'deptid' => $deptId,
                    'email'  => $userData['email'] ?: null,
                    'st'     => $userData['status']
                ]);
            }
            
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

    public function updateUserStatus(array $payload): array {
        requireAuth(['ADMIN', 'SUPER_ADMIN']);
        $id = $payload['id'] ?? null;
        $status = strtoupper(trim($payload['status'] ?? 'ACTIVE'));
        if (!$id) {
            return ['success' => false, 'message' => 'User ID is required.', 'code' => 400];
        }
        $this->userModel->updateUserStatus((int)$id, $status);
        $pStmt = $this->pdo->prepare("UPDATE `professors` SET `status` = :st WHERE `user_id` = :uid OR `id` = :pid");
        $pStmt->execute(['st' => $status, 'uid' => (int)$id, 'pid' => (int)$id]);
        return ['success' => true, 'message' => "User status updated to {$status}."];
    }

    public function resetOperatorPassword(array $payload): array {
        $user = requireAuth(['ADMIN', 'SUPER_ADMIN']);
        $userId = $payload['userId'] ?? null;
        $customPassword = trim($payload['newPassword'] ?? '');
        $overrideEmail = trim($payload['email'] ?? '');

        if (!$userId) {
            return ['success' => false, 'message' => 'User ID is required.', 'code' => 400];
        }

        $targetUser = $this->userModel->findById((int)$userId);
        if (!$targetUser) {
            return ['success' => false, 'message' => 'User account not found.', 'code' => 404];
        }

        // Prevent standard ADMIN from resetting SUPER_ADMIN
        $targetRole = strtoupper($targetUser['role'] ?? '');
        if ($targetRole === 'SUPER_ADMIN' && ($user['role'] ?? '') !== 'SUPER_ADMIN') {
            return ['success' => false, 'message' => 'Only a Super Admin can reset a Super Admin password.', 'code' => 403];
        }

        if (!empty($overrideEmail) && !filter_var($overrideEmail, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'message' => 'Invalid email address provided for operator notification.', 'code' => 400];
        }

        $tempPassword = !empty($customPassword) ? $customPassword : 'Gncp#' . random_int(1000, 9999) . '!';
        $hashed = password_hash($tempPassword, PASSWORD_DEFAULT);

        // Update target email if overridden, otherwise use existing
        $destinationEmail = !empty($overrideEmail) ? $overrideEmail : ($targetUser['email'] ?? '');
        $this->userModel->setOperatorPassword((int)$userId, $hashed, 1, !empty($overrideEmail) ? $overrideEmail : null);

        // Dispatch password reset email via EmailService
        $mailResult = ['success' => false, 'message' => 'No email address registered for this operator.'];
        if (!empty($destinationEmail)) {
            $mailResult = EmailService::sendOperatorPasswordReset(
                $destinationEmail,
                $targetUser['name'],
                $targetUser['username'],
                $tempPassword,
                $targetUser['role']
            );
        }

        return [
            'success' => true,
            'data' => [
                'userId'         => (int)$userId,
                'username'       => $targetUser['username'],
                'tempPassword'   => $tempPassword,
                'recipientEmail' => $destinationEmail,
                'emailSent'      => $mailResult['success'],
                'emailMessage'   => $mailResult['message'] ?? ''
            ],
            'message' => 'Operator password reset successfully.'
        ];
    }

    public function cleanupTestUsers(array $payload): array {
        requireAuth(['ADMIN', 'SUPER_ADMIN']);
        $pattern = $payload['pattern'] ?? 'test_%_auto_%';
        $deleted = $this->userModel->deleteTestUsers($pattern);
        return ['success' => true, 'data' => ['deleted' => $deleted], 'message' => "Purged $deleted test user account(s)."];
    }
}

