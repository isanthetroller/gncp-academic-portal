<?php
/**
 * Scheduling Submodule — Scheduling & Enrollment Namespace
 */

function daysOverlap($days1, $days2) {
    $days1 = strtoupper(trim($days1));
    $days2 = strtoupper(trim($days2));
    if ($days1 === 'DAILY' || $days2 === 'DAILY') return true;
    
    $chars1 = str_split($days1);
    $chars2 = str_split($days2);
    $intersect = array_intersect($chars1, $chars2);
    return !empty($intersect);
}

function parseTimeRange($timeStr) {
    $parts = explode('-', $timeStr);
    if (count($parts) !== 2) return null;
    $start = strtotime(trim($parts[0]));
    $end = strtotime(trim($parts[1]));
    if (!$start || !$end) return null;
    return [$start, $end];
}

function detectScheduleCollision($pdo, $days, $time, $room, $instructor, $sectionId, $ignoreOfferingId = null) {
    $timeRange = parseTimeRange($time);
    if (!$timeRange) return null;

    $activePeriodId = null;
    if ($sectionId) {
        $stmtSec = $pdo->prepare("SELECT `academic_period_id` FROM `sections` WHERE `id` = :id LIMIT 1");
        $stmtSec->execute([':id' => (int)$sectionId]);
        $activePeriodId = $stmtSec->fetchColumn();
    }
    if (!$activePeriodId) {
        $activePeriodId = $pdo->query("SELECT `id` FROM `academic_periods` WHERE `status` = 'Active' LIMIT 1")->fetchColumn();
    }

    $query = "
        SELECT ss.*, s.academic_period_id 
        FROM `subject_sections` ss 
        LEFT JOIN `sections` s ON ss.section_id = s.id 
        WHERE (s.academic_period_id = :ap_id OR ss.section_id IS NULL)
    ";
    if ($ignoreOfferingId) {
        $query .= " AND ss.id != :ignore_id";
    }
    $stmt = $pdo->prepare($query);
    $params = ['ap_id' => $activePeriodId];
    if ($ignoreOfferingId) {
        $params['ignore_id'] = (int)$ignoreOfferingId;
    }
    $stmt->execute($params);
    $offerings = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($offerings as $off) {
        if (!daysOverlap($days, $off['days'])) {
            continue;
        }

        $offTimeRange = parseTimeRange($off['time']);
        if (!$offTimeRange) {
            continue;
        }

        if ($timeRange[0] < $offTimeRange[1] && $offTimeRange[0] < $timeRange[1]) {
            if ($instructor !== 'TBD' && $instructor !== '' && strcasecmp($instructor, $off['instructor']) === 0) {
                return [
                    'type' => 'Instructor Conflict',
                    'message' => "Instructor '$instructor' is already scheduled for class '{$off['code']}' ({$off['subject']}) during this slot."
                ];
            }
            if ($room !== 'TBD' && $room !== '' && strcasecmp($room, $off['room']) === 0) {
                return [
                    'type' => 'Room Conflict',
                    'message' => "Room '$room' is already occupied by class '{$off['code']}' ({$off['subject']}) during this slot."
                ];
            }
            if ($sectionId !== null && $off['section_id'] !== null && (int)$sectionId === (int)$off['section_id']) {
                return [
                    'type' => 'Section Overload',
                    'message' => "Section cohort is already scheduled for class '{$off['code']}' ({$off['subject']}) during this slot."
                ];
            }
        }
    }

    return null;
}

