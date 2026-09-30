<?php
/**
 * Professor API Controller
 * Provides authenticated REST endpoints for faculty profile, assigned sections/subjects,
 * weekly schedule, Personal Drive file management, and LMS post publishing.
 */

require_once __DIR__ . '/../../shared/backend/services/ProfessorService.php';
require_once __DIR__ . '/../../shared/backend/utils/session_guard.php';

class ProfessorController {
    private $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    private function getResolvedProfessor(): array {
        $user = requireAuth(['PROFESSOR', 'TEACHER', 'ADMIN', 'SUPER_ADMIN']);
        $uStmt = $this->pdo->prepare("SELECT id FROM `station_users` WHERE username = :u LIMIT 1");
        $uStmt->execute(['u' => $user['username']]);
        $uId = (int)$uStmt->fetchColumn();

        $prof = ProfessorService::getProfessorByUserId($this->pdo, $uId);
        if (!$prof) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'No faculty record linked to your account.', 'code' => 403]);
            exit;
        }

        return ['user' => $user, 'professor' => $prof, 'userId' => $uId];
    }

    public function getProfile(): array {
        $resolved = $this->getResolvedProfessor();
        return ProfessorService::getProfile($this->pdo, $resolved['userId']);
    }

    public function getSections(): array {
        $resolved = $this->getResolvedProfessor();
        return ProfessorService::getSections($this->pdo, (int)$resolved['professor']['id']);
    }

    public function getSubjects(): array {
        $resolved = $this->getResolvedProfessor();
        return ProfessorService::getSubjects($this->pdo, (int)$resolved['professor']['id']);
    }

    public function getSchedule(): array {
        $resolved = $this->getResolvedProfessor();
        return ProfessorService::getSchedule($this->pdo, (int)$resolved['professor']['id']);
    }

    public function getFiles(): array {
        $resolved = $this->getResolvedProfessor();
        return ProfessorService::getFiles($this->pdo, (int)$resolved['professor']['id']);
    }

    public function uploadFile(): array {
        $resolved = $this->getResolvedProfessor();
        if (empty($_FILES['file'])) {
            return ['success' => false, 'message' => 'No file was submitted for upload.', 'code' => 400];
        }
        return ProfessorService::uploadFile($this->pdo, (int)$resolved['professor']['id'], $_FILES['file']);
    }

    public function deleteFile(array $payload): array {
        $resolved = $this->getResolvedProfessor();
        $fileId = (int)($payload['fileId'] ?? ($payload['id'] ?? 0));
        if ($fileId <= 0) {
            return ['success' => false, 'message' => 'File ID is required.', 'code' => 400];
        }
        return ProfessorService::deleteFile($this->pdo, (int)$resolved['professor']['id'], $fileId);
    }

    public function downloadFile() {
        $user = requireAuth(['PROFESSOR', 'TEACHER', 'ADMIN', 'SUPER_ADMIN']);
        $fileId = (int)($_GET['fileId'] ?? ($_GET['id'] ?? 0));

        if ($fileId <= 0) {
            http_response_code(400);
            die("Invalid file ID requested.");
        }

        $stmt = $this->pdo->prepare("SELECT * FROM `professor_files` WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $fileId]);
        $file = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$file) {
            http_response_code(404);
            die("File not found.");
        }

        $role = strtoupper($user['role'] ?? '');
        if ($role === 'PROFESSOR' || $role === 'TEACHER') {
            $resolved = $this->getResolvedProfessor();
            if ((int)$file['professor_id'] !== (int)$resolved['professor']['id']) {
                http_response_code(403);
                die("Unauthorized: You do not have permission to access another professor's private file.");
            }
        }

        $fullPath = ProfessorService::UPLOAD_DIR . $file['stored_filename'];
        if (!file_exists($fullPath)) {
            http_response_code(404);
            die("Physical file missing from server storage.");
        }

        // Clean output buffers
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

    public function publishMaterial(array $payload): array {
        $resolved = $this->getResolvedProfessor();
        return ProfessorService::publishMaterial($this->pdo, (int)$resolved['professor']['id'], $payload);
    }

    public function getPublishedMaterials(): array {
        $resolved = $this->getResolvedProfessor();
        return ProfessorService::getPublishedMaterials($this->pdo, (int)$resolved['professor']['id']);
    }

    public function unpublishMaterial(array $payload): array {
        $resolved = $this->getResolvedProfessor();
        $matId = (int)($payload['materialId'] ?? ($payload['id'] ?? 0));
        return ProfessorService::unpublishMaterial($this->pdo, (int)$resolved['professor']['id'], $matId);
    }

    public function getAnnouncements(): array {
        $resolved = $this->getResolvedProfessor();
        return ProfessorService::getAnnouncements($this->pdo, (int)$resolved['professor']['id']);
    }

    public function createAnnouncement(array $payload): array {
        $resolved = $this->getResolvedProfessor();
        return ProfessorService::createAnnouncement(
            $this->pdo,
            (int)$resolved['professor']['id'],
            $payload,
            $resolved['professor']['name'],
            $resolved['userId']
        );
    }

    public function deleteAnnouncement(array $payload): array {
        $resolved = $this->getResolvedProfessor();
        $annId = (int)($payload['announcementId'] ?? ($payload['id'] ?? 0));
        return ProfessorService::deleteAnnouncement($this->pdo, $resolved['userId'], $annId);
    }
}
