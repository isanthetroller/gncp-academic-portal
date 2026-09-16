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

        $caller = requireAuth(['STUDENT', 'ADMIN', 'SUPER_ADMIN', 'REGISTRAR', 'HELPDESK', 'IT_CENTER']);
        if (strtoupper($caller['role'] ?? '') === 'STUDENT') {
            if (strcasecmp($caller['identity'] ?? '', $studentId) !== 0) {
                return ['success' => false, 'message' => 'Unauthorized access to student dashboard.', 'code' => 403];
            }
        }

        return StudentPortalService::getStudentDashboard($this->pdo, $studentId);
    }

    public function updateProfile(array $data = []): array {
        $studentId = $data['studentId'] ?? ($data['id'] ?? '');
        if (!$studentId) {
            return ['success' => false, 'message' => 'Student ID is required.', 'code' => 400];
        }

        $caller = requireAuth(['STUDENT', 'ADMIN', 'SUPER_ADMIN']);
        if (strtoupper($caller['role'] ?? '') === 'STUDENT') {
            if (strcasecmp($caller['identity'] ?? '', $studentId) !== 0) {
                return ['success' => false, 'message' => 'Unauthorized access to update profile.', 'code' => 403];
            }
        }

        $res = StudentPortalService::updateProfile($this->pdo, $studentId, $data);
        if (!empty($res['success']) && !empty($res['data']['photo'])) {
            initSession();
            if (isset($_SESSION['gncp_student']) && is_array($_SESSION['gncp_student'])) {
                $_SESSION['gncp_student']['photo'] = $res['data']['photo'];
            }
            session_write_close();
        }

        return $res;
    }

    public function changePassword(array $data = []): array {
        $studentId       = $data['studentId'] ?? ($data['student_id'] ?? ($data['id'] ?? ''));
        $currentPassword = $data['currentPassword'] ?? ($data['current_password'] ?? '');
        $newPassword     = $data['newPassword'] ?? ($data['new_password'] ?? '');

        if (!$studentId) {
            return ['success' => false, 'message' => 'Student ID is required.', 'code' => 400];
        }

        $caller = requireAuth(['STUDENT', 'ADMIN', 'SUPER_ADMIN']);
        if (strtoupper($caller['role'] ?? '') === 'STUDENT') {
            if (strcasecmp($caller['identity'] ?? '', $studentId) !== 0) {
                return ['success' => false, 'message' => 'Unauthorized access to change password.', 'code' => 403];
            }
        }

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
        $caller = requireAuth(['STUDENT', 'ADMIN', 'SUPER_ADMIN', 'REGISTRAR', 'HELPDESK', 'IT_CENTER']);
        if (strtoupper($caller['role'] ?? '') === 'STUDENT') {
            if (strcasecmp($caller['identity'] ?? '', $studentId) !== 0) {
                return ['success' => false, 'message' => 'Unauthorized access to student documents.', 'code' => 403];
            }
        }

        $ctrl = new StudentController($this->pdo);
        return $ctrl->getDocuments($studentId);
    }

    public function uploadDocument(array $data = []): array {
        $studentId = $data['studentId'] ?? ($data['id'] ?? ($data['ref'] ?? ''));
        $caller = requireAuth(['STUDENT', 'ADMIN', 'SUPER_ADMIN']);
        if (strtoupper($caller['role'] ?? '') === 'STUDENT' && !empty($studentId)) {
            if (strcasecmp($caller['identity'] ?? '', $studentId) !== 0) {
                return ['success' => false, 'message' => 'Unauthorized access to upload document.', 'code' => 403];
            }
        }

        $ctrl = new StudentController($this->pdo);
        return $ctrl->uploadDocument($data);
    }

    public function logout(): array {
        initSession();
        $studentSess = $_SESSION['gncp_student'] ?? null;
        $sessStudentId = is_array($studentSess) ? ($studentSess['id'] ?? '') : '';
        if (!empty($sessStudentId)) {
            clearUserActiveSessionToken($sessStudentId, true);
        }
        destroySessionCompletely();
        return ['success' => true, 'data' => null, 'message' => 'Logged out successfully.'];
    }
}
