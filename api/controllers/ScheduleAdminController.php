<?php
/**
 * Schedule Admin Controller — Handles section scheduling and academic period terms management
 */
require_once __DIR__ . '/../models/SectionModel.php';
require_once __DIR__ . '/../../shared/backend/utils/session_guard.php';

class ScheduleAdminController {
    private $sectionModel;

    public function __construct(PDO $pdo) {
        $this->sectionModel = new SectionModel($pdo);
    }

    public function getSections(): array {
        return ['success' => true, 'data' => $this->sectionModel->getAllSections()];
    }

    public function getTerms(): array {
        return ['success' => true, 'data' => $this->sectionModel->getAllTerms()];
    }

    public function saveSection(array $payload): array {
        requireAuth(['ADMIN', 'SUPER_ADMIN']);
        $sec = isset($payload['section']) ? $payload['section'] : $payload;
        $code = strtoupper(trim($sec['code'] ?? ''));
        $prog = trim($sec['program'] ?? '');
        $cap = max(1, min(100, (int)($sec['maxCapacity'] ?? 40)));

        if (empty($code) || empty($prog)) {
            return ['success' => false, 'message' => 'Section code and program are required.', 'code' => 400];
        }

        $sec['code'] = $code;
        $sec['program'] = $prog;
        $sec['maxCapacity'] = $cap;
        return ['success' => true, 'data' => $this->sectionModel->saveSection($sec)];
    }

    public function saveTerm(array $payload): array {
        requireAuth(['ADMIN', 'SUPER_ADMIN']);
        $term = $payload['term'] ?? [];
        $name = trim($term['name'] ?? '');
        $ay = trim($term['academicYear'] ?? '');
        $start = trim($term['enrollmentStart'] ?? '');
        $end = trim($term['enrollmentEnd'] ?? '');

        if (empty($name)) {
            return ['success' => false, 'message' => 'Academic period name is required.', 'code' => 400];
        }
        if (!empty($ay) && !preg_match('/^\d{4}-\d{4}$/', $ay)) {
            return ['success' => false, 'message' => 'Academic Year must follow the format YYYY-YYYY (e.g. 2026-2027).', 'code' => 400];
        }
        if (!empty($start) && !empty($end) && $end < $start) {
            return ['success' => false, 'message' => 'Enrollment end date cannot be earlier than start date.', 'code' => 400];
        }

        return ['success' => true, 'data' => $this->sectionModel->saveTerm($term)];
    }
}
