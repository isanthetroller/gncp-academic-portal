<?php
/**
 * GNCP Academic Portal — Centralized Schedule Conflict Service
 * Authoritative backend conflict validation engine for academic scheduling,
 * operating windows (7:00 AM - 7:00 PM), lunch blackout (12:00 PM - 1:00 PM),
 * section shift indicators (M/A/E), room occupancy, and professor availability.
 */

require_once __DIR__ . '/../config/database.php';

class ScheduleConflictService {

    const INSTITUTIONAL_START = '07:00:00';
    const INSTITUTIONAL_END   = '19:00:00';
    const LUNCH_START          = '12:00:00';
    const LUNCH_END            = '13:00:00';

    /**
     * Parse day strings into normalized day tokens.
     * Supports: MW, TTH, MWF, FS, S, M, T, W, TH, F, DAILY
     */
    public static function parseDays(string $daysStr): array {
        $daysStr = strtoupper(trim($daysStr));
        if (empty($daysStr)) return [];
        if ($daysStr === 'DAILY') {
            return ['M', 'T', 'W', 'TH', 'F', 'S'];
        }

        $days = [];
        $len = strlen($daysStr);
        $i = 0;
        while ($i < $len) {
            if ($i + 1 < $len && substr($daysStr, $i, 2) === 'TH') {
                $days[] = 'TH';
                $i += 2;
            } else {
                $days[] = substr($daysStr, $i, 1);
                $i += 1;
            }
        }
        return array_unique($days);
    }

    /**
     * Check if two day specifications overlap
     */
    public static function daysOverlap(?string $days1, ?string $days2): bool {
        if (!$days1 || !$days2) return false;
        $d1 = strtoupper(trim($days1));
        $d2 = strtoupper(trim($days2));
        if ($d1 === 'DAILY' || $d2 === 'DAILY') return true;

        $tokens1 = self::parseDays($d1);
        $tokens2 = self::parseDays($d2);

        $common = array_intersect($tokens1, $tokens2);
        return !empty($common);
    }

    /**
     * Parse time strings like "09:00 AM - 10:30 AM" or "09:00-10:30"
     * Returns array with:
     *   'start'    => seconds from midnight (0 to 86400)
     *   'end'      => seconds from midnight
     *   'startStr' => 'HH:MM:SS'
     *   'endStr'   => 'HH:MM:SS'
     */
    public static function parseTimeRange(?string $timeStr): ?array {
        if (!$timeStr) return null;
        $parts = explode('-', $timeStr);
        if (count($parts) !== 2) return null;

        $rawStart = trim($parts[0]);
        $rawEnd   = trim($parts[1]);

        $startTs = strtotime($rawStart);
        $endTs   = strtotime($rawEnd);

        if (!$startTs || !$endTs) return null;

        $startHour = (int)date('H', $startTs);
        $startMin  = (int)date('i', $startTs);
        $startSec  = (int)date('s', $startTs);
        $startTotalSec = ($startHour * 3600) + ($startMin * 60) + $startSec;

        $endHour = (int)date('H', $endTs);
        $endMin  = (int)date('i', $endTs);
        $endSec  = (int)date('s', $endTs);
        $endTotalSec = ($endHour * 3600) + ($endMin * 60) + $endSec;

        if ($endTotalSec <= $startTotalSec) {
            return null; // Invalid inverted or zero-length range
        }

        return [
            'start'    => $startTotalSec,
            'end'      => $endTotalSec,
            'startStr' => sprintf('%02d:%02d:%02d', $startHour, $startMin, $startSec),
            'endStr'   => sprintf('%02d:%02d:%02d', $endHour, $endMin, $endSec),
            'rawStart' => date('g:i A', $startTs),
            'rawEnd'   => date('g:i A', $endTs)
        ];
    }

    /**
     * Validates that the time falls within the 7:00 AM - 7:00 PM operating window
     */
    public static function validateInstitutionalOperatingWindow(int $startSec, int $endSec): ?array {
        $instStartSec = 7 * 3600;   // 07:00:00
        $instEndSec   = 19 * 3600;  // 19:00:00

        if ($startSec < $instStartSec) {
            return [
                'valid' => false,
                'type'  => 'Window Violation',
                'message' => 'Class cannot start before the institutional operating window (7:00 AM).'
            ];
        }

        if ($endSec > $instEndSec) {
            return [
                'valid' => false,
                'type'  => 'Window Violation',
                'message' => 'Class cannot extend past the institutional operating window (7:00 PM).'
            ];
        }

        return ['valid' => true];
    }

