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

    public function getLearningMaterials(array $params = []): array {
        $caller = requireAuth(['STUDENT', 'ADMIN', 'SUPER_ADMIN']);
        $studentId = $params['studentId'] ?? ($params['id'] ?? ($caller['identity'] ?? ''));
        return StudentPortalService::getLearningMaterials($this->pdo, $studentId);
    }

    public function downloadMaterial(array $params = []) {
        $caller = requireAuth(['STUDENT', 'ADMIN', 'SUPER_ADMIN']);
        $studentId = $caller['identity'] ?? '';
        $fileId = (int)($params['fileId'] ?? ($params['id'] ?? 0));

        if ($fileId <= 0) {
            http_response_code(400);
            die("Invalid file ID requested.");
        }

        // Verify that this file belongs to a material published to this student's section
        $secCode = StudentPortalService::getStudentAssignedSection($this->pdo, $studentId);
        $role = strtoupper($caller['role'] ?? '');

        if ($role === 'STUDENT' && !empty($secCode)) {
            $chkStmt = $this->pdo->prepare("
                SELECT COUNT(*) FROM `lms_materials` m
                JOIN `lms_material_sections` ms ON m.id = ms.material_id
                WHERE m.file_id = :fid AND (ms.section_code = :sec1 OR :sec2 LIKE CONCAT('%', ms.section_code, '%'))
            ");
            $chkStmt->execute(['fid' => $fileId, 'sec1' => $secCode, 'sec2' => $secCode]);
            $count = (int)$chkStmt->fetchColumn();
            if ($count === 0) {
                http_response_code(403);
                die("Unauthorized: This learning material is not accessible to your assigned section ($secCode).");
            }
        }

        $fStmt = $this->pdo->prepare("SELECT * FROM `professor_files` WHERE id = :id LIMIT 1");
        $fStmt->execute(['id' => $fileId]);
        $file = $fStmt->fetch(PDO::FETCH_ASSOC);

        if (!$file) {
            http_response_code(404);
            die("File record not found.");
        }

        $fullPath = __DIR__ . '/../../../uploads/professor_drive/' . $file['stored_filename'];
        if (!file_exists($fullPath)) {
            http_response_code(404);
            die("Physical file missing from storage.");
        }

        while (ob_get_level()) { ob_end_clean(); }

        header('Content-Description: File Transfer');
        header('Content-Type: ' . $file['mime_type']);
        header('Content-Disposition: attachment; filename="' . addslashes($file['filename']) . '"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($fullPath));
        readfile($fullPath);
        exit;
    }

    public function getAnnouncements(array $params = []): array {
        $caller = requireAuth(['STUDENT', 'ADMIN', 'SUPER_ADMIN']);
        $studentId = $params['studentId'] ?? ($params['id'] ?? ($caller['identity'] ?? ''));
        return StudentPortalService::getStudentAnnouncements($this->pdo, $studentId);
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
