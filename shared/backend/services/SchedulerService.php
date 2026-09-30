<?php
/**
 * GNCP Academic Portal — Scheduler Domain Service
 * Manages academic schedule configuration, professor eligibility calculation,
 * manual assignment with authoritative conflict rejection, and deterministic auto-assignment.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/ScheduleConflictService.php';

class SchedulerService {

    /**
     * Retrieve full context required by the Scheduler interface
     */
    public static function getCatalogContext(PDO $pdo): array {
        $activePeriodStmt = $pdo->query("SELECT * FROM `academic_periods` WHERE `status` = 'Active' LIMIT 1");
        $activePeriod = $activePeriodStmt ? $activePeriodStmt->fetch(PDO::FETCH_ASSOC) : null;

        $allPeriods = $pdo->query("SELECT * FROM `academic_periods` ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
        $programs = $pdo->query("SELECT * FROM `programs` WHERE `status` = 'Active' ORDER BY `code` ASC")->fetchAll(PDO::FETCH_ASSOC);
        $departments = $pdo->query("SELECT * FROM `departments` WHERE `status` = 'Active' ORDER BY `code` ASC")->fetchAll(PDO::FETCH_ASSOC);

        $professorsRaw = $pdo->query("
            SELECT p.*, d.code as department_code, d.name as department_name,
                   (SELECT COUNT(*) FROM `subject_sections` ss WHERE ss.professor_id = p.id) as assigned_classes_count
            FROM `professors` p
            JOIN `departments` d ON p.department_id = d.id
            ORDER BY d.code ASC, p.name ASC
        ")->fetchAll(PDO::FETCH_ASSOC);

        $sections = $pdo->query("
            SELECT s.*, 
                   COALESCE(ap.name, '') as period_name,
                   (SELECT COUNT(*) FROM `subject_sections` ss WHERE ss.section_id = s.id) as offerings_count
            FROM `sections` s
            LEFT JOIN `academic_periods` ap ON s.academic_period_id = ap.id
            ORDER BY s.program ASC, s.year_level ASC, s.code ASC
        ")->fetchAll(PDO::FETCH_ASSOC);

        return [
            'activePeriod' => $activePeriod,
            'periods'      => $allPeriods,
            'programs'     => $programs,
            'departments'  => $departments,
            'professors'   => $professorsRaw,
            'sections'     => $sections
        ];
    }

    /**
     * Retrieve subject offerings for a specified section
     */
    public static function getSectionSchedule(PDO $pdo, int $sectionId): array {
        $secStmt = $pdo->prepare("
            SELECT s.*, ap.name as period_name, ap.semester as period_semester, ap.academic_year 
            FROM `sections` s 
            LEFT JOIN `academic_periods` ap ON s.academic_period_id = ap.id 
            WHERE s.id = :id 
            LIMIT 1
        ");
        $secStmt->execute(['id' => $sectionId]);
        $section = $secStmt->fetch(PDO::FETCH_ASSOC);

        if (!$section) {
            return ['success' => false, 'message' => 'Section not found.', 'code' => 404];
        }

        $ssStmt = $pdo->prepare("
            SELECT ss.*, 
                   p.name as professor_name, p.employee_id as professor_employee_id, 
                   d.code as professor_dept_code, d.name as professor_dept_name,
                   sub.lecture_units, sub.lab_units, (sub.lecture_units + sub.lab_units) as total_units
            FROM `subject_sections` ss
            LEFT JOIN `professors` p ON ss.professor_id = p.id
            LEFT JOIN `departments` d ON p.department_id = d.id
            LEFT JOIN `subjects` sub ON (ss.code = sub.code OR ss.subject = sub.title)
            WHERE ss.section_id = :sec_id OR ss.code LIKE :sec_pat
            ORDER BY ss.id ASC
        ");
        $ssStmt->execute([
            'sec_id'  => $sectionId,
            'sec_pat' => '%' . $section['code'] . '%'
        ]);
        $offerings = $ssStmt->fetchAll(PDO::FETCH_ASSOC);

        $formatted = [];
        foreach ($offerings as $off) {
            $conflictInfo = null;
            if (!empty($off['professor_id'])) {
                $check = ScheduleConflictService::validateAssignment($pdo, (int)$off['professor_id'], (int)$off['id'], (int)$section['academic_period_id']);
                if (!$check['valid']) {
                    $conflictInfo = $check['message'];
                }
            }

            $formatted[] = [
                'id'              => (int)$off['id'],
                'program'         => $off['program'],
                'yearLevel'       => $off['year_level'],
                'semester'        => $off['semester'],
                'subject'         => $off['subject'],
                'code'            => $off['code'],
                'instructor'      => $off['instructor'],
                'professorId'     => !empty($off['professor_id']) ? (int)$off['professor_id'] : null,
                'professorName'   => $off['professor_name'] ?? $off['instructor'],
                'professorEmpId'  => $off['professor_employee_id'] ?? null,
                'professorDept'   => $off['professor_dept_code'] ?? null,
                'days'            => $off['days'],
                'time'            => $off['time'],
                'room'            => $off['room'],
                'capacity'        => (int)$off['capacity'],
                'sectionId'       => (int)($off['section_id'] ?: $sectionId),
                'lectureUnits'    => (float)($off['lecture_units'] ?? 3.0),
                'labUnits'        => (float)($off['lab_units'] ?? 0.0),
                'totalUnits'      => (float)($off['total_units'] ?? 3.0),
                'hasConflict'     => !empty($conflictInfo),
                'conflictMessage' => $conflictInfo
            ];
        }

        return [
            'success' => true,
            'data'    => [
                'section'   => $section,
                'offerings' => $formatted
            ]
        ];
    }

    /**
     * Compute eligible professors for a subject offering with real-time conflict testing
     */
    public static function getEligibleProfessorsForSubject(PDO $pdo, int $subjectSectionId): array {
        $ssStmt = $pdo->prepare("
            SELECT ss.*, s.academic_period_id as sec_period_id 
            FROM `subject_sections` ss 
            LEFT JOIN `sections` s ON ss.section_id = s.id 
            WHERE ss.id = :id 
            LIMIT 1
        ");
        $ssStmt->execute(['id' => $subjectSectionId]);
        $ss = $ssStmt->fetch(PDO::FETCH_ASSOC);

        if (!$ss) {
            return ['success' => false, 'message' => 'Subject offering slot not found.', 'code' => 404];
        }

        $activePeriodId = $ss['sec_period_id'] ?: $pdo->query("SELECT id FROM `academic_periods` WHERE `status` = 'Active' LIMIT 1")->fetchColumn();

        // Get subject's department
        $subDeptStmt = $pdo->prepare("SELECT department FROM `subjects` WHERE code = :code OR title = :title LIMIT 1");
        $subDeptStmt->execute(['code' => $ss['code'], 'title' => $ss['subject']]);
        $subDept = $subDeptStmt->fetchColumn() ?: '';

        // Query all active professors
        $profStmt = $pdo->query("
            SELECT p.*, d.code as dept_code, d.name as dept_name,
                   (
                       SELECT COUNT(*) FROM `subject_sections` ss2 
                       LEFT JOIN `sections` s2 ON ss2.section_id = s2.id 
                       WHERE ss2.professor_id = p.id
                   ) as current_load_count
            FROM `professors` p
            JOIN `departments` d ON p.department_id = d.id
            JOIN `station_users` u ON p.user_id = u.id
            WHERE p.status = 'ACTIVE' AND u.status = 'ACTIVE'
            ORDER BY d.code ASC, p.name ASC
        ");
        $professors = $profStmt->fetchAll(PDO::FETCH_ASSOC);

        $results = [];
        foreach ($professors as $prof) {
            $isDeptMatch = (strcasecmp($subDept, $prof['dept_name']) === 0 || strcasecmp($subDept, $prof['dept_code']) === 0);

            // Run validation
            $val = ScheduleConflictService::validateAssignment($pdo, (int)$prof['id'], $subjectSectionId, (int)$activePeriodId);

            $results[] = [
                'id'              => (int)$prof['id'],
                'name'            => $prof['name'],
                'employeeId'      => $prof['employee_id'],
                'departmentId'    => (int)$prof['department_id'],
                'departmentCode'  => $prof['dept_code'],
                'departmentName'  => $prof['dept_name'],
                'isDeptMatch'     => $isDeptMatch,
                'currentLoad'     => (int)$prof['current_load_count'],
                'isAvailable'     => $val['valid'],
                'conflictType'    => $val['type'] ?? null,
                'conflictMessage' => $val['valid'] ? null : $val['message'],
                'isAssigned'      => (!empty($ss['professor_id']) && (int)$ss['professor_id'] === (int)$prof['id'])
            ];
        }

        // Deterministic sorting:
        // 1. Available first
        // 2. Dept match first
        // 3. Current load ASC
        // 4. Professor ID ASC
        usort($results, function($a, $b) {
            if ($a['isAvailable'] !== $b['isAvailable']) {
                return $a['isAvailable'] ? -1 : 1;
            }
            if ($a['isDeptMatch'] !== $b['isDeptMatch']) {
                return $a['isDeptMatch'] ? -1 : 1;
            }
            if ($a['currentLoad'] !== $b['currentLoad']) {
                return $a['currentLoad'] <=> $b['currentLoad'];
            }
            return $a['id'] <=> $b['id'];
        });

        return ['success' => true, 'data' => $results];
    }

    /**
     * Authoritative manual professor assignment
     */
    public static function assignProfessor(PDO $pdo, int $professorId, int $subjectSectionId, string $operator = 'Scheduler'): array {
        // 1. Strict conflict validation
        $val = ScheduleConflictService::validateAssignment($pdo, $professorId, $subjectSectionId);
        if (!$val['valid']) {
            return [
                'success' => false,
                'type'    => $val['type'] ?? 'Conflict',
                'message' => $val['message'],
                'code'    => 400
            ];
        }

        // 2. Atomic update
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("
                UPDATE `subject_sections` 
                SET `professor_id` = :pid, `instructor` = :pname 
                WHERE `id` = :id
            ");
            $stmt->execute([
                'pid'   => $professorId,
                'pname' => $val['professorName'],
                'id'    => $subjectSectionId
            ]);

            // Audit log
            $auditStmt = $pdo->prepare("
                INSERT INTO `audit_logs` (`reference_number`, `operator_username`, `station_role`, `action_performed`, `new_state`)
                VALUES (:ref, :op, 'SCHEDULER', 'ASSIGN_PROFESSOR', :new_st)
            ");
            $auditStmt->execute([
                'ref'    => "SS-{$subjectSectionId}",
                'op'     => $operator,
                'new_st' => json_encode([
                    'subjectSectionId' => $subjectSectionId,
                    'professorId'      => $professorId,
                    'professorName'    => $val['professorName']
                ])
            ]);

            $pdo->commit();
            return [
                'success' => true,
                'message' => "Professor '{$val['professorName']}' assigned successfully.",
                'data'    => [
                    'professorId'   => $professorId,
                    'professorName' => $val['professorName']
                ]
            ];
        } catch (Exception $e) {
            $pdo->rollBack();
            return ['success' => false, 'message' => 'Database error: ' . $e->getMessage(), 'code' => 500];
        }
    }

    /**
     * Deterministic Auto-Assignment for an entire section
     */
    public static function autoAssignSection(PDO $pdo, int $sectionId, string $operator = 'Scheduler'): array {
        $secStmt = $pdo->prepare("SELECT * FROM `sections` WHERE id = :id LIMIT 1");
        $secStmt->execute(['id' => $sectionId]);
        $section = $secStmt->fetch(PDO::FETCH_ASSOC);

        if (!$section) {
            return ['success' => false, 'message' => 'Section not found.', 'code' => 404];
        }

        $ssStmt = $pdo->prepare("SELECT * FROM `subject_sections` WHERE section_id = :id ORDER BY id ASC");
        $ssStmt->execute(['id' => $sectionId]);
        $offerings = $ssStmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($offerings)) {
            $ssStmt = $pdo->prepare("SELECT * FROM `subject_sections` WHERE code LIKE :pat ORDER BY id ASC");
            $ssStmt->execute(['pat' => '%' . $section['code'] . '%']);
            $offerings = $ssStmt->fetchAll(PDO::FETCH_ASSOC);
        }

        if (empty($offerings)) {
            return ['success' => false, 'message' => 'No subject offerings configured for this section.', 'code' => 400];
        }

        $assigned = [];
        $skipped  = [];
        $failed   = [];

        $pdo->beginTransaction();
        try {
            foreach ($offerings as $off) {
                // If already assigned and conflict-free, we can keep or re-evaluate
                $currentProfId = (int)($off['professor_id'] ?? 0);
                if ($currentProfId > 0) {
                    $chk = ScheduleConflictService::validateAssignment($pdo, $currentProfId, (int)$off['id'], (int)$section['academic_period_id']);
                    if ($chk['valid']) {
                        $skipped[] = [
                            'subject'       => $off['subject'],
                            'code'          => $off['code'],
                            'professorName' => $off['instructor'],
                            'reason'        => 'Already assigned with no conflict.'
                        ];
                        continue;
                    }
                }

                // Retrieve candidates using deterministic ranking
                $candRes = self::getEligibleProfessorsForSubject($pdo, (int)$off['id']);
                if (!$candRes['success']) {
                    $failed[] = ['subject' => $off['subject'], 'reason' => $candRes['message']];
                    continue;
                }

                $candidates = $candRes['data'];
                $chosen = null;

                // Pick the top eligible candidate
                foreach ($candidates as $cand) {
                    if ($cand['isAvailable']) {
                        $chosen = $cand;
                        break;
                    }
                }

                if ($chosen) {
                    $upd = $pdo->prepare("UPDATE `subject_sections` SET `professor_id` = :pid, `instructor` = :pname WHERE id = :id");
                    $upd->execute([
                        'pid'   => $chosen['id'],
                        'pname' => $chosen['name'],
                        'id'    => $off['id']
                    ]);
                    $assigned[] = [
                        'offeringId'    => (int)$off['id'],
                        'subject'       => $off['subject'],
                        'code'          => $off['code'],
                        'professorId'   => $chosen['id'],
                        'professorName' => $chosen['name'],
                        'deptMatch'     => $chosen['isDeptMatch']
                    ];
                } else {
                    $failed[] = [
                        'subject' => $off['subject'],
                        'code'    => $off['code'],
                        'reason'  => 'No available conflict-free professor found for this slot.'
                    ];
                }
            }

            // Audit log
            $auditStmt = $pdo->prepare("
                INSERT INTO `audit_logs` (`reference_number`, `operator_username`, `station_role`, `action_performed`, `new_state`)
                VALUES (:ref, :op, 'SCHEDULER', 'AUTO_ASSIGN_SECTION', :new_st)
            ");
            $auditStmt->execute([
                'ref'    => "SEC-{$sectionId}",
                'op'     => $operator,
                'new_st' => json_encode(['assignedCount' => count($assigned), 'failedCount' => count($failed)])
            ]);

            $pdo->commit();

            return [
                'success' => true,
                'data'    => [
                    'assigned' => $assigned,
                    'skipped'  => $skipped,
                    'failed'   => $failed
                ],
                'message' => 'Deterministic auto-assignment completed. ' . count($assigned) . ' subject(s) assigned.'
            ];
        } catch (Exception $e) {
            $pdo->rollBack();
            return ['success' => false, 'message' => 'Auto-assignment failed: ' . $e->getMessage(), 'code' => 500];
        }
    }

    /**
     * Create or edit a subject section schedule slot
     */
    public static function saveSubjectSection(PDO $pdo, array $slotData, string $operator = 'Scheduler'): array {
        // Validate slot
        $val = ScheduleConflictService::validateSlot($pdo, $slotData);
        if (!$val['valid']) {
            return [
                'success' => false,
                'type'    => $val['type'] ?? 'Conflict',
                'message' => $val['message'],
                'code'    => 400
            ];
        }

        $id          = !empty($slotData['id']) ? (int)$slotData['id'] : null;
        $program     = trim($slotData['program'] ?? '');
        $yearLevel   = trim($slotData['yearLevel'] ?? ($slotData['year_level'] ?? '1st Year'));
        $semester    = trim($slotData['semester'] ?? '1st Semester');
        $subject     = trim($slotData['subject'] ?? '');
        $code        = trim($slotData['code'] ?? '');
        $days        = strtoupper(trim($slotData['days'] ?? ''));
        $time        = trim($slotData['time'] ?? '');
        $room        = trim($slotData['room'] ?? 'TBA');
        $capacity    = (int)($slotData['capacity'] ?? 40);
        $sectionId   = !empty($slotData['sectionId']) ? (int)$slotData['sectionId'] : (!empty($slotData['section_id']) ? (int)$slotData['section_id'] : null);
        $professorId = !empty($slotData['professorId']) ? (int)$slotData['professorId'] : (!empty($slotData['professor_id']) ? (int)$slotData['professor_id'] : null);

        // Resolve instructor name
        $instructor = 'TBA';
        if ($professorId) {
            $pNameStmt = $pdo->prepare("SELECT name FROM `professors` WHERE id = :id LIMIT 1");
            $pNameStmt->execute(['id' => $professorId]);
            $instructor = $pNameStmt->fetchColumn() ?: 'TBA';
        }

        if ($id) {
            $stmt = $pdo->prepare("
                UPDATE `subject_sections` 
                SET `program` = :prog, `year_level` = :yl, `semester` = :sem, `subject` = :subj,
                    `code` = :code, `instructor` = :inst, `days` = :days, `time` = :time,
                    `room` = :room, `capacity` = :cap, `section_id` = :sec_id, `professor_id` = :prof_id
                WHERE `id` = :id
            ");
            $stmt->execute([
                'prog'    => $program,
                'yl'      => $yearLevel,
                'sem'     => $semester,
                'subj'    => $subject,
                'code'    => $code,
                'inst'    => $instructor,
                'days'    => $days,
                'time'    => $time,
                'room'    => $room,
                'cap'     => $capacity,
                'sec_id'  => $sectionId,
                'prof_id' => $professorId,
                'id'      => $id
            ]);
            $msg = "Schedule slot '{$code}' updated successfully.";
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO `subject_sections` (`program`, `year_level`, `semester`, `subject`, `code`, `instructor`, `days`, `time`, `room`, `capacity`, `section_id`, `professor_id`)
                VALUES (:prog, :yl, :sem, :subj, :code, :inst, :days, :time, :room, :cap, :sec_id, :prof_id)
            ");
            $stmt->execute([
                'prog'    => $program,
                'yl'      => $yearLevel,
                'sem'     => $semester,
                'subj'    => $subject,
                'code'    => $code,
                'inst'    => $instructor,
                'days'    => $days,
                'time'    => $time,
                'room'    => $room,
                'cap'     => $capacity,
                'sec_id'  => $sectionId,
                'prof_id' => $professorId
            ]);
            $id = (int)$pdo->lastInsertId();
            $msg = "Schedule slot '{$code}' created successfully.";
        }

        return ['success' => true, 'message' => $msg, 'data' => ['id' => $id]];
    }
}