    /**
     * Validates that class schedule does not cross or overlap the 12:00 PM - 1:00 PM lunch blackout
     */
    public static function validateLunchBlackout(int $startSec, int $endSec): ?array {
        $lunchStartSec = 12 * 3600; // 12:00:00
        $lunchEndSec   = 13 * 3600; // 13:00:00

        // Overlap condition: start < lunchEnd AND lunchStart < end
        if ($startSec < $lunchEndSec && $lunchStartSec < $endSec) {
            return [
                'valid'   => false,
                'type'    => 'Lunch Conflict',
                'message' => 'Class schedule overlaps the institutional lunch period (12:00 PM – 1:00 PM), which is unavailable for class scheduling.'
            ];
        }

        return ['valid' => true];
    }

    /**
     * Validates section cohort shift indicator rules (M = Morning, A = Afternoon, E = Evening)
     */
    public static function validateSectionShift(?string $sectionCode, int $startSec, int $endSec): ?array {
        if (!$sectionCode) return ['valid' => true];

        $code = strtoupper(trim($sectionCode));
        $morningLimit = 12 * 3600;  // 12:00 PM
        $afternoonStart = 13 * 3600; // 1:00 PM
        $eveningStart   = 17 * 3600; // 5:00 PM

        // Extract trailing shift indicator (e.g. BSIT-1M, 1M, BSIT-1A, 1A, BSIT-1E, 1E)
        $isMorning = (bool)preg_match('/(^|[-_ ])[1-4]?M($|[-_ ])/i', $code) || str_ends_with($code, 'M');
        $isAfternoon = (bool)preg_match('/(^|[-_ ])[1-4]?A($|[-_ ])/i', $code) || str_ends_with($code, 'A');
        $isEvening = (bool)preg_match('/(^|[-_ ])[1-4]?E($|[-_ ])/i', $code) || str_ends_with($code, 'E');

        // Only enforce if explicitly matched
        if ($isMorning && !$isAfternoon && !$isEvening) {
            if ($endSec > $morningLimit) {
                return [
                    'valid'   => false,
                    'type'    => 'Shift Mismatch',
                    'message' => "Morning cohort section '{$sectionCode}' requires classes between 7:00 AM and 12:00 PM."
                ];
            }
        } elseif ($isAfternoon && !$isMorning && !$isEvening) {
            if ($startSec < $afternoonStart) {
                return [
                    'valid'   => false,
                    'type'    => 'Shift Mismatch',
                    'message' => "Afternoon cohort section '{$sectionCode}' requires classes between 1:00 PM and 7:00 PM."
                ];
            }
        } elseif ($isEvening) {
            if ($startSec < $eveningStart) {
                return [
                    'valid'   => false,
                    'type'    => 'Shift Mismatch',
                    'message' => "Evening cohort section '{$sectionCode}' requires classes between 5:00 PM and 7:00 PM."
                ];
            }
        }

        return ['valid' => true];
    }

