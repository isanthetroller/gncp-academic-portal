<?php
/**
 * Term Submodule — Term Setup Namespace
 */

if ($action === 'save_academic_period') {
    $period = json_decode(file_get_contents('php://input'), true)['period'] ?? null;
    if (!$period) sendResponse(false, null, 'Period data missing.', 400);

    $params = [
        'name'   => trim($period['name']),
        'ay'     => trim($period['academicYear']),
        'sem'    => trim($period['semester']),
        'start'  => $period['enrollmentStart'] ?: null,
        'end'    => $period['enrollmentEnd'] ?: null,
        'status' => $period['status'] ?? 'Inactive'
    ];

    if ($params['status'] === 'Active') {
        // Automatically deactivate all other periods and transition all Active students to Inactive for semester rollover
        $pdo->query("UPDATE `academic_periods` SET `status` = 'Inactive'");
        $pdo->query("UPDATE `students` SET `status` = 'Inactive' WHERE `status` = 'Active'");
    }

    if (!empty($period['id'])) {
        $params['id'] = (int)$period['id'];
        $stmt = $pdo->prepare("UPDATE `academic_periods` SET `name`=:name,`academic_year`=:ay,`semester`=:sem,`enrollment_start`=:start,`enrollment_end`=:end,`status`=:status WHERE `id`=:id");
    } else {
        $stmt = $pdo->prepare("INSERT INTO `academic_periods`(`name`,`academic_year`,`semester`,`enrollment_start`,`enrollment_end`,`status`) VALUES(:name,:ay,:sem,:start,:end,:status)");
    }
    $stmt->execute($params);

    $apRaw = $pdo->query("SELECT * FROM `academic_periods` ORDER BY `id` DESC")->fetchAll();
    $out   = [];
    foreach ($apRaw as $r) {
        $out[] = [
            'id'              => (int)$r['id'],
            'name'            => $r['name'],
            'academicYear'    => $r['academic_year'],
            'semester'        => $r['semester'],
            'enrollmentStart' => $r['enrollment_start'],
            'enrollmentEnd'   => $r['enrollment_end'],
            'status'          => $r['status']
        ];
    }
    sendResponse(true, $out);

} elseif ($action === 'delete_academic_period') {
    $id = json_decode(file_get_contents('php://input'), true)['id'] ?? null;
    if ($id === null) sendResponse(false, null, 'ID missing.', 400);

    // Check for sections tied to this academic period
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM `sections` WHERE `academic_period_id` = :id");
    $stmt->execute(['id' => (int)$id]);
    $sectionCount = (int)$stmt->fetchColumn();

    if ($sectionCount > 0) {
        // Check deeper: do any of those sections have class offerings or assigned students?
        $stmt2 = $pdo->prepare("SELECT COUNT(*) FROM `subject_sections` WHERE `section_id` IN (SELECT `id` FROM `sections` WHERE `academic_period_id` = :id)");
        $stmt2->execute(['id' => (int)$id]);
        $offeringCount = (int)$stmt2->fetchColumn();

        if ($offeringCount > 0) {
            sendResponse(false, null, "Cannot delete period: it has {$sectionCount} section(s) with {$offeringCount} active class offering(s). Remove class offerings and sections first.", 400);
        }

        sendResponse(false, null, "Cannot delete period: {$sectionCount} section cohort(s) are still linked to it. Delete those sections first.", 400);
    }

    $pdo->prepare("DELETE FROM `academic_periods` WHERE `id`=:id")->execute(['id'=>(int)$id]);
    $apRaw = $pdo->query("SELECT * FROM `academic_periods` ORDER BY `id` DESC")->fetchAll();
    $out   = [];
    foreach ($apRaw as $r) {
        $out[] = [
            'id'              => (int)$r['id'],
            'name'            => $r['name'],
            'academicYear'    => $r['academic_year'],
            'semester'        => $r['semester'],
            'enrollmentStart' => $r['enrollment_start'],
            'enrollmentEnd'   => $r['enrollment_end'],
            'status'          => $r['status']
        ];
    }
    sendResponse(true, $out);

} elseif ($action === 'save_section') {
    $sec = json_decode(file_get_contents('php://input'), true)['section'] ?? null;
    if (!$sec) sendResponse(false, null, 'Section data missing.', 400);

    $code  = trim($sec['code'] ?? '');
    $prog  = trim($sec['program'] ?? '');
    $yl    = trim($sec['yearLevel'] ?? '');
    $ap_id = (int)($sec['academicPeriodId'] ?? 0);
    if ($ap_id <= 0) {
        $activePer = $pdo->query("SELECT id FROM academic_periods WHERE status = 'Active' LIMIT 1")->fetch();
        if ($activePer) {
            $ap_id = (int)$activePer['id'];
        }
    }
    $curr_v = trim($sec['curriculumVersion'] ?? '2022 Curriculum');
    $cap   = (int)($sec['capacity'] ?? 40);
    $adv   = isset($sec['adviser']) && trim($sec['adviser']) !== '' ? trim($sec['adviser']) : null;

    if (!$code || !$prog || !$yl || !$ap_id) {
        sendResponse(false, null, 'Program, Year Level, Period, and Section Code are required.', 400);
    }

    // Verify academic period exists and is Active
    $perCheck = $pdo->prepare("SELECT `status` FROM `academic_periods` WHERE `id` = :id");
    $perCheck->execute(['id' => $ap_id]);
    $perStatus = $perCheck->fetchColumn();
    if (!$perStatus || strtoupper($perStatus) !== 'ACTIVE') {
        sendResponse(false, null, 'Cannot create or assign sections to an Inactive academic period. Please activate the term under Enrollment Semesters first.', 400);
    }

    // Uniqueness check: Program + Year Level + Academic Period + Code
    $checkStmt = $pdo->prepare("SELECT `id` FROM `sections` WHERE `code` = :code AND `program` = :prog AND `year_level` = :yl AND `academic_period_id` = :ap_id");
    $checkStmt->execute(['code' => $code, 'prog' => $prog, 'yl' => $yl, 'ap_id' => $ap_id]);
    $existing = $checkStmt->fetch();
    if ($existing && (empty($sec['id']) || (int)$existing['id'] !== (int)$sec['id'])) {
        sendResponse(false, null, "A section with code '$code' already exists for this Program, Year Level, and Academic Period.", 400);
    }

    $params = [
        'code'   => $code,
        'prog'   => $prog,
        'yl'     => $yl,
        'ap_id'  => $ap_id,
        'curr_v' => $curr_v,
        'cap'    => $cap,
        'adv'    => $adv
    ];
    if (!empty($sec['id'])) {
        $params['id'] = (int)$sec['id'];
        $stmt = $pdo->prepare("UPDATE `sections` SET `code`=:code,`program`=:prog,`year_level`=:yl,`academic_period_id`=:ap_id,`curriculum_version`=:curr_v,`capacity`=:cap,`adviser`=:adv WHERE `id`=:id");
    } else {
        $stmt = $pdo->prepare("INSERT INTO `sections`(`code`,`program`,`year_level`,`academic_period_id`,`curriculum_version`,`capacity`,`adviser`) VALUES(:code,:prog,:yl,:ap_id,:curr_v,:cap,:adv)");
    }
    $stmt->execute($params);
    $rows = $pdo->query("SELECT * FROM `sections` ORDER BY `id` DESC")->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'id'               => (int)$r['id'],
            'code'             => $r['code'],
            'program'          => $r['program'],
            'yearLevel'        => $r['year_level'],
            'academicPeriodId' => (int)$r['academic_period_id'],
            'curriculumVersion'=> $r['curriculum_version'] ?? '2022 Curriculum',
            'capacity'         => (int)$r['capacity'],
            'adviser'          => $r['adviser'] ?? ''
        ];
    }
    sendResponse(true, $out);

} elseif ($action === 'delete_section') {
    $id = json_decode(file_get_contents('php://input'), true)['id'] ?? null;
    if ($id === null) sendResponse(false, null, 'ID missing.', 400);

    // Fetch section details for downstream checks
    $stmt = $pdo->prepare("SELECT * FROM `sections` WHERE `id` = :id");
    $stmt->execute(['id' => (int)$id]);
    $section = $stmt->fetch();
    if (!$section) sendResponse(false, null, 'Section not found.', 404);

    $sectionCode = $section['code'];
    $sectionProgram = $section['program'];

    // Check for class offerings tied to this section
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM `subject_sections` WHERE `section_id` = :id");
    $stmt->execute(['id' => (int)$id]);
    $offeringCount = (int)$stmt->fetchColumn();

    if ($offeringCount > 0) {
        sendResponse(false, null, "Cannot delete section: {$offeringCount} class offering(s) are still scheduled under it. Remove those offerings first.", 400);
    }

    // Check for pre-enrollments assigned to this section code
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM `pre_enrollments` WHERE `section_code` = :code AND `status` NOT IN ('Rejected','PRE_REGISTERED')");
    $stmt->execute(['code' => $sectionCode]);
    $preEnrollCount = (int)$stmt->fetchColumn();

    // Check for students assigned to this section
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM `students` WHERE `program` = :prog AND (JSON_UNQUOTE(JSON_EXTRACT(`enrollment_data`, '$.assignedSection')) = :code)");
    $stmt->execute(['prog' => $sectionProgram, 'code' => $sectionCode]);
    $studentCount = (int)$stmt->fetchColumn();

    if ($preEnrollCount > 0 || $studentCount > 0) {
        $total = $preEnrollCount + $studentCount;
        sendResponse(false, null, "Cannot delete section: {$total} student(s) are currently assigned to section '{$sectionCode}'.", 400);
    }

    $pdo->prepare("DELETE FROM `sections` WHERE `id`=:id")->execute(['id'=>(int)$id]);
    $rows = $pdo->query("SELECT * FROM `sections` ORDER BY `id` DESC")->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'id'               => (int)$r['id'],
            'code'             => $r['code'],
            'program'          => $r['program'],
            'yearLevel'        => $r['year_level'],
            'academicPeriodId' => (int)$r['academic_period_id'],
            'curriculumVersion'=> $r['curriculum_version'] ?? '2022 Curriculum',
            'capacity'         => (int)$r['capacity'],
            'adviser'          => $r['adviser'] ?? ''
        ];
    }
    sendResponse(true, $out);

} elseif ($action === 'clone_term') {
    $payload = json_decode(file_get_contents('php://input'), true)['clone'] ?? null;
    if (!$payload) sendResponse(false, null, 'Clone options missing.', 400);

    $fromPeriodId = (int)$payload['fromPeriodId'];
    $newName      = trim($payload['newPeriodName'] ?? '');
    $newAY        = trim($payload['newAcademicYear'] ?? '');
    $newSem       = trim($payload['newSemester'] ?? '');
    $start        = $payload['enrollmentStart'] ?: null;
    $end          = $payload['enrollmentEnd'] ?: null;
    
    $cloneSections = (bool)($payload['cloneSections'] ?? true);
    $cloneOfferings = (bool)($payload['cloneOfferings'] ?? true);

    if (!$fromPeriodId || !$newName || !$newAY || !$newSem) {
        sendResponse(false, null, 'All academic period fields are required.', 400);
    }

    // 1. Verify period name uniqueness
    $checkName = $pdo->prepare("SELECT COUNT(*) FROM `academic_periods` WHERE `name` = :name");
    $checkName->execute(['name' => $newName]);
    if ($checkName->fetchColumn() > 0) {
        sendResponse(false, null, "An academic period with the name '$newName' already exists.", 400);
    }

    // 2. Create New Academic Period (Status = Inactive to let admin inspect first)
    $stmt = $pdo->prepare("INSERT INTO `academic_periods`(`name`,`academic_year`,`semester`,`enrollment_start`,`enrollment_end`,`status`) VALUES(:name,:ay,:sem,:start,:end,'Inactive')");
    $stmt->execute(['name' => $newName, 'ay' => $newAY, 'sem' => $newSem, 'start' => $start, 'end' => $end]);
    $newPeriodId = (int)$pdo->lastInsertId();

    $sectionsClonedCount = 0;
    $offeringsClonedCount = 0;

    // 3. Clone Section Cohorts
    if ($cloneSections) {
        $secStmt = $pdo->prepare("SELECT * FROM `sections` WHERE `academic_period_id` = :from_id");
        $secStmt->execute(['from_id' => $fromPeriodId]);
        $oldSections = $secStmt->fetchAll();

        $sectionIdMap = []; // old_id => new_id
        
        foreach ($oldSections as $oldSec) {
            // Uniqueness check: Program + Year Level + Academic Period + Code
            $checkExist = $pdo->prepare("SELECT `id` FROM `sections` WHERE `code` = :code AND `program` = :prog AND `year_level` = :yl AND `academic_period_id` = :ap_id");
            $checkExist->execute([
                'code'  => $oldSec['code'],
                'prog'  => $oldSec['program'],
                'yl'    => $oldSec['year_level'],
                'ap_id' => $newPeriodId
            ]);
            $exists = $checkExist->fetchColumn();

            if (!$exists) {
                $insSec = $pdo->prepare("INSERT INTO `sections`(`code`,`program`,`year_level`,`academic_period_id`,`curriculum_version`,`capacity`,`adviser`) VALUES(:code,:prog,:yl,:ap_id,:curr_v,:cap,:adv)");
                $insSec->execute([
                    'code'   => $oldSec['code'],
                    'prog'   => $oldSec['program'],
                    'yl'     => $oldSec['year_level'],
                    'ap_id'  => $newPeriodId,
                    'curr_v' => $oldSec['curriculum_version'],
                    'cap'    => $oldSec['capacity'],
                    'adv'    => $oldSec['adviser']
                ]);
                $newSecId = (int)$pdo->lastInsertId();
                $sectionsClonedCount++;
            } else {
                $newSecId = (int)$exists;
            }

            $sectionIdMap[(int)$oldSec['id']] = $newSecId;
        }

        // 4. Clone Class Offerings (Schedules)
        if ($cloneOfferings && !empty($sectionIdMap)) {
            $oldSecIds = array_map('intval', array_keys($sectionIdMap));
            $placeholders = implode(',', array_fill(0, count($oldSecIds), '?'));
            $offStmt = $pdo->prepare("SELECT * FROM `subject_sections` WHERE `section_id` IN ($placeholders)");
            $offStmt->execute($oldSecIds);
            $offRaw = $offStmt->fetchAll();
            
            foreach ($offRaw as $oldOff) {
                $newSecId = $sectionIdMap[(int)$oldOff['section_id']];
                
                // Resolve and generate unique Class Code
                $baseCode = $oldOff['code'];
                $newCode  = $baseCode;

                $checkCode = $pdo->prepare("SELECT COUNT(*) FROM `subject_sections` WHERE `code` = :code");
                $checkCode->execute(['code' => $newCode]);
                if ($checkCode->fetchColumn() > 0) {
                    $yearShort = str_replace('-', '', substr($newAY, 2)); // e.g. "2728"
                    $semShort = ($newSem === '1st Semester') ? '1' : (($newSem === '2nd Semester') ? '2' : 'S');
                    $newCode = $baseCode . '-' . $yearShort . '-' . $semShort;

                    $checkCode->execute(['code' => $newCode]);
                    if ($checkCode->fetchColumn() > 0) {
                        $newCode = $baseCode . '-' . $newPeriodId;
                    }
                }

                $insOff = $pdo->prepare("INSERT INTO `subject_sections`(`program`,`year_level`,`semester`,`subject`,`code`,`instructor`,`days`,`time`,`room`,`capacity`,`section_id`) VALUES(:prog,:yl,:sem,:sub,:code,:inst,:days,:time,:room,:cap,:sec_id)");
                $insOff->execute([
                    'prog'   => $oldOff['program'],
                    'yl'     => $oldOff['year_level'],
                    'sem'    => $newSem,
                    'sub'    => $oldOff['subject'],
                    'code'   => $newCode,
                    'inst'   => $oldOff['instructor'],
                    'days'   => $oldOff['days'],
                    'time'   => $oldOff['time'],
                    'room'   => $oldOff['room'],
                    'cap'    => $oldOff['capacity'],
                    'sec_id' => $newSecId
                ]);
                $offeringsClonedCount++;
            }
        }
    }

    // Return the updated data payload
    $periodsList = [];
    $apRaw = $pdo->query("SELECT * FROM `academic_periods` ORDER BY `id` DESC")->fetchAll();
    foreach ($apRaw as $r) {
        $periodsList[] = [
            'id'              => (int)$r['id'],
            'name'            => $r['name'],
            'academicYear'    => $r['academic_year'],
            'semester'        => $r['semester'],
            'enrollmentStart' => $r['enrollment_start'],
            'enrollmentEnd'   => $r['enrollment_end'],
            'status'          => $r['status']
        ];
    }

    $sectionsList = [];
    $secRaw = $pdo->query("SELECT * FROM `sections` ORDER BY `id` DESC")->fetchAll();
    foreach ($secRaw as $r) {
        $sectionsList[] = [
            'id'               => (int)$r['id'],
            'code'             => $r['code'],
            'program'          => $r['program'],
            'yearLevel'        => $r['year_level'],
            'academicPeriodId' => (int)$r['academic_period_id'],
            'curriculumVersion'=> $r['curriculum_version'] ?? '2022 Curriculum',
            'capacity'         => (int)$r['capacity'],
            'adviser'          => $r['adviser'] ?? ''
        ];
    }

    $offeringsList = [];
    $offRaw = $pdo->query("SELECT * FROM `subject_sections` ORDER BY `id` DESC")->fetchAll();
    foreach ($offRaw as $r) {
        $offeringsList[] = [
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

    sendResponse(true, [
        'periods'        => $periodsList,
        'sections'       => $sectionsList,
        'classOfferings' => $offeringsList
    ], "Cloned successfully! Created period, $sectionsClonedCount section(s), and $offeringsClonedCount class schedule(s).");

} elseif ($action === 'bulk_generate_sections') {
    $payload = json_decode(file_get_contents('php://input'), true)['bulk'] ?? null;
    if (!$payload) sendResponse(false, null, 'Bulk generation data missing.', 400);

    $program    = trim($payload['program'] ?? '');
    $yearLevel  = trim($payload['yearLevel'] ?? '');
    $periodId   = (int)($payload['academicPeriodId'] ?? 0);
    $currVersion = trim($payload['curriculumVersion'] ?? '2022 Curriculum');
    $capacity   = (int)($payload['capacity'] ?? 40);
    $adviser    = trim($payload['adviser'] ?? '');
    $count      = (int)($payload['count'] ?? 1);

    if (!$program || !$yearLevel || !$periodId || $count <= 0) {
        sendResponse(false, null, 'Program, Year Level, Academic Period, and Section count are required.', 400);
    }

    // Verify academic period exists and is Active
    $perCheck = $pdo->prepare("SELECT `status` FROM `academic_periods` WHERE `id` = :id");
    $perCheck->execute(['id' => $periodId]);
    $perStatus = $perCheck->fetchColumn();
    if (!$perStatus || strtoupper($perStatus) !== 'ACTIVE') {
        sendResponse(false, null, 'Cannot generate sections for an Inactive academic period. Please activate the term under Enrollment Semesters first.', 400);
    }

    $alphabet = range('A', 'Z');
    $createdCount = 0;
    
    for ($i = 0; $i < $count; $i++) {
        $code = $alphabet[$i] ?? ('Sec' . ($i + 1));
        
        // Uniqueness check: Program + Year Level + Academic Period + Code
        $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM `sections` WHERE `code` = :code AND `program` = :prog AND `year_level` = :yl AND `academic_period_id` = :ap_id");
        $checkStmt->execute(['code' => $code, 'prog' => $program, 'yl' => $yearLevel, 'ap_id' => $periodId]);
        if ($checkStmt->fetchColumn() == 0) {
            $insStmt = $pdo->prepare("INSERT INTO `sections`(`code`,`program`,`year_level`,`academic_period_id`,`curriculum_version`,`capacity`,`adviser`) VALUES(:code,:prog,:yl,:ap_id,:curr_v,:cap,:adv)");
            $insStmt->execute([
                'code'   => $code,
                'prog'   => $program,
                'yl'     => $yearLevel,
                'ap_id'  => $periodId,
                'curr_v' => $currVersion,
                'cap'    => $capacity,
                'adv'    => $adviser ?: null
            ]);
            $createdCount++;
        }
    }

    $rows = $pdo->query("SELECT * FROM `sections` ORDER BY `id` DESC")->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'id'               => (int)$r['id'],
            'code'             => $r['code'],
            'program'          => $r['program'],
            'yearLevel'        => $r['year_level'],
            'academicPeriodId' => (int)$r['academic_period_id'],
            'curriculumVersion'=> $r['curriculum_version'] ?? '2022 Curriculum',
            'capacity'         => (int)$r['capacity'],
            'adviser'          => $r['adviser'] ?? ''
        ];
    }
    
    sendResponse(true, $out, "Successfully generated $createdCount new section cohort block(s)!");
}
