<?php
/**
 * GNCP Workstations — Queue Service
 * Handles queue compilation, section scheduling mapping, and stats aggregation.
 *
 * Performance: Pre-loads programs map and curriculum cache before the main loop
 * to eliminate N+1 query patterns (was ~3N+2 queries, now ~7 queries total).
 */

require_once __DIR__ . '/../../../shared/backend/services/AssessmentService.php';

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
        // 1. Get active semester period first to scope the sections
        $activeSem = '1st Semester';
        $activePeriodId = null;
        $activePeriodQuery = $pdo->query("SELECT `id`, `semester` FROM `academic_periods` WHERE `status` = 'Active' LIMIT 1");
        if ($activePeriodQuery) {
            $apRow = $activePeriodQuery->fetch(PDO::FETCH_ASSOC);
            if ($apRow) {
                $activeSem = $apRow['semester'];
                $activePeriodId = (int)$apRow['id'];
            }
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

        // 3. Fetch permanent student directory records and index by reference ID/Email for seamless merging
        $studMap = [];
        $existingRefs = [];
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
            foreach ($studRows as $sr) {
                if (!empty($sr['temp_reference_no'])) {
                    $studMap[$sr['temp_reference_no']] = $sr;
                }
                $studMap[$sr['id']] = $sr;
                if (!empty($sr['email'])) {
                    $studMap[strtolower($sr['email'])] = $sr;
                }
            }
        } catch (Exception $e) {
            // Log silently — do not halt queue; enrolled students may be missing from view
            if (function_exists('logAppError')) {
                logAppError('QueueService: Failed to fetch students table', [
                    'error' => $e->getMessage()
                ]);
            }
        }

        // 4. Merge updated student fields from permanent directory into staging queue rows
        foreach ($rows as &$row) {
            $ref = $row['temp_student_id'];
            $email = strtolower($row['email'] ?? '');
            $sr = $studMap[$ref] ?? ($studMap[$email] ?? null);

            if ($sr) {
                $existingRefs[$ref] = true;
                $existingRefs[$sr['id']] = true;

                if (!empty($sr['medical_data']) && $sr['medical_data'] !== '{}') {
                    $row['medical_data'] = $sr['medical_data'];
                }
                if (!empty($sr['payment_data']) && $sr['payment_data'] !== '{}') {
                    $row['payment_data'] = $sr['payment_data'];
                }
                if (!empty($sr['requirements_data']) && $sr['requirements_data'] !== '{}') {
                    $row['requirements_data'] = $sr['requirements_data'];
                }
                if (!empty($sr['helpdesk_data']) && $sr['helpdesk_data'] !== '{}') {
                    $row['helpdesk_data'] = $sr['helpdesk_data'];
                }
                if (!empty($sr['roadmap']) && $sr['roadmap'] !== '[]') {
                    $row['roadmap'] = $sr['roadmap'];
                }
            }
        }
        unset($row);

        // 5. Append any permanent enrolled students that do not exist in staging pre_enrollments
        foreach ($studRows as $sr) {
            $ref = !empty($sr['temp_reference_no']) ? $sr['temp_reference_no'] : $sr['id'];
            $email = strtolower($sr['email'] ?? '');
            if (!isset($existingRefs[$ref]) && !isset($existingRefs[$sr['id']]) && !isset($existingRefs[$email])) {
                $existingRefs[$ref] = true;
                $existingRefs[$sr['id']] = true;

                $personal = json_decode($sr['personal_info'] ?? '{}', true) ?: [];
                $academic = json_decode($sr['academic_info'] ?? '{}', true) ?: [];
                $paymentInfo = json_decode($sr['payment_data'] ?? '{}', true) ?: [];
                $nameParts = explode(' ', trim($sr['name'] ?? ''));
                $firstName = $personal['firstName'] ?? ($nameParts[0] ?? '');
                $lastName = $personal['lastName'] ?? (end($nameParts) ?: '');
                $middleName = $personal['middleName'] ?? '';

                // Recover original temp_pin from personal_info; fall back to masked placeholder
                $recoveredPin = $personal['temp_pin'] ?? $sr['temp_pin'] ?? '——';

                $rows[] = [
                    'id'                  => $sr['id'],
                    'temp_student_id'     => $ref,
                    'temp_pin'            => $recoveredPin,
                    'first_name'          => $firstName,
                    'middle_name'         => $middleName,
                    'last_name'           => $lastName,
                    'course_code'         => $sr['program'],
                    'student_type'        => 'REGULAR',
                    'phone'               => $personal['phone'] ?? '',
                    'email'               => $sr['email'],
                    'gender'              => $personal['gender'] ?? 'Other',
                    'address'             => $personal['address'] ?? '',
                    'payment_mode'        => $paymentInfo['paymentMode'] ?? ($paymentInfo['paymentType'] ?? 'CASH'),
                    'created_at'          => $sr['created_at'],
                    'senior_high_school'  => $academic['seniorHighSchool'] ?? '',
                    'shs_track'           => $academic['shsTrack'] ?? '',
                    'health_status'       => 'GOOD',
                    'medical_conditions'  => '',
                    'allergies'           => 'None',
                    'current_medication'  => 0,
                    'medication_details'  => '',
                    'roadmap'             => $sr['roadmap'],
                    'requirements_data'   => $sr['requirements_data'],
                    'medical_data'        => $sr['medical_data'],
                    'scholarship_data'    => $sr['scholarship_data'],
                    'payment_data'        => $sr['payment_data'],
                    'helpdesk_data'       => $sr['helpdesk_data'],
                    'enrollment_data'     => $sr['enrollment_data'],
                    'scholarship'         => 'NONE',
                    'year_level_applied'  => $sr['year_level'],
                    'status'              => $sr['status']
                ];
            }
        }

        // 6. Fetch subject sections belonging to the active academic period
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
        } else {
            $sectionsRaw = [];
        }

        // -------------------------------------------------------------------
        // PERFORMANCE FIX: Pre-load all programs into an in-memory map.
        // This eliminates the N+1 pattern (was 1 query per student in the loop).
        // -------------------------------------------------------------------
        $progMap = [];
        $allProgsStmt = $pdo->query("SELECT `code`, `name` FROM `programs`");
        if ($allProgsStmt) {
            foreach ($allProgsStmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
                $progMap[$p['code']] = $p['name'];
            }
        }

        // -------------------------------------------------------------------
        // PERFORMANCE FIX: Pre-load curriculum subjects for all unique
        // (course_code, year_level) combinations found in the queue.
        // This eliminates the N+1 getCurriculumSubjects() call per student.
        // -------------------------------------------------------------------
        $curriculumCache = [];
        $uniqueCombos = [];
        foreach ($rows as $r) {
            $yearLvl = !empty($r['year_level_applied']) ? $r['year_level_applied'] : '1st Year';
            $key = ($r['course_code'] ?? '') . '|' . $yearLvl;
            $uniqueCombos[$key] = [$r['course_code'] ?? '', $yearLvl];
        }
        foreach ($uniqueCombos as $key => [$code, $yearLvl]) {
            if ($code) {
                $curriculumCache[$key] = self::getCurriculumSubjects($pdo, $code, $yearLvl, $activeSem);
            }
        }

        // 7. Build the final queue payload — all lookups are now O(1)
        $queue = [];
        foreach ($rows as $row) {
            $nameParts = array_filter([$row['first_name'], $row['middle_name'], $row['last_name']]);
            $fullName = implode(' ', $nameParts);

            $medConditionsStr = $row['medical_conditions'] ?? '';
            $medConditionsArr = $medConditionsStr ? array_map('trim', explode(',', $medConditionsStr)) : [];

            // O(1) program name lookup — no more per-student SQL query
            $programName = $progMap[$row['course_code'] ?? ''] ?? ($row['course_code'] ?? '');

            $yearLevel = !empty($row['year_level_applied']) ? $row['year_level_applied'] : '1st Year';

            // O(1) curriculum lookup — no more per-student SQL query
            $cacheKey = ($row['course_code'] ?? '') . '|' . $yearLevel;
            $progSubjects = $curriculumCache[$cacheKey] ?? [];
            $subjectTitles = array_column($progSubjects, 'title');

            $matchingSections = [];
            foreach ($sectionsRaw as $sec) {
                if ($sec['capacity'] > 0 &&
                    in_array($sec['subject'], $subjectTitles) &&
                    (empty($sec['program']) || $sec['program'] === $programName) &&
                    (empty($sec['year_level']) || $sec['year_level'] === $yearLevel) &&
                    (empty($sec['semester']) || $sec['semester'] === $activeSem)) {

                    $matchingSections[] = [
                        'id'         => (int)$sec['id'],
                        'subject'    => $sec['subject'],
                        'code'       => $sec['code'],
                        'instructor' => $sec['instructor'],
                        'days'       => $sec['days'],
                        'time'       => $sec['time'],
                        'room'       => $sec['room'],
                        'capacity'   => (int)$sec['capacity']
                    ];
                }
            }

            $rowId = (int)($row['id'] ?? 0);
            $padId = str_pad((string)($rowId > 0 ? $rowId : rand(1, 999)), 3, '0', STR_PAD_LEFT);
            $parsedRoadmap = json_decode((string)($row['roadmap'] ?? ''), true) ?: [];

            // Compute deterministic station queue tokens
            $queueTickets = [
                'registrar' => 'REG-' . $padId,
                'helpdesk'  => 'ADV-' . $padId,
                'medical'   => 'MED-' . $padId,
                'cashier'   => 'CSH-' . $padId,
                'it'        => 'ITC-' . $padId
            ];

            // Compute arrival timestamps per station based on roadmap progression
            $stationArrivals = [
                'registrar' => $row['created_at'] ?? null,
                'helpdesk'  => $parsedRoadmap[1]['updatedAt'] ?? ($parsedRoadmap[0]['updatedAt'] ?? ($row['created_at'] ?? null)),
                'medical'   => $parsedRoadmap[2]['updatedAt'] ?? ($parsedRoadmap[1]['updatedAt'] ?? ($row['created_at'] ?? null)),
                'cashier'   => $parsedRoadmap[3]['updatedAt'] ?? ($parsedRoadmap[2]['updatedAt'] ?? ($row['created_at'] ?? null)),
                'it'        => $parsedRoadmap[4]['updatedAt'] ?? ($parsedRoadmap[3]['updatedAt'] ?? ($row['created_at'] ?? null))
            ];

            // Determine active station ticket
            $activeStationKey = 'registrar';
            if ($row['status'] === 'VERIFIED') {
                $activeStationKey = 'helpdesk';
            } elseif ($row['status'] === 'ADVISED') {
                $activeStationKey = 'medical';
            } elseif ($row['status'] === 'MEDICAL_CLEARED') {
                $activeStationKey = 'cashier';
            } elseif ($row['status'] === 'PAID') {
                $activeStationKey = 'it';
            }

            $currentTicket = $queueTickets[$activeStationKey] ?? ('Q-' . $padId);

            $queue[] = [
                'id'                 => (int)$row['id'],
                'referenceNumber'    => $row['temp_student_id'],
                'tempPin'            => $row['temp_pin'],
                'status'             => $row['status'] ?? 'PRE_REGISTERED',
                'queueTickets'       => $queueTickets,
                'stationArrivals'    => $stationArrivals,
                'currentTicket'      => $currentTicket,
                'activeStation'      => $activeStationKey,
                'lastName'           => $row['last_name'],
                'firstName'          => $row['first_name'],
                'middleName'         => $row['middle_name'] ?? '',
                'name'               => $fullName,
                'program'            => $row['course_code'],
                'studentType'        => $row['student_type'],
                'phone'              => $row['phone'],
                'email'              => $row['email'],
                'gender'             => $row['gender'] ?? 'Not specified',
                'birthDate'          => $row['birth_date'] ?? '',
                'address'            => $row['address'],
                'nstp'               => $row['nstp'] ?? '',
                'emergencyContactName'  => $row['emergency_contact_name'] ?? '',
                'emergencyContactPhone' => $row['emergency_contact_phone'] ?? '',
                'fitnessParticipation'  => (bool)($row['fitness_participation'] ?? true),
                'paymentMode'        => $row['payment_mode'] ?? 'Cash',
                'datePreRegistered'  => date('F j, Y', strtotime($row['created_at'])),
                'createdAt'          => $row['created_at'],
                'seniorHighSchool'   => $row['senior_high_school'] ?? '',
                'shsTrack'           => $row['shs_track'] ?? '',
                'orNumber'           => $row['or_number'] ?? null,
                'enrolledAt'         => $row['enrolled_at'] ?? null,
                'cashierName'        => $row['cashier_name'] ?? null,
                'form'               => [
                    'healthStatus'          => $row['health_status'] ?? 'GOOD',
                    'medicalConditions'     => $medConditionsArr,
                    'allergies'             => $row['allergies'] ?? 'None',
                    'currentMedication'     => (bool)($row['current_medication'] ?? false),
                    'medicationDetails'     => $row['medication_details'] ?? '',
                    'fitnessParticipation'  => (bool)($row['fitness_participation'] ?? true),
                    'emergencyContactName'  => $row['emergency_contact_name'] ?? '',
                    'emergencyContactPhone' => $row['emergency_contact_phone'] ?? ''
                ],
                'roadmap'            => $parsedRoadmap,
                'requirements'       => json_decode((string)($row['requirements_data'] ?? ''), true) ?: new stdClass(),
                'medical'            => json_decode((string)($row['medical_data'] ?? ''), true) ?: new stdClass(),
                'scholarship'        => json_decode((string)($row['scholarship_data'] ?? ''), true) ?: new stdClass(),
                'payment'            => json_decode((string)($row['payment_data'] ?? ''), true) ?: new stdClass(),
                'helpdesk'           => array_merge(
                    ['scholarshipName' => $row['scholarship'] ?? 'NONE'],
                    json_decode((string)($row['helpdesk_data'] ?? ''), true) ?: []
                ),
                'enrollment'         => json_decode((string)($row['enrollment_data'] ?? ''), true) ?: new stdClass(),
                'prospectusSubjects' => $progSubjects,
                'availableSections'  => $matchingSections
            ];
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

        // PERFORMANCE FIX: Use bound parameter instead of raw string interpolation
        $todayStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM `students` WHERE DATE(`created_at`) = :today"
        );
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

    private static function getCurriculumSubjects(PDO $pdo, string $courseCode, string $yearLevel, string $semester): array {
        try {
            $aliasMap = [
                'BSCPE' => 'BS Computer Engineering',
                'BSCOE' => 'BS Computer Engineering',
                'BSCS'  => 'BS Computer Science',
                'BSIT'  => 'BS Information Technology',
                'BSN'   => 'BS Nursing',
                'BSBA'  => 'BS Business Administration'
            ];
            $cleanCode = strtoupper(trim($courseCode));
            $programName = $aliasMap[$cleanCode] ?? null;

            if (!$programName) {
                $progStmt = $pdo->prepare("SELECT `name` FROM `programs` WHERE `code` = :code OR `name` = :name");
                $progStmt->execute([':code' => $courseCode, ':name' => $courseCode]);
                $programName = $progStmt->fetchColumn() ?: $courseCode;
            }

            $stmt = $pdo->prepare("
                SELECT s.code, s.title, s.lecture_units, s.lab_units, s.lab_fee, s.prerequisites
                FROM `curriculum` c
                JOIN `subjects` s ON (c.subject = s.title OR c.subject = s.code)
                WHERE (c.program = :progName OR c.program = :progCode)
                  AND c.year_level = :year_level
                  AND c.semester = :sem
            ");
            $stmt->execute([
                ':progName'   => $programName,
                ':progCode'   => $courseCode,
                ':year_level' => $yearLevel,
                ':sem'        => $semester
            ]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            if (function_exists('logAppError')) {
                logAppError('QueueService::getCurriculumSubjects Error', [
                    'error' => $e->getMessage(),
                    'courseCode' => $courseCode,
                    'yearLevel' => $yearLevel,
                    'semester' => $semester
                ]);
            }
            return [];
        }
    }
}