function resolveDefaultInstructor($subjectCode, $subjectTitle, $defaultFormInstructor) {
    $code = strtoupper($subjectCode);
    $title = strtoupper($subjectTitle);
    
    if (strpos($code, 'PE') !== false || strpos($title, 'PHYSICAL EDUCATION') !== false) {
        return 'Coach Carter';
    }
    if (strpos($code, 'NSTP') !== false || strpos($title, 'NATIONAL SERVICE') !== false) {
        return 'Major Payne';
    }
    if (strpos($code, 'GE101') !== false || strpos($title, 'MATHEMATICS') !== false) {
        return 'Dr. Pythagoras';
    }
    if (strpos($code, 'GE102') !== false || strpos($title, 'PURPOSIVE COMMUNICATION') !== false) {
        return 'Dr. Shakespeare';
    }
    if (strpos($code, 'GE103') !== false || strpos($title, 'UNDERSTANDING THE SELF') !== false) {
        return 'Dr. Freud';
    }
    if (strpos($code, 'COE102') !== false || strpos($title, 'COLLEGE ALGEBRA') !== false) {
        return 'Dr. Euler';
    }
    if (strpos($code, 'COE103') !== false || strpos($title, 'CHEMISTRY') !== false) {
        return 'Dr. Nobel';
    }
    if (strpos($code, 'IT101') !== false || strpos($code, 'CS101') !== false || strpos($title, 'INTRODUCTION TO COMPUTING') !== false) {
        if (strpos($title, '(CS)') !== false) return 'Prof. Alan Turing';
        return 'Prof. Steve Jobs';
    }
    if (strpos($code, 'IT102') !== false || strpos($code, 'CS102') !== false || strpos($title, 'COMPUTER PROGRAMMING') !== false) {
        if (strpos($title, '(CS)') !== false) return 'Prof. Grace Hopper';
        if (strpos($title, '(CPE)') !== false) return 'Prof. Steve Wozniak';
        return 'Prof. Dennis Ritchie';
    }
    
    return !empty($defaultFormInstructor) ? $defaultFormInstructor : 'TBD';
}

