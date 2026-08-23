<?php
/**
 * Catalog Admin Controller — Handles academic programs, subjects, and curriculum catalog actions
 */
require_once __DIR__ . '/../models/CourseModel.php';
require_once __DIR__ . '/../../shared/backend/utils/session_guard.php';

class CatalogAdminController {
    private $courseModel;

    public function __construct(PDO $pdo) {
        $this->courseModel = new CourseModel($pdo);
    }

    public function getCatalog(): array {
        return ['success' => true, 'data' => $this->courseModel->getFullCatalog()];
    }

    public function saveProgram(array $payload): array {
        requireAuth(['ADMIN', 'SUPER_ADMIN']);
        $prog = $payload['program'] ?? [];
        $code = strtoupper(trim($prog['code'] ?? ''));
        $name = trim($prog['name'] ?? '');
        $dept = trim($prog['department'] ?? '');

        if (empty($code) || empty($name)) {
            return ['success' => false, 'message' => 'Program code and name are required.', 'code' => 400];
        }

        $prog['code'] = $code;
        $prog['name'] = $name;
        $prog['department'] = $dept;
        return ['success' => true, 'data' => $this->courseModel->saveProgram($prog)];
    }

    public function saveSubject(array $payload): array {
        requireAuth(['ADMIN', 'SUPER_ADMIN']);
        $subj = $payload['subject'] ?? [];
        $code = strtoupper(trim($subj['code'] ?? ''));
        $title = trim($subj['title'] ?? '');
        $dept = trim($subj['department'] ?? '');
        $lec = max(0, (int)($subj['lectureUnits'] ?? 0));
        $lab = max(0, (int)($subj['labUnits'] ?? 0));

        if (empty($code) || empty($title)) {
            return ['success' => false, 'message' => 'Subject code and title are required.', 'code' => 400];
        }
        if (($lec + $lab) <= 0) {
            return ['success' => false, 'message' => 'Total units (lecture + lab) must be greater than zero.', 'code' => 400];
        }

        $subj['code'] = $code;
        $subj['title'] = $title;
        $subj['department'] = $dept;
        $subj['lectureUnits'] = $lec;
        $subj['labUnits'] = $lab;
        return ['success' => true, 'data' => $this->courseModel->saveSubject($subj)];
    }

    public function saveCurriculum(array $payload): array {
        requireAuth(['ADMIN', 'SUPER_ADMIN']);
        $curr = $payload['curriculum'] ?? [];
        if (empty($curr['program']) || empty($curr['subject'])) {
            return ['success' => false, 'message' => 'Program and Subject are required for curriculum mapping.', 'code' => 400];
        }
        return ['success' => true, 'data' => $this->courseModel->saveCurriculum($curr)];
    }

    public function deleteCurriculum(array $payload): array {
        requireAuth(['ADMIN', 'SUPER_ADMIN']);
        $id = $payload['id'] ?? null;
        if (!$id) {
            return ['success' => false, 'message' => 'Curriculum ID is required.', 'code' => 400];
        }
        return ['success' => true, 'data' => $this->courseModel->deleteCurriculum($id)];
    }

    public function cloneCurriculumVersion(array $payload): array {
        requireAuth(['ADMIN', 'SUPER_ADMIN']);
        $prog = trim($payload['program'] ?? '');
        $fromV = trim($payload['fromVersion'] ?? '');
        $toV = trim($payload['toVersion'] ?? '');
        if (empty($prog) || empty($fromV) || empty($toV)) {
            return ['success' => false, 'message' => 'Program, Source Version, and Target Version are required.', 'code' => 400];
        }
        return ['success' => true, 'data' => $this->courseModel->cloneCurriculumVersion($prog, $fromV, $toV)];
    }
}
