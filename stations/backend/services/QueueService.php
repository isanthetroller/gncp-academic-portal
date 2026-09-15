<?php
/**
 * GNCP Workstations — Queue Service
 * Handles queue compilation, ETag hash verification, enrollment stats, and station history delegation.
 * Modularity: Decomposed to conform to token-efficiency bounds (< 250 lines).
 */

require_once __DIR__ . '/../../../shared/backend/services/AssessmentService.php';
require_once __DIR__ . '/ProspectusScopingService.php';
require_once __DIR__ . '/StationHistoryService.php';
require_once __DIR__ . '/QueueHydrationService.php';

class QueueService {
    public static function getQueueHash(PDO $pdo): string {
        try {
            $peStmt = $pdo->query("
                SELECT 
                    COUNT(*) AS cnt,
                    COALESCE(MAX(`id`), 0) AS max_id,
                    COALESCE(SUM(CRC32(CONCAT(
                        `id`, ':', `status`, ':', 
                        IFNULL(`section_code`,''), ':', 
                        IFNULL(`or_number`,''), ':', 
                        IFNULL(SUBSTRING(`roadmap`, 1, 80),''), ':',
                        IFNULL(SUBSTRING(`medical_data`, 1, 80),''), ':',
                        IFNULL(SUBSTRING(`payment_data`, 1, 80),'')
                    ))), 0) AS pe_checksum
                FROM `pre_enrollments`
                WHERE `status` IN ('VERIFIED', 'MEDICAL_CLEARED', 'ADVISED', 'PAID', 'ENROLLED', 'APPROVED', 'IN_PROGRESS', 'PROMOTED')
            ");
            $pe = $peStmt ? $peStmt->fetch(PDO::FETCH_ASSOC) : ['cnt' => 0, 'max_id' => 0, 'pe_checksum' => 0];

            $stStmt = $pdo->query("
                SELECT 
                    COUNT(*) AS cnt,
                    COALESCE(SUM(CRC32(CONCAT(
                        `id`, ':', `status`, ':', 
                        IFNULL(SUBSTRING(`roadmap`, 1, 80),''), ':',
                        IFNULL(SUBSTRING(`medical_data`, 1, 80),''), ':',
                        IFNULL(SUBSTRING(`payment_data`, 1, 80),'')
                    ))), 0) AS st_checksum
                FROM `students`
            ");
            $st = $stStmt ? $stStmt->fetch(PDO::FETCH_ASSOC) : ['cnt' => 0, 'st_checksum' => 0];

            $seed = sprintf("pe:%d:%d:%u-st:%d:%u", 
                $pe['cnt'] ?? 0, $pe['max_id'] ?? 0, $pe['pe_checksum'] ?? 0, 
                $st['cnt'] ?? 0, $st['st_checksum'] ?? 0
            );
            return '"' . md5($seed) . '"';
        } catch (Exception $e) {
            return '"' . md5((string)time()) . '"';
        }
    }

    public static function fetchQueue(PDO $pdo) {
        // 1. Get active semester period first to scope sections
        $activeSem = '1st Semester';
        $activePeriodYear = '2026-2027';
        $activePeriodId = null;
        $activePeriodQuery = $pdo->query("SELECT `id`, `semester`, `academic_year` FROM `academic_periods` WHERE `status` = 'Active' LIMIT 1");
        if ($activePeriodQuery && ($apRow = $activePeriodQuery->fetch(PDO::FETCH_ASSOC))) {
            $activeSem = $apRow['semester'];
            $activePeriodYear = $apRow['academic_year'] ?? '2026-2027';
            $activePeriodId = (int)$apRow['id'];
        }

        // 2. Fetch staging pre_enrollments using indexed status lookup
        $stmt = $pdo->query("
            SELECT 
                `id`, `temp_student_id`, `temp_pin`, `student_type`, `course_code`, `nstp`,
                `first_name`, `middle_name`, `last_name`, `email`, `phone`, `birth_date`, `gender`,
                `address`, `shs_track`, `previous_college`, `health_status`, `medical_conditions`,
                `allergies`, `current_medication`, `medication_details`, `fitness_participation`,
                `emergency_contact_name`, `emergency_contact_phone`, `payment_mode`, `scholarship`,
                `registrar_notes`, `status`, `roadmap`, `requirements_data`, `medical_data`,
                `scholarship_data`, `payment_data`, `helpdesk_data`, `enrollment_data`,
                `section_code`, `or_number`, `enrolled_at`, `cashier_name`, `year_level_applied`,
                `curriculum_version`, `created_at`
            FROM `pre_enrollments` 
            WHERE `status` IN ('VERIFIED', 'MEDICAL_CLEARED', 'ADVISED', 'PAID', 'ENROLLED', 'APPROVED', 'Approved', 'IN_PROGRESS')
            ORDER BY `id` DESC
        ");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 3. Fetch permanent students directory records for merging
        $studRows = [];
        try {
            $studStmt = $pdo->query("
                SELECT 
                    `id`, `name`, `program`, `email`, `year_level`, `curriculum_version`, `status`,
                    `temp_reference_no`, `personal_info`, `academic_info`, `roadmap`, `requirements_data`,
                    `medical_data`, `scholarship_data`, `payment_data`, `helpdesk_data`, `enrollment_data`,
                    `created_at`
                FROM `students` 
                ORDER BY `created_at` DESC
                LIMIT 500
            ");
            $studRows = $studStmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            if (function_exists('logAppError')) {
                logAppError('QueueService: Failed to fetch students table', ['error' => $e->getMessage()]);
            }
        }

        // 4. Merge permanent records with staging rows via QueueHydrationService
        $mergedRows = QueueHydrationService::mergeStudentRecords($rows, $studRows);

        // 5. Fetch subject sections for active period
        $sectionsRaw = [];
        if ($activePeriodId !== null) {
            $sectionsStmt = $pdo->prepare("
                SELECT ss.*,
                       COALESCE(s.program, ss.program) AS program,
                       COALESCE(s.year_level, ss.year_level) AS year_level
                FROM `subject_sections` ss
                LEFT JOIN `sections` s ON ss.section_id = s.id
                WHERE ss.semester = (SELECT semester FROM `academic_periods` WHERE id = :activePeriodId1 LIMIT 1)
                   OR s.academic_period_id = :activePeriodId2
            ");
            $sectionsStmt->execute([
                'activePeriodId1' => $activePeriodId,
                'activePeriodId2' => $activePeriodId
            ]);
            $sectionsRaw = $sectionsStmt->fetchAll(PDO::FETCH_ASSOC);
        }

        // 6. Pre-load programs and curriculum maps (O(1) lookups)
        $progMap = [];
        $allProgsStmt = $pdo->query("SELECT `code`, `name` FROM `programs`");
        if ($allProgsStmt) {
            foreach ($allProgsStmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
                $progMap[$p['code']] = $p['name'];
            }
        }

        $curriculumCache = [];
        $uniqueCombos = [];
        foreach ($mergedRows as $r) {
            $yearLvl = !empty($r['year_level_applied']) ? $r['year_level_applied'] : '1st Year';
            $currVer = !empty($r['curriculum_version']) ? $r['curriculum_version'] : '2022 Curriculum';
            $key = ($r['course_code'] ?? '') . '|' . $yearLvl . '|' . $currVer;
            $uniqueCombos[$key] = [$r['course_code'] ?? '', $yearLvl, $currVer];
        }
        foreach ($uniqueCombos as $key => [$code, $yearLvl, $currVer]) {
            if ($code) {
                $curriculumCache[$key] = ProspectusScopingService::getCurriculumSubjects($pdo, $code, $yearLvl, $activeSem, $currVer);
            }
        }

        // 7. Hydrate final queue items
        $queue = [];
        foreach ($mergedRows as $row) {
            $queue[] = QueueHydrationService::hydrateQueueRow(
                $row,
                $progMap,
                $curriculumCache,
                $sectionsRaw,
                $activeSem,
                $activePeriodYear
            );
        }

        return $queue;
    }

    public static function getEnrollmentStats(PDO $pdo) {
        $stmt = $pdo->query(
            "SELECT
                SUM(JSON_UNQUOTE(JSON_EXTRACT(roadmap, '$[5].status')) != 'COMPLETED') AS pending_activation,
                SUM(JSON_UNQUOTE(JSON_EXTRACT(roadmap, '$[4].status')) = 'COMPLETED'
                    AND JSON_UNQUOTE(JSON_EXTRACT(roadmap, '$[5].status')) != 'COMPLETED') AS ready_for_it
            FROM `pre_enrollments`
            WHERE `status` NOT IN ('PRE_REGISTERED', 'Rejected', 'PROMOTED')
            AND roadmap IS NOT NULL"
        );
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);

        $totalStmt = $pdo->query("SELECT COUNT(*) FROM `students`");
        $activatedTotal = (int)$totalStmt->fetchColumn();

        $todayStmt = $pdo->prepare("SELECT COUNT(*) FROM `students` WHERE DATE(`created_at`) = :today");
        $todayStmt->execute(['today' => date('Y-m-d')]);
        $activatedToday = (int)$todayStmt->fetchColumn();

        return [
            'pendingActivation' => (int)($stats['pending_activation'] ?? 0),
            'activatedTotal'    => $activatedTotal,
            'readyForIt'        => (int)($stats['ready_for_it'] ?? 0),
            'activatedToday'    => $activatedToday
        ];
    }

    public static function fetchStudentAccounts(PDO $pdo) {
        $stmt = $pdo->query("SELECT `id`, `name`, `program`, `year_level`, `email`, `status`, `created_at` FROM `students` ORDER BY `name` ASC");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $students = [];
        foreach ($rows as $r) {
            $students[] = [
                'id'        => $r['id'],
                'name'      => $r['name'],
                'program'   => $r['program'],
                'yearLevel' => $r['year_level'],
                'year_level'=> $r['year_level'],
                'email'     => $r['email'] ?? '',
                'status'    => $r['status'],
                'createdAt' => $r['created_at'],
                'created_at'=> $r['created_at']
            ];
        }
        return $students;
    }

    /**
     * Backward-compatible delegations to modular domain services
     */
    public static function getCurriculumSubjects(PDO $pdo, string $courseCode, string $yearLevel, string $semester, ?string $curriculumVersion = null): array {
        return ProspectusScopingService::getCurriculumSubjects($pdo, $courseCode, $yearLevel, $semester, $curriculumVersion);
    }

    public static function fetchStationHistory(PDO $pdo, string $stationRole, int $limit = 200): array {
        return StationHistoryService::fetchStationHistory($pdo, $stationRole, $limit);
    }
}