if ($action === 'save_subject_section') {
    $sect = json_decode(file_get_contents('php://input'), true)['section'] ?? null;
    if (!$sect) sendResponse(false, null, 'Section data missing.', 400);

    $secId = !empty($sect['sectionId']) ? (int)$sect['sectionId'] : null;
    $offId = !empty($sect['id']) ? (int)$sect['id'] : null;

    $collision = detectScheduleCollision(
        $pdo, 
        $sect['days'] ?? '', 
        $sect['time'] ?? '', 
        $sect['room'] ?? '', 
        $sect['instructor'] ?? '', 
        $secId, 
        $offId
    );
    if ($collision) {
        sendResponse(false, null, $collision['message'], 400);
    }

    $params = [
        'prog' => $sect['program'] ?? '',
        'yl'   => $sect['yearLevel'] ?? '',
        'sem'  => $sect['semester'] ?? '',
        'sub'  => $sect['subject'] ?? '',
        'code' => $sect['code'] ?? '',
        'inst' => $sect['instructor'] ?? 'TBD',
        'days' => $sect['days'] ?? '',
        'time' => $sect['time'] ?? '',
        'room' => $sect['room'] ?? '',
        'cap'  => (int)($sect['capacity'] ?? 0),
        'sec_id' => $secId
    ];
    if ($offId) {
        $params['id'] = $offId;
        $stmt = $pdo->prepare("UPDATE `subject_sections` SET `program`=:prog,`year_level`=:yl,`semester`=:sem,`subject`=:sub,`code`=:code,`instructor`=:inst,`days`=:days,`time`=:time,`room`=:room,`capacity`=:cap,`section_id`=:sec_id WHERE `id`=:id");
    } else {
        $stmt = $pdo->prepare("INSERT INTO `subject_sections`(`program`,`year_level`,`semester`,`subject`,`code`,`instructor`,`days`,`time`,`room`,`capacity`,`section_id`) VALUES(:prog,:yl,:sem,:sub,:code,:inst,:days,:time,:room,:cap,:sec_id)");
    }
    $stmt->execute($params);
    $rows = $pdo->query("SELECT * FROM `subject_sections` ORDER BY `id` DESC")->fetchAll();
    $out  = [];
    foreach ($rows as $r) {
        $out[] = [
            'id'         => (int)$r['id'],
            'program'    => $r['program'] ?? '',
            'yearLevel'  => $r['year_level'] ?? '',
            'semester'   => $r['semester'] ?? '',
            'subject'    => $r['subject'],
            'code'       => $r['code'],
            'instructor' => $r['instructor'],
            'days'       => $r['days'],
            'time'       => $r['time'],
            'room'       => $r['room'],
            'capacity'   => (int)$r['capacity'],
            'sectionId'  => $r['section_id'] !== null ? (int)$r['section_id'] : null
        ];
    }
    sendResponse(true, $out);

} elseif ($action === 'delete_subject_section') {
    $id = json_decode(file_get_contents('php://input'), true)['id'] ?? null;
    if ($id === null) sendResponse(false, null, 'ID missing.', 400);

    // Fetch the offering code before deleting for dependency checks
    $fetchSec = $pdo->prepare("SELECT `code` FROM `subject_sections` WHERE `id` = :id");
    $fetchSec->execute(['id' => (int)$id]);
    $secCode = $fetchSec->fetchColumn();

    if ($secCode) {
        // Check pre_enrollments for students assigned to this class offering
        $checkPre = $pdo->prepare("SELECT COUNT(*) FROM `pre_enrollments` WHERE `enrollment_data` LIKE :pattern AND `status` NOT IN ('CANCELLED','Rejected')");
        $checkPre->execute(['pattern' => '%"' . $secCode . '"%']);
        $preCount = (int)$checkPre->fetchColumn();

        // Check enrolled students with this section in their enrollment_data
        $checkStudents = $pdo->prepare("SELECT COUNT(*) FROM `students` WHERE `enrollment_data` LIKE :pattern AND `status` = 'Active'");
        $checkStudents->execute(['pattern' => '%"' . $secCode . '"%']);
        $studentCount = (int)$checkStudents->fetchColumn();

        $total = $preCount + $studentCount;
        if ($total > 0) {
            sendResponse(false, null, "Cannot delete class offering '{$secCode}': {$total} student(s) are currently assigned to it.", 400);
        }
    }

    $pdo->prepare("DELETE FROM `subject_sections` WHERE `id`=:id")->execute(['id'=>(int)$id]);
    $rows = $pdo->query("SELECT * FROM `subject_sections` ORDER BY `id` DESC")->fetchAll();
    $out  = [];
    foreach ($rows as $r) {
        $out[] = [
            'id'         => (int)$r['id'],
            'program'    => $r['program'] ?? '',
            'yearLevel'  => $r['year_level'] ?? '',
            'semester'   => $r['semester'] ?? '',
            'subject'    => $r['subject'],
            'code'       => $r['code'],
            'instructor' => $r['instructor'],
            'days'       => $r['days'],
            'time'       => $r['time'],
            'room'       => $r['room'],
            'capacity'   => (int)$r['capacity'],
            'sectionId'  => $r['section_id'] !== null ? (int)$r['section_id'] : null
        ];
    }
    sendResponse(true, $out);

} elseif ($action === 'save_block_section') {
    $block = json_decode(file_get_contents('php://input'), true)['block'] ?? null;
    if (!$block) sendResponse(false, null, 'Block section data missing.', 400);

    $program = trim($block['program'] ?? '');
    $yearLevel = trim($block['yearLevel'] ?? '');
    $semester = trim($block['semester'] ?? '');
    $sectionSuffix = strtoupper(trim($block['sectionSuffix'] ?? ''));
    $capacity = (int)($block['capacity'] ?? 40);
    $instructor = trim($block['instructor'] ?? 'TBD');
    $days = trim($block['days'] ?? 'MWF');
    $time = trim($block['time'] ?? '09:00 AM - 10:30 AM');
    $room = trim($block['room'] ?? 'Room 101');

    if (!$program || !$yearLevel || !$semester || !$sectionSuffix) {
        sendResponse(false, null, 'Program, year level, semester, and section suffix are required.', 400);
    }

    // Resolve active period
    $activePeriodId = (int)$pdo->query("SELECT `id` FROM `academic_periods` WHERE `status` = 'Active' LIMIT 1")->fetchColumn();
    if (!$activePeriodId) {
        sendResponse(false, null, 'No active academic period found. Please activate an academic period first.', 400);
    }

    // Find section ID
    $secStmt = $pdo->prepare("SELECT `id` FROM `sections` WHERE `program` = :prog AND `year_level` = :yl AND `academic_period_id` = :ap_id AND `code` = :code");
    $secStmt->execute([
        'prog'  => $program,
        'yl'    => $yearLevel,
        'ap_id' => $activePeriodId,
        'code'  => $sectionSuffix
    ]);
    $sectionId = $secStmt->fetchColumn();

    if (!$sectionId) {
        // Auto-create the section cohort block to match
        $currVersionQuery = $pdo->prepare("SELECT `curriculum_version` FROM `curriculum` WHERE `program` = :prog LIMIT 1");
        $currVersionQuery->execute(['prog' => $program]);
        $cv = $currVersionQuery->fetchColumn() ?: '2022 Curriculum';

        $insSec = $pdo->prepare("INSERT INTO `sections`(`code`,`program`,`year_level`,`academic_period_id`,`curriculum_version`,`capacity`) VALUES(:code,:prog,:yl,:ap_id,:cv,:cap)");
        $insSec->execute([
            'code'   => $sectionSuffix,
            'prog'   => $program,
            'yl'     => $yearLevel,
            'ap_id'  => $activePeriodId,
            'cv'     => $cv,
            'cap'    => $capacity
        ]);
        $sectionId = (int)$pdo->lastInsertId();
    }

    // Fetch Program Code
    $progStmt = $pdo->prepare("SELECT `code` FROM `programs` WHERE `name` = :name");
    $progStmt->execute(['name' => $program]);
    $progCode = $progStmt->fetchColumn();
    if (!$progCode) {
        $progCode = $program;
    }
    // Normalize program short code (e.g. BSIT -> IT, BSCS -> CS)
    $progShort = str_replace('BS', '', $progCode);

    // Fetch curriculum mapping for this program/year/semester
    $currStmt = $pdo->prepare("SELECT * FROM `curriculum` WHERE `program` = :prog AND `year_level` = :yl AND `semester` = :sem");
    $currStmt->execute([
        'prog' => $program,
        'yl'   => $yearLevel,
        'sem'  => $semester
    ]);
    $currSubjects = $currStmt->fetchAll();

    if (empty($currSubjects)) {
        sendResponse(false, null, 'No curriculum subjects mapped for this program, year, and semester.', 400);
    }

    $generatedCount = 0;
    $skippedCount = 0;

    // Staggering schedule builder calculations
    $offset = ord(strtoupper($sectionSuffix)) - ord('A');
    if ($offset < 0 || $offset > 25) $offset = 0;

    $mwRoom = 'Room ' . (201 + ($offset * 2));
    $tthRoom = 'Room ' . (202 + ($offset * 2));
    $labRoom = 'Lab ' . (1 + $offset);

    // Standard slots pool
    $slotsPool = [
        ['days' => 'MW', 'time' => '08:00 AM - 09:30 AM', 'room' => $mwRoom],
        ['days' => 'MW', 'time' => '09:00 AM - 10:30 AM', 'room' => $mwRoom],
        ['days' => 'MW', 'time' => '10:30 AM - 12:00 PM', 'room' => $mwRoom],
        ['days' => 'TTH', 'time' => '08:00 AM - 09:30 AM', 'room' => $tthRoom],
        ['days' => 'TTH', 'time' => '10:30 AM - 12:00 PM', 'room' => $tthRoom],
        ['days' => 'TTH', 'time' => '01:00 PM - 02:30 PM', 'room' => $tthRoom],
        ['days' => 'TTH', 'time' => '02:30 PM - 04:00 PM', 'room' => $tthRoom]
    ];

    $peTime = '08:00 AM - 10:00 AM';
    if ($offset === 1) $peTime = '10:00 AM - 12:00 PM';
    elseif ($offset === 2) $peTime = '02:00 PM - 04:00 PM';
    elseif ($offset > 2) $peTime = '04:00 PM - 06:00 PM';

    $slotIndex = 0;

    foreach ($currSubjects as $cs) {
        $subjectName = $cs['subject'];
        
        // Find subject code and lab units
        $subStmt = $pdo->prepare("SELECT `code`, `lab_units` FROM `subjects` WHERE `title` = :title OR `code` = :code");
        $subStmt->execute(['title' => $subjectName, 'code' => $subjectName]);
        $subRow = $subStmt->fetch(PDO::FETCH_ASSOC);
        
        $subCode = $subRow['code'] ?? 'SUB';
        $labUnits = (int)($subRow['lab_units'] ?? 0);

        // Construct section code: e.g. IT-IT101-A
        $sectionCode = $progShort . '-' . $subCode . '-' . $sectionSuffix;

        // Check if section code already exists
        $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM `subject_sections` WHERE `code` = :code");
        $checkStmt->execute(['code' => $sectionCode]);
        if ($checkStmt->fetchColumn() > 0) {
            $skippedCount++;
            continue;
        }

        // Resolve default instructor for this subject
        $resolvedInst = resolveDefaultInstructor($subCode, $subjectName, $instructor);

        // Determine schedule stagger
        $subjUpper = strtoupper($subjectName);
        if (strpos($subCode, 'PE') !== false || strpos($subjUpper, 'PE 1') !== false || strpos($subjUpper, 'PHYSICAL EDUCATION') !== false) {
            $subjDays = 'F';
            $subjTime = $peTime;
            $subjRoom = 'Gymnasium';
        } elseif (strpos($subCode, 'NSTP') !== false || strpos($subjUpper, 'NSTP 1') !== false || strpos($subjUpper, 'NATIONAL SERVICE') !== false) {
            $subjDays = 'S';
            $subjTime = '08:00 AM - 11:00 AM';
            $subjRoom = 'Quadrangle';
        } else {
            // Assign standard slot from the pool
            $poolSlot = $slotsPool[$slotIndex % count($slotsPool)];
            $subjDays = $poolSlot['days'];
            $subjTime = $poolSlot['time'];
            // If has lab units, override with lab room
            $subjRoom = ($labUnits > 0) ? $labRoom : $poolSlot['room'];
            $slotIndex++;
        }

        // Insert new subject section (Class Offering)
        $insertStmt = $pdo->prepare("INSERT INTO `subject_sections` (`program`, `year_level`, `semester`, `subject`, `code`, `instructor`, `days`, `time`, `room`, `capacity`, `section_id`) 
                                     VALUES (:prog, :yl, :sem, :sub, :code, :inst, :days, :time, :room, :cap, :sec_id)");
        $insertStmt->execute([
            'prog'   => $program,
            'yl'     => $yearLevel,
            'sem'    => $semester,
            'sub'    => $subjectName,
            'code'   => $sectionCode,
            'inst'   => $resolvedInst,
            'days'   => $subjDays,
            'time'   => $subjTime,
            'room'   => $subjRoom,
            'cap'    => $capacity,
            'sec_id' => $sectionId
        ]);
        $generatedCount++;
    }

    // Return updated list of class offerings
    $rows = $pdo->query("SELECT * FROM `subject_sections` ORDER BY `id` DESC")->fetchAll();
    $out  = [];
    foreach ($rows as $r) {
        $out[] = [
            'id'         => (int)$r['id'],
            'program'    => $r['program'] ?? '',
            'yearLevel'  => $r['year_level'] ?? '',
            'semester'   => $r['semester'] ?? '',
            'subject'    => $r['subject'],
            'code'       => $r['code'],
            'instructor' => $r['instructor'],
            'days'       => $r['days'],
            'time'       => $r['time'],
            'room'       => $r['room'],
            'capacity'   => (int)$r['capacity'],
            'sectionId'  => $r['section_id'] !== null ? (int)$r['section_id'] : null
        ];
    }
    sendResponse(true, $out, "Generated {$generatedCount} sections successfully (skipped {$skippedCount} existing).");
}
