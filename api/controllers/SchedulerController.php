<?php
/**
 * Scheduler API Controller
 * Provides authenticated REST endpoints for academic schedule management,
 * professor assignment, and conflict checking.
 */

require_once __DIR__ . '/../../shared/backend/services/SchedulerService.php';
require_once __DIR__ . '/../../shared/backend/services/ScheduleConflictService.php';
require_once __DIR__ . '/../../shared/backend/utils/session_guard.php';

class SchedulerController {
    private $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    public function getCatalogContext(): array {
        requireAuth(['SCHEDULER', 'ADMIN', 'SUPER_ADMIN', 'REGISTRAR']);
        return ['success' => true, 'data' => SchedulerService::getCatalogContext($this->pdo)];
    }

    public function getSectionSchedule(array $params): array {
        requireAuth(['SCHEDULER', 'ADMIN', 'SUPER_ADMIN', 'REGISTRAR']);
        $sectionId = (int)($params['sectionId'] ?? ($params['id'] ?? 0));
        if ($sectionId <= 0) {
            return ['success' => false, 'message' => 'Valid Section ID is required.', 'code' => 400];
        }
        return SchedulerService::getSectionSchedule($this->pdo, $sectionId);
    }

    public function getEligibleProfessors(array $params): array {
        requireAuth(['SCHEDULER', 'ADMIN', 'SUPER_ADMIN', 'REGISTRAR']);
        $offeringId = (int)($params['offeringId'] ?? ($params['subjectSectionId'] ?? ($params['id'] ?? 0)));
        if ($offeringId <= 0) {
            return ['success' => false, 'message' => 'Valid Subject Section ID is required.', 'code' => 400];
        }
        return SchedulerService::getEligibleProfessorsForSubject($this->pdo, $offeringId);
    }

    public function assignProfessor(array $payload): array {
        $user = requireAuth(['SCHEDULER', 'ADMIN', 'SUPER_ADMIN', 'REGISTRAR']);
        $profId = (int)($payload['professorId'] ?? 0);
        $offeringId = (int)($payload['subjectSectionId'] ?? ($payload['offeringId'] ?? 0));
        $operator = $user['username'] ?? 'Scheduler';

        if ($profId <= 0 || $offeringId <= 0) {
            return ['success' => false, 'message' => 'Professor ID and Subject Section ID are required.', 'code' => 400];
        }

        return SchedulerService::assignProfessor($this->pdo, $profId, $offeringId, $operator);
    }

    public function autoAssignSection(array $payload): array {
        $user = requireAuth(['SCHEDULER', 'ADMIN', 'SUPER_ADMIN']);
        $sectionId = (int)($payload['sectionId'] ?? 0);
        $operator = $user['username'] ?? 'Scheduler';

        if ($sectionId <= 0) {
            return ['success' => false, 'message' => 'Section ID is required.', 'code' => 400];
        }

        return SchedulerService::autoAssignSection($this->pdo, $sectionId, $operator);
    }

    public function validateSlot(array $payload): array {
        requireAuth(['SCHEDULER', 'ADMIN', 'SUPER_ADMIN', 'REGISTRAR']);
        $slotData = $payload['slot'] ?? $payload;
        $val = ScheduleConflictService::validateSlot($this->pdo, $slotData);
        return [
            'success' => $val['valid'],
            'data'    => $val,
            'message' => $val['message']
        ];
    }

    public function saveSubjectSection(array $payload): array {
        $user = requireAuth(['SCHEDULER', 'ADMIN', 'SUPER_ADMIN']);
        $slotData = $payload['section'] ?? ($payload['slot'] ?? $payload);
        $operator = $user['username'] ?? 'Scheduler';

        return SchedulerService::saveSubjectSection($this->pdo, $slotData, $operator);
    }
}