    /**
     * Check explicit professor availability from professor_availability table
     */
    public static function checkProfessorExplicitAvailability(PDO $pdo, int $professorId, string $daysStr, int $startSec, int $endSec): ?array {
        $days = self::parseDays($daysStr);
        if (empty($days)) return ['valid' => true];

        $dayMap = [
            'M'  => 'Monday',
            'T'  => 'Tuesday',
            'W'  => 'Wednesday',
            'TH' => 'Thursday',
            'F'  => 'Friday',
            'S'  => 'Saturday'
        ];

        $dayNames = [];
        foreach ($days as $d) {
            if (isset($dayMap[$d])) $dayNames[] = $dayMap[$d];
        }

        if (empty($dayNames)) return ['valid' => true];

        $inClause = implode(',', array_fill(0, count($dayNames), '?'));
        $stmt = $pdo->prepare("
            SELECT * FROM `professor_availability` 
            WHERE `professor_id` = ? AND `day_of_week` IN ({$inClause}) AND `is_available` = 0
        ");
        $params = array_merge([$professorId], $dayNames);
        $stmt->execute($params);
        $unavailBlocks = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($unavailBlocks as $block) {
            $bStartTs = strtotime($block['start_time']);
            $bEndTs   = strtotime($block['end_time']);
            if (!$bStartTs || !$bEndTs) continue;

            $bStartSec = (int)date('H', $bStartTs) * 3600 + (int)date('i', $bStartTs) * 60;
            $bEndSec   = (int)date('H', $bEndTs) * 3600 + (int)date('i', $bEndTs) * 60;

            if ($startSec < $bEndSec && $bStartSec < $endSec) {
                return [
                    'valid'   => false,
                    'type'    => 'Professor Unavailable',
                    'message' => "Professor has logged an unavailability block on {$block['day_of_week']} between " . date('g:i A', $bStartTs) . " and " . date('g:i A', $bEndTs) . (!empty($block['notes']) ? " ({$block['notes']})" : "") . "."
                ];
            }
        }

        return ['valid' => true];
    }

    /**
     * Check professor active status and existence
     */
    public static function checkProfessorStatus(PDO $pdo, int $professorId): ?array {
        $stmt = $pdo->prepare("
            SELECT p.*, u.status as user_status 
            FROM `professors` p 
            JOIN `station_users` u ON p.user_id = u.id 
            WHERE p.id = :id 
            LIMIT 1
        ");
        $stmt->execute(['id' => $professorId]);
        $prof = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$prof) {
            return [
                'valid'   => false,
                'type'    => 'Not Found',
                'message' => 'Professor account not found.'
            ];
        }

        $profStatus = strtoupper($prof['status'] ?? 'ACTIVE');
        $userStatus = strtoupper($prof['user_status'] ?? 'ACTIVE');

        if ($profStatus !== 'ACTIVE' || $userStatus !== 'ACTIVE') {
            return [
                'valid'   => false,
                'type'    => 'Professor Inactive',
                'message' => "Professor '{$prof['name']}' is currently inactive or deactivated and cannot be assigned to classes."
            ];
        }

        return ['valid' => true, 'professor' => $prof];
    }

    /**
     * Authoritative full conflict validation for assigning a professor to a subject section
     */
    public static function validateAssignment(PDO $pdo, int $professorId, int $subjectSectionId, ?int $academicPeriodId = null): array {
        // 1. Fetch Subject Section
        $ssStmt = $pdo->prepare("
            SELECT ss.*, s.academic_period_id as sec_period_id, s.code as sec_code 
            FROM `subject_sections` ss 
            LEFT JOIN `sections` s ON ss.section_id = s.id 
            WHERE ss.id = :id 
            LIMIT 1
        ");
        $ssStmt->execute(['id' => $subjectSectionId]);
        $ss = $ssStmt->fetch(PDO::FETCH_ASSOC);

        if (!$ss) {
            return ['valid' => false, 'type' => 'Not Found', 'message' => 'Subject offering slot not found.'];
        }

        $timeRange = self::parseTimeRange($ss['time']);
        if (!$timeRange) {
            return ['valid' => false, 'type' => 'Invalid Time', 'message' => "Class has an invalid or unset schedule time: '{$ss['time']}'."];
        }

        // 2. Validate institutional window & lunch blackout
        $winCheck = self::validateInstitutionalOperatingWindow($timeRange['start'], $timeRange['end']);
        if (!$winCheck['valid']) return $winCheck;

        $lunchCheck = self::validateLunchBlackout($timeRange['start'], $timeRange['end']);
        if (!$lunchCheck['valid']) return $lunchCheck;

        // 3. Validate professor status
        $statusCheck = self::checkProfessorStatus($pdo, $professorId);
        if (!$statusCheck['valid']) return $statusCheck;
        $professor = $statusCheck['professor'];

        // 4. Validate professor explicit availability
        $availCheck = self::checkProfessorExplicitAvailability($pdo, $professorId, $ss['days'], $timeRange['start'], $timeRange['end']);
        if (!$availCheck['valid']) return $availCheck;

        // 5. Determine active academic period
        $periodId = $academicPeriodId ?: ($ss['sec_period_id'] ?: null);
        if (!$periodId) {
            $periodId = $pdo->query("SELECT id FROM `academic_periods` WHERE `status` = 'Active' LIMIT 1")->fetchColumn();
        }

        // 6. Check for overlapping classes already assigned to this professor
        $overlapQuery = "
            SELECT ss.*, s.code as section_code 
            FROM `subject_sections` ss 
            LEFT JOIN `sections` s ON ss.section_id = s.id 
            WHERE (ss.professor_id = :prof_id OR LOWER(ss.instructor) = LOWER(:prof_name))
              AND ss.id != :current_id
        ";
        if ($periodId) {
            $overlapQuery .= " AND (s.academic_period_id = :period_id OR s.academic_period_id IS NULL)";
        }

        $overlapStmt = $pdo->prepare($overlapQuery);
        $params = [
            'prof_id'    => $professorId,
            'prof_name'  => $professor['name'],
            'current_id' => $subjectSectionId
        ];
        if ($periodId) {
            $params['period_id'] = (int)$periodId;
        }
        $overlapStmt->execute($params);
        $assignedClasses = $overlapStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($assignedClasses as $ac) {
            if (!self::daysOverlap($ss['days'], $ac['days'])) {
                continue;
            }

            $acRange = self::parseTimeRange($ac['time']);
            if (!$acRange) continue;

            // Strict overlap check: start1 < end2 AND start2 < end1
            // Exact adjacent boundaries (e.g. 7:00-9:00 and 9:00-11:00) are allowed because 9:00 < 9:00 is false.
            if ($timeRange['start'] < $acRange['end'] && $acRange['start'] < $timeRange['end']) {
                return [
                    'valid'   => false,
                    'type'    => 'Schedule Conflict',
                    'message' => "Professor '{$professor['name']}' cannot be assigned because this schedule overlaps with another assigned class '{$ac['code']}' ({$ac['subject']}) on {$ac['days']} {$ac['time']}."
                ];
            }
        }

        // 7. Department compatibility assessment (advisory / informational)
        $subDeptStmt = $pdo->prepare("SELECT department FROM `subjects` WHERE code = :code OR title = :title LIMIT 1");
        $subDeptStmt->execute(['code' => $ss['code'], 'title' => $ss['subject']]);
        $subjectDept = $subDeptStmt->fetchColumn();

        $deptMatch = true;
        if ($subjectDept) {
            $profDeptStmt = $pdo->prepare("SELECT code, name FROM `departments` WHERE id = :id LIMIT 1");
            $profDeptStmt->execute(['id' => $professor['department_id']]);
            $profDept = $profDeptStmt->fetch(PDO::FETCH_ASSOC);

            if ($profDept) {
                $deptMatch = (strcasecmp($subjectDept, $profDept['name']) === 0 || strcasecmp($subjectDept, $profDept['code']) === 0);
            }
        }

        return [
            'valid'           => true,
            'deptMatch'       => $deptMatch,
            'professorName'   => $professor['name'],
            'professorEmpId'  => $professor['employee_id'],
            'message'         => 'Schedule slot validated successfully with no conflicts.'
        ];
    }

    /**
     * Authoritative full validation for modifying or adding a subject_section slot
     */
    public static function validateSlot(PDO $pdo, array $slotData): array {
        $days = trim($slotData['days'] ?? '');
        $time = trim($slotData['time'] ?? '');
        $room = trim($slotData['room'] ?? 'TBA');
        $sectionId = !empty($slotData['sectionId']) ? (int)$slotData['sectionId'] : (!empty($slotData['section_id']) ? (int)$slotData['section_id'] : null);
        $sectionCode = trim($slotData['sectionCode'] ?? ($slotData['section_code'] ?? ''));
        $professorId = !empty($slotData['professorId']) ? (int)$slotData['professorId'] : (!empty($slotData['professor_id']) ? (int)$slotData['professor_id'] : null);
        $ignoreId = !empty($slotData['id']) ? (int)$slotData['id'] : null;

        if (empty($days) || empty($time)) {
            return ['valid' => false, 'type' => 'Missing Fields', 'message' => 'Days and Time range are required for class schedule slots.'];
        }

        $timeRange = self::parseTimeRange($time);
        if (!$timeRange) {
            return ['valid' => false, 'type' => 'Invalid Time', 'message' => "Time format must be 'HH:MM AM - HH:MM PM' (e.g. '09:00 AM - 10:30 AM')."];
        }

        // 1. Operating window
        $winCheck = self::validateInstitutionalOperatingWindow($timeRange['start'], $timeRange['end']);
        if (!$winCheck['valid']) return $winCheck;

        // 2. Lunch blackout
        $lunchCheck = self::validateLunchBlackout($timeRange['start'], $timeRange['end']);
        if (!$lunchCheck['valid']) return $lunchCheck;

        // 3. Shift M/A/E indicator
        if ($sectionCode) {
            $shiftCheck = self::validateSectionShift($sectionCode, $timeRange['start'], $timeRange['end']);
            if (!$shiftCheck['valid']) return $shiftCheck;
        }

        // 4. Active Academic Period
        $activePeriodId = $pdo->query("SELECT id FROM `academic_periods` WHERE `status` = 'Active' LIMIT 1")->fetchColumn();

        // 5. Section cohort overlap
        if ($sectionId) {
            $secOverlapStmt = $pdo->prepare("
                SELECT ss.* FROM `subject_sections` ss 
                WHERE ss.section_id = :sec_id " . ($ignoreId ? "AND ss.id != :ign_id" : "")
            );
            $secParams = ['sec_id' => $sectionId];
            if ($ignoreId) $secParams['ign_id'] = $ignoreId;
            $secOverlapStmt->execute($secParams);
            $secClasses = $secOverlapStmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($secClasses as $sc) {
                if (!self::daysOverlap($days, $sc['days'])) continue;
                $scRange = self::parseTimeRange($sc['time']);
                if (!$scRange) continue;

                if ($timeRange['start'] < $scRange['end'] && $scRange['start'] < $timeRange['end']) {
                    return [
                        'valid'   => false,
                        'type'    => 'Section Conflict',
                        'message' => "Section cohort is already scheduled for '{$sc['code']}' ({$sc['subject']}) on {$sc['days']} {$sc['time']}."
                    ];
                }
            }
        }

        // 6. Room overlap (ignore 'TBA' and 'ONLINE')
        $normRoom = strtoupper(trim($room));
        if ($normRoom && $normRoom !== 'TBA' && $normRoom !== 'ONLINE') {
            $roomQuery = "
                SELECT ss.*, s.code as sec_name 
                FROM `subject_sections` ss 
                LEFT JOIN `sections` s ON ss.section_id = s.id 
                WHERE UPPER(TRIM(ss.room)) = :rm " . ($ignoreId ? "AND ss.id != :ign_id" : "");
            if ($activePeriodId) {
                $roomQuery .= " AND (s.academic_period_id = :ap_id OR s.academic_period_id IS NULL)";
            }
            $roomStmt = $pdo->prepare($roomQuery);
            $rParams = ['rm' => $normRoom];
            if ($ignoreId) $rParams['ign_id'] = $ignoreId;
            if ($activePeriodId) $rParams['ap_id'] = (int)$activePeriodId;
            $roomStmt->execute($rParams);
            $roomClasses = $roomStmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($roomClasses as $rc) {
                if (!self::daysOverlap($days, $rc['days'])) continue;
                $rcRange = self::parseTimeRange($rc['time']);
                if (!$rcRange) continue;

                if ($timeRange['start'] < $rcRange['end'] && $rcRange['start'] < $timeRange['end']) {
                    return [
                        'valid'   => false,
                        'type'    => 'Room Conflict',
                        'message' => "Room '{$room}' is already occupied by '{$rc['code']}' ({$rc['subject']}) on {$rc['days']} {$rc['time']}."
                    ];
                }
            }
        }

        // 7. Professor conflict if professor assigned
        if ($professorId) {
            $profStatus = self::checkProfessorStatus($pdo, $professorId);
            if (!$profStatus['valid']) return $profStatus;

            $profAvail = self::checkProfessorExplicitAvailability($pdo, $professorId, $days, $timeRange['start'], $timeRange['end']);
            if (!$profAvail['valid']) return $profAvail;

            $profOverlapQuery = "
                SELECT ss.* FROM `subject_sections` ss 
                LEFT JOIN `sections` s ON ss.section_id = s.id 
                WHERE ss.professor_id = :pid " . ($ignoreId ? "AND ss.id != :ign_id" : "");
            if ($activePeriodId) {
                $profOverlapQuery .= " AND (s.academic_period_id = :ap_id OR s.academic_period_id IS NULL)";
            }
            $poStmt = $pdo->prepare($profOverlapQuery);
            $pParams = ['pid' => $professorId];
            if ($ignoreId) $pParams['ign_id'] = $ignoreId;
            if ($activePeriodId) $pParams['ap_id'] = (int)$activePeriodId;
            $poStmt->execute($pParams);
            $poClasses = $poStmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($poClasses as $poc) {
                if (!self::daysOverlap($days, $poc['days'])) continue;
                $pocRange = self::parseTimeRange($poc['time']);
                if (!$pocRange) continue;

                if ($timeRange['start'] < $pocRange['end'] && $pocRange['start'] < $timeRange['end']) {
                    return [
                        'valid'   => false,
                        'type'    => 'Professor Conflict',
                        'message' => "Professor '{$profStatus['professor']['name']}' is already assigned to '{$poc['code']}' ({$poc['subject']}) on {$poc['days']} {$poc['time']}."
                    ];
                }
            }
        }

        return ['valid' => true, 'message' => 'Schedule slot is conflict-free and valid.'];
    }
}
