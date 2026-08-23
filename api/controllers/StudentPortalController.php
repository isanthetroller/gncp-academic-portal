<?php
/**
 * GNCP Academic Portal — Student Portal REST Controller
 * Handles request routing, authorization verification, and payload passing
 * for student self-service portal operations.
 */

require_once __DIR__ . '/../../shared/backend/utils/session_guard.php';
require_once __DIR__ . '/../../shared/backend/utils/response.php';
require_once __DIR__ . '/../../shared/backend/services/StudentPortalService.php';
require_once __DIR__ . '/../../api/models/StudentModel.php';
require_once __DIR__ . '/StudentController.php';

class StudentPortalController {
    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    public function login(array $data = []): array {
        $studentId = $data['studentId'] ?? ($data['username'] ?? '');
        $password  = $data['password'] ?? '';
        return StudentPortalService::login($this->pdo, $studentId, $password);
    }

    public function getDashboard(array $params = []): array {
        $studentId = $params['studentId'] ?? ($params['id'] ?? '');

        if (!$studentId) {
            return ['success' => false, 'message' => 'Student ID is required.', 'code' => 400];
        }

        // Authorize caller: Student must match session, or Admin/Staff
        initSession();
        $studentSess = $_SESSION['gncp_student'] ?? null;
        $adminSess   = $_SESSION['gncp_admin_user'] ?? null;
        $stationSess = $_SESSION['gncp_station_user'] ?? null;
        $sessStudentId = is_array($studentSess) ? ($studentSess['id'] ?? '') : '';

        if (!$adminSess && !$stationSess && (!$sessStudentId || strcasecmp($sessStudentId, $studentId) !== 0)) {
            return ['success' => false, 'message' => 'Unauthorized access to student dashboard. Please sign in.', 'code' => 401];
        }

        // Idle Timeout verification (7200s)
        $now = time();
        if (isset($_SESSION['last_activity']) && ($now - $_SESSION['last_activity']) > 7200) {
            $_SESSION = [];
            @session_destroy();
            return ['success' => false, 'message' => 'Your session has expired due to inactivity. Please sign in again.', 'code' => 401];
        }
        $_SESSION['last_activity'] = $now;

        // Single-Active Session Token verification
        if ($studentSess && !empty($studentSess['session_token'])) {
            $checkStmt = $this->pdo->prepare("SELECT `active_session_token` FROM `students` WHERE `id` = :id LIMIT 1");
            $checkStmt->execute(['id' => $sessStudentId]);
            $activeDbToken = $checkStmt->fetchColumn();

            if ($activeDbToken && $activeDbToken !== $studentSess['session_token']) {
                $_SESSION = [];
                @session_destroy();
                return ['success' => false, 'message' => 'Your session has expired because your account was logged in from another device.', 'code' => 401];
            }
        }
        session_write_close();

        return StudentPortalService::getStudentDashboard($this->pdo, $studentId);
    }

    public function updateProfile(array $data = []): array {
        $studentId = $data['studentId'] ?? ($data['id'] ?? '');
        if (!$studentId) {
            return ['success' => false, 'message' => 'Student ID is required.', 'code' => 400];
        }

        initSession();
        $studentSess = $_SESSION['gncp_student'] ?? null;
        $adminSess   = $_SESSION['gncp_admin_user'] ?? null;
        $stationSess = $_SESSION['gncp_station_user'] ?? null;
        $sessStudentId = is_array($studentSess) ? ($studentSess['id'] ?? '') : '';

        if (!$adminSess && !$stationSess && (!$sessStudentId || strcasecmp($sessStudentId, $studentId) !== 0)) {
            return ['success' => false, 'message' => 'Unauthorized access to update student profile.', 'code' => 401];
        }
        session_write_close();

        return StudentPortalService::updateProfile($this->pdo, $studentId, $data);
    }

    public function changePassword(array $data = []): array {
        $studentId       = $data['studentId'] ?? ($data['student_id'] ?? ($data['id'] ?? ''));
        $currentPassword = $data['currentPassword'] ?? ($data['current_password'] ?? '');
        $newPassword     = $data['newPassword'] ?? ($data['new_password'] ?? '');

        initSession();
        $studentSess = $_SESSION['gncp_student'] ?? null;
        $adminSess   = $_SESSION['gncp_admin_user'] ?? null;
        $stationSess = $_SESSION['gncp_station_user'] ?? null;
        $sessStudentId = is_array($studentSess) ? ($studentSess['id'] ?? '') : '';

        if (!$adminSess && !$stationSess && (!$sessStudentId || strcasecmp($sessStudentId, $studentId) !== 0)) {
            return ['success' => false, 'message' => 'Unauthorized access to change password.', 'code' => 401];
        }
        session_write_close();

        return StudentPortalService::changePassword($this->pdo, $studentId, $currentPassword, $newPassword);
    }

    public function requestPasswordReset(array $data = []): array {
        $identifier = $data['identifier'] ?? '';
        return StudentPortalService::requestPasswordReset($this->pdo, $identifier);
    }

    public function resetPasswordWithCode(array $data = []): array {
        $identifier  = $data['identifier'] ?? '';
        $code        = $data['code'] ?? '';
        $newPassword = $data['newPassword'] ?? ($data['new_password'] ?? '');
        return StudentPortalService::resetPasswordWithCode($this->pdo, $identifier, $code, $newPassword);
    }

    public function getDocuments(array $params = []): array {
        $studentId = $params['studentId'] ?? ($params['id'] ?? ($params['ref'] ?? ''));
        $model = new StudentModel($this->pdo);
        $data = $model->getStudentRequirements($studentId);
        if (!$data) {
            return ['success' => false, 'message' => 'Student document requirements record not found.', 'code' => 404];
        }
        return ['success' => true, 'data' => $data, 'message' => 'Document requirements loaded successfully.'];
    }

    public function uploadDocument(array $data = []): array {
        $ctrl = new StudentController($this->pdo);
        return $ctrl->uploadDocument($data);
    }

    public function logout(): array {
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
        @session_unset();
        @session_destroy();
        return ['success' => true, 'data' => null, 'message' => 'Logged out successfully.'];
    }
}
