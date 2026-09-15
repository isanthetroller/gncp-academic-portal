<?php
/**
 * Catalog Submodule — Program Catalog Namespace
 */

if ($action === 'save_program') {
    $prog = json_decode(file_get_contents('php://input'), true)['program'] ?? null;
    if (!$prog) sendResponse(false, null, 'Program data missing.', 400);

    $status = $prog['status'] ?? 'Active';

    if (!empty($prog['id'])) {
        // Fetch old name/code before update for rename cascading
        $oldStmt = $pdo->prepare("SELECT `name`, `code` FROM `programs` WHERE `id` = :id");
        $oldStmt->execute(['id' => (int)$prog['id']]);
        $oldProg = $oldStmt->fetch();

        $stmt = $pdo->prepare("UPDATE `programs` SET `code`=:code,`name`=:name,`department`=:dept,`status`=:status WHERE `id`=:id");
        $stmt->execute([
            'code'   => $prog['code'] ?? '',
            'name'   => $prog['name'] ?? '',
            'dept'   => $prog['department'] ?? '',
            'status' => $status,
            'id'     => (int)$prog['id']
        ]);

        // Cascade rename to child tables if name changed
        if ($oldProg && $oldProg['name'] !== ($prog['name'] ?? '')) {
            $newName = $prog['name'] ?? '';
            $oldName = $oldProg['name'];
            $pdo->prepare("UPDATE `curriculum` SET `program` = :new WHERE `program` = :old")->execute(['new' => $newName, 'old' => $oldName]);
            $pdo->prepare("UPDATE `subject_sections` SET `program` = :new WHERE `program` = :old")->execute(['new' => $newName, 'old' => $oldName]);
            $pdo->prepare("UPDATE `sections` SET `program` = :new WHERE `program` = :old")->execute(['new' => $newName, 'old' => $oldName]);
            // Cascade to student records and active applications
            $pdo->prepare("UPDATE `students` SET `program` = :new WHERE `program` = :old")->execute(['new' => $newName, 'old' => $oldName]);
            $pdo->prepare("UPDATE `pre_enrollments` SET `course_code` = :new WHERE `course_code` = :old")->execute(['new' => $newName, 'old' => $oldName]);
            // Also cascade old code references
            if ($oldProg['code'] !== ($prog['code'] ?? '')) {
                $newCode = $prog['code'] ?? '';
                $oldCode = $oldProg['code'];
                $pdo->prepare("UPDATE `students` SET `program` = :new WHERE `program` = :old")->execute(['new' => $newCode, 'old' => $oldCode]);
                $pdo->prepare("UPDATE `pre_enrollments` SET `course_code` = :new WHERE `course_code` = :old")->execute(['new' => $newCode, 'old' => $oldCode]);
            }
        }
    } else {
        $stmt = $pdo->prepare("INSERT INTO `programs`(`code`,`name`,`department`,`status`) VALUES(:code,:name,:dept,:status)");
        $stmt->execute([
            'code'   => $prog['code'] ?? '',
            'name'   => $prog['name'] ?? '',
            'dept'   => $prog['department'] ?? '',
            'status' => $status
        ]);
    }
    sendResponse(true, $pdo->query("SELECT * FROM `programs` ORDER BY `id` DESC")->fetchAll());

} elseif ($action === 'delete_program') {
    $id = json_decode(file_get_contents('php://input'), true)['id'] ?? null;
    if ($id === null) sendResponse(false, null, 'ID missing.', 400);

    // Fetch the program details to get code and name references
    $stmt = $pdo->prepare("SELECT * FROM `programs` WHERE `id` = :id");
    $stmt->execute(['id' => (int)$id]);
    $program = $stmt->fetch();
    if (!$program) sendResponse(false, null, 'Program not found.', 404);

    $code = $program['code'];
    $name = $program['name'];

    // Check pre_enrollments dependency
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM `pre_enrollments` WHERE `course_code` = :code OR `course_code` = :name");
    $stmt->execute(['code' => $code, 'name' => $name]);
    $preCount = $stmt->fetchColumn();

    // Check students dependency
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM `students` WHERE `program` = :code OR `program` = :name");
    $stmt->execute(['code' => $code, 'name' => $name]);
    $studentCount = $stmt->fetchColumn();

    if ($preCount > 0 || $studentCount > 0) {
        sendResponse(false, null, "Cannot delete program: active or pending students are currently enrolled.", 400);
    }

    // Perform cascading deletions of dependent catalog configurations
    $pdo->prepare("DELETE FROM `curriculum` WHERE `program` = :name")->execute(['name' => $name]);
    $pdo->prepare("DELETE FROM `subject_sections` WHERE `program` = :name")->execute(['name' => $name]);
    $pdo->prepare("DELETE FROM `sections` WHERE `program` = :name")->execute(['name' => $name]);

    // Delete the program
    $pdo->prepare("DELETE FROM `programs` WHERE `id` = :id")->execute(['id' => (int)$id]);
    sendResponse(true, $pdo->query("SELECT * FROM `programs` ORDER BY `id` DESC")->fetchAll());

} elseif ($action === 'save_subject') {
    $sub = json_decode(file_get_contents('php://input'), true)['subject'] ?? null;
    if (!$sub) sendResponse(false, null, 'Subject data missing.', 400);

    $params = [
        'code'   => $sub['code'],
        'title'  => $sub['title'],
        'desc'   => $sub['description'] ?? '',
        'lec'    => (int)($sub['lectureUnits'] ?? 0),
        'lab'    => (int)($sub['labUnits'] ?? 0),
        'fee'    => (float)($sub['labFee'] ?? 0),
        'dept'   => $sub['department'] ?? '',
        'prereq' => $sub['prerequisites'] ?? 'None'
    ];

    if (!empty($sub['id'])) {
        // Fetch old title before update for rename cascading
        $oldStmt = $pdo->prepare("SELECT `title`, `code` FROM `subjects` WHERE `id` = :id");
        $oldStmt->execute(['id' => (int)$sub['id']]);
        $oldSub = $oldStmt->fetch();

        $params['id'] = (int)$sub['id'];
        $stmt = $pdo->prepare("UPDATE `subjects` SET `code`=:code,`title`=:title,`description`=:desc,`lecture_units`=:lec,`lab_units`=:lab,`lab_fee`=:fee,`department`=:dept,`prerequisites`=:prereq WHERE `id`=:id");
        $stmt->execute($params);

        // Cascade rename to child tables if title changed
        if ($oldSub && $oldSub['title'] !== $sub['title']) {
            $newTitle = $sub['title'];
            $oldTitle = $oldSub['title'];
            $pdo->prepare("UPDATE `curriculum` SET `subject` = :new WHERE `subject` = :old")->execute(['new' => $newTitle, 'old' => $oldTitle]);
            $pdo->prepare("UPDATE `subject_sections` SET `subject` = :new WHERE `subject` = :old")->execute(['new' => $newTitle, 'old' => $oldTitle]);
        }
    } else {
        $stmt = $pdo->prepare("INSERT INTO `subjects`(`code`,`title`,`description`,`lecture_units`,`lab_units`,`lab_fee`,`department`,`prerequisites`) VALUES(:code,:title,:desc,:lec,:lab,:fee,:dept,:prereq)");
        $stmt->execute($params);
    }

    $rows = $pdo->query("SELECT * FROM `subjects` ORDER BY `id` DESC")->fetchAll();
    $out  = [];
    foreach ($rows as $r) {
        $out[] = ['id'=>(int)$r['id'],'code'=>$r['code'],'title'=>$r['title'],'description'=>$r['description'],
                  'lectureUnits'=>(int)$r['lecture_units'],'labUnits'=>(int)$r['lab_units'],'labFee'=>(float)$r['lab_fee'],
                  'department'=>$r['department'],'prerequisites'=>$r['prerequisites']];
    }
    sendResponse(true, $out);

} elseif ($action === 'delete_subject') {
    $id = json_decode(file_get_contents('php://input'), true)['id'] ?? null;
    if ($id === null) sendResponse(false, null, 'ID missing.', 400);

    // Fetch the subject details first to get code and title
    $stmt = $pdo->prepare("SELECT * FROM `subjects` WHERE `id` = :id");
    $stmt->execute(['id' => (int)$id]);
    $subject = $stmt->fetch();
    if (!$subject) sendResponse(false, null, 'Subject not found.', 404);

    $code = $subject['code'];
    $title = $subject['title'];

    // Check curriculum dependency
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM `curriculum` WHERE `subject` = :code OR `subject` = :title");
    $stmt->execute(['code' => $code, 'title' => $title]);
    $currCount = $stmt->fetchColumn();

    // Check subject_sections dependency
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM `subject_sections` WHERE `subject` = :code OR `subject` = :title");
    $stmt->execute(['code' => $code, 'title' => $title]);
    $secCount = $stmt->fetchColumn();

    if ($currCount > 0 || $secCount > 0) {
        sendResponse(false, null, "Cannot delete subject: it is currently mapped in active curricula or has active class offerings.", 400);
    }

    // Delete the subject
    $pdo->prepare("DELETE FROM `subjects` WHERE `id` = :id")->execute(['id' => (int)$id]);
    $rows = $pdo->query("SELECT * FROM `subjects` ORDER BY `id` DESC")->fetchAll();
    $out  = [];
    foreach ($rows as $r) {
        $out[] = ['id'=>(int)$r['id'],'code'=>$r['code'],'title'=>$r['title'],'description'=>$r['description'],
                  'lectureUnits'=>(int)$r['lecture_units'],'labUnits'=>(int)$r['lab_units'],'labFee'=>(float)$r['lab_fee'],
                  'department'=>$r['department'],'prerequisites'=>$r['prerequisites']];
    }
    sendResponse(true, $out);

} elseif ($action === 'save_curriculum') {
    $curr = json_decode(file_get_contents('php://input'), true)['curriculum'] ?? null;
    if (!$curr) sendResponse(false, null, 'Curriculum data missing.', 400);

    $params = [
        'prog'   => $curr['program'],
        'sub'    => $curr['subject'],
        'yl'     => $curr['yearLevel'],
        'sem'    => $curr['semester'],
        'el'     => (int)($curr['elective'] ?? 0),
        'curr_v' => $curr['curriculumVersion'] ?? '2022 Curriculum'
    ];
    if (!empty($curr['id'])) {
        $params['id'] = (int)$curr['id'];
        $stmt = $pdo->prepare("UPDATE `curriculum` SET `program`=:prog,`subject`=:sub,`year_level`=:yl,`semester`=:sem,`elective`=:el,`curriculum_version`=:curr_v WHERE `id`=:id");
    } else {
        $stmt = $pdo->prepare("INSERT INTO `curriculum`(`program`,`subject`,`year_level`,`semester`,`elective`,`curriculum_version`) VALUES(:prog,:sub,:yl,:sem,:el,:curr_v)");
    }
    $stmt->execute($params);

    $rows = $pdo->query("SELECT c.*,s.code as subject_code,s.lecture_units,s.lab_units,s.lab_fee,s.prerequisites FROM `curriculum` c LEFT JOIN `subjects` s ON (c.subject=s.title OR c.subject=s.code) ORDER BY c.id DESC")->fetchAll();
    $out  = [];
    foreach ($rows as $r) {
        $out[] = [
            'id'           => (int)$r['id'],
            'program'      => $r['program'],
            'subject'      => $r['subject'],
            'subjectCode'  => $r['subject_code'] ?? '',
            'lectureUnits' => (int)($r['lecture_units'] ?? 0),
            'labUnits'     => (int)($r['lab_units'] ?? 0),
            'labFee'       => (float)($r['lab_fee'] ?? 0),
            'prerequisites'=> $r['prerequisites'] ?? 'None',
            'yearLevel'    => $r['year_level'],
            'semester'     => $r['semester'],
            'elective'     => (bool)$r['elective'],
            'curriculumVersion' => $r['curriculum_version'] ?? '2022 Curriculum'
        ];
    }
    sendResponse(true, $out);

} elseif ($action === 'delete_curriculum') {
    $id = json_decode(file_get_contents('php://input'), true)['id'] ?? null;
    if ($id === null) sendResponse(false, null, 'ID missing.', 400);

    // Fetch curriculum entry details for dependency check
    $fetchCurr = $pdo->prepare("SELECT `program`, `subject`, `year_level`, `semester` FROM `curriculum` WHERE `id` = :id");
    $fetchCurr->execute(['id' => (int)$id]);
    $currEntry = $fetchCurr->fetch();

    if ($currEntry) {
        // Check if class offerings exist for this exact program+subject+year+semester
        $checkOfferings = $pdo->prepare(
            "SELECT COUNT(*) FROM `subject_sections` WHERE `program` = :prog AND `subject` = :sub AND `year_level` = :yl AND `semester` = :sem"
        );
        $checkOfferings->execute([
            'prog' => $currEntry['program'],
            'sub'  => $currEntry['subject'],
            'yl'   => $currEntry['year_level'],
            'sem'  => $currEntry['semester']
        ]);
        $offeringCount = (int)$checkOfferings->fetchColumn();

        if ($offeringCount > 0) {
            sendResponse(false, null, "Cannot delete curriculum entry: {$offeringCount} class offering(s) are linked to '{$currEntry['subject']}' for this program and semester. Remove those class offerings first.", 400);
        }
    }

    $pdo->prepare("DELETE FROM `curriculum` WHERE `id`=:id")->execute(['id'=>(int)$id]);
    $rows = $pdo->query("SELECT c.*,s.code as subject_code,s.lecture_units,s.lab_units,s.lab_fee,s.prerequisites FROM `curriculum` c LEFT JOIN `subjects` s ON (c.subject=s.title OR c.subject=s.code) ORDER BY c.id DESC")->fetchAll();
    $out  = [];
    foreach ($rows as $r) {
        $out[] = [
            'id'           => (int)$r['id'],
            'program'      => $r['program'],
            'subject'      => $r['subject'],
            'subjectCode'  => $r['subject_code'] ?? '',
            'lectureUnits' => (int)($r['lecture_units'] ?? 0),
            'labUnits'     => (int)($r['lab_units'] ?? 0),
            'labFee'       => (float)($r['lab_fee'] ?? 0),
            'prerequisites'=> $r['prerequisites'] ?? 'None',
            'yearLevel'    => $r['year_level'],
            'semester'     => $r['semester'],
            'elective'     => (bool)$r['elective'],
            'curriculumVersion' => $r['curriculum_version'] ?? '2022 Curriculum'
        ];
    }
    sendResponse(true, $out);

} elseif ($action === 'clone_curriculum_version') {
    $payload = json_decode(file_get_contents('php://input'), true);
    $prog = trim($payload['program'] ?? '');
    $fromV = trim($payload['fromVersion'] ?? '');
    $toV = trim($payload['toVersion'] ?? '');

    if (empty($prog) || empty($fromV) || empty($toV)) {
        sendResponse(false, null, 'Program, Source Version, and Target Version are required.', 400);
    }
    if (strcasecmp($fromV, $toV) === 0) {
        sendResponse(false, null, 'Source Version and Target Version cannot be identical.', 400);
    }

    $sourceRows = $pdo->prepare("SELECT * FROM `curriculum` WHERE `program` = :prog AND `curriculum_version` = :from_v");
    $sourceRows->execute(['prog' => $prog, 'from_v' => $fromV]);
    $entries = $sourceRows->fetchAll(PDO::FETCH_ASSOC);

    if (empty($entries)) {
        sendResponse(false, null, "No curriculum mappings found in source version '{$fromV}' for program '{$prog}'.", 400);
    }

    // Remove existing if any in target version to avoid duplicates
    $del = $pdo->prepare("DELETE FROM `curriculum` WHERE `program` = :prog AND `curriculum_version` = :to_v");
    $del->execute(['prog' => $prog, 'to_v' => $toV]);

    $ins = $pdo->prepare("INSERT INTO `curriculum` (`program`, `subject`, `year_level`, `semester`, `elective`, `curriculum_version`) VALUES (:prog, :sub, :yl, :sem, :el, :curr_v)");
    foreach ($entries as $e) {
        $ins->execute([
            'prog'   => $prog,
            'sub'    => $e['subject'],
            'yl'     => $e['year_level'],
            'sem'    => $e['semester'],
            'el'     => (int)$e['elective'],
            'curr_v' => $toV
        ]);
    }

    $rows = $pdo->query("SELECT c.*,s.code as subject_code,s.lecture_units,s.lab_units,s.lab_fee,s.prerequisites FROM `curriculum` c LEFT JOIN `subjects` s ON (c.subject=s.title OR c.subject=s.code) ORDER BY c.id DESC")->fetchAll();
    $out  = [];
    foreach ($rows as $r) {
        $out[] = [
            'id'           => (int)$r['id'],
            'program'      => $r['program'],
            'subject'      => $r['subject'],
            'subjectCode'  => $r['subject_code'] ?? '',
            'lectureUnits' => (int)($r['lecture_units'] ?? 0),
            'labUnits'     => (int)($r['lab_units'] ?? 0),
            'labFee'       => (float)($r['lab_fee'] ?? 0),
            'prerequisites'=> $r['prerequisites'] ?? 'None',
            'yearLevel'    => $r['year_level'],
            'semester'     => $r['semester'],
            'elective'     => (bool)$r['elective'],
            'curriculumVersion' => $r['curriculum_version'] ?? '2022 Curriculum'
        ];
    }
    sendResponse(true, $out, "Successfully cloned {$fromV} to {$toV} for {$prog} (" . count($entries) . " subjects mapped).");

} elseif ($action === 'delete_curriculum_version') {
    $payload = json_decode(file_get_contents('php://input'), true);
    $prog = trim($payload['program'] ?? '');
    $version = trim($payload['version'] ?? '');

    if (empty($prog) || empty($version)) {
        sendResponse(false, null, 'Program and Curriculum Version are required.', 400);
    }

    $del = $pdo->prepare("DELETE FROM `curriculum` WHERE `program` = :prog AND `curriculum_version` = :ver");
    $del->execute(['prog' => $prog, 'ver' => $version]);

    $rows = $pdo->query("SELECT c.*,s.code as subject_code,s.lecture_units,s.lab_units,s.lab_fee,s.prerequisites FROM `curriculum` c LEFT JOIN `subjects` s ON (c.subject=s.title OR c.subject=s.code) ORDER BY c.id DESC")->fetchAll();
    $out  = [];
    foreach ($rows as $r) {
        $out[] = [
            'id'           => (int)$r['id'],
            'program'      => $r['program'],
            'subject'      => $r['subject'],
            'subjectCode'  => $r['subject_code'] ?? '',
            'lectureUnits' => (int)($r['lecture_units'] ?? 0),
            'labUnits'     => (int)($r['lab_units'] ?? 0),
            'labFee'       => (float)($r['lab_fee'] ?? 0),
            'prerequisites'=> $r['prerequisites'] ?? 'None',
            'yearLevel'    => $r['year_level'],
            'semester'     => $r['semester'],
            'elective'     => (bool)$r['elective'],
            'curriculumVersion' => $r['curriculum_version'] ?? '2022 Curriculum'
        ];
    }
    sendResponse(true, $out, "Curriculum version '{$version}' deleted for program '{$prog}'.");

} elseif ($action === 'save_department') {
    sendResponse(false, null, 'Departments are locked and cannot be added or modified.', 403);

} elseif ($action === 'delete_department') {
    sendResponse(false, null, 'Departments are locked and cannot be deleted.', 403);

} elseif ($action === 'save_curriculum_wizard') {
    $payload = json_decode(file_get_contents('php://input'), true)['wizard'] ?? null;
    if (!$payload) sendResponse(false, null, 'Wizard data missing.', 400);

    $deptCode = trim($payload['departmentCode'] ?? '');
    $deptName = trim($payload['departmentName'] ?? '');
    
    $progCode = trim($payload['programCode'] ?? '');
    $progName = trim($payload['programName'] ?? '');
    
    $currV = trim($payload['curriculumVersion'] ?? '2022 Curriculum');
    $mappings = $payload['mappings'] ?? []; // Array of { subject: string, yearLevel: string, semester: string, elective: boolean }

    // 1. Manage Department
    if ($deptCode && $deptName) {
        $checkDept = $pdo->prepare("SELECT `name` FROM `departments` WHERE `code` = :code");
        $checkDept->execute(['code' => $deptCode]);
        $existingDeptName = $checkDept->fetchColumn();
        if (!$existingDeptName) {
            sendResponse(false, null, 'New departments cannot be created. Please select one of the three standard departments.', 403);
        } else {
            $deptNameUsed = $existingDeptName;
        }
    } else {
        $deptNameUsed = trim($payload['selectedDepartmentName'] ?? '');
    }

    // 2. Manage Program
    if ($progCode && $progName) {
        $checkProg = $pdo->prepare("SELECT `name` FROM `programs` WHERE `code` = :code");
        $checkProg->execute(['code' => $progCode]);
        $existingProgName = $checkProg->fetchColumn();
        if (!$existingProgName) {
            if (!$deptNameUsed) sendResponse(false, null, 'Department is required to create a Program.', 400);
            $stmt = $pdo->prepare("INSERT INTO `programs`(`code`,`name`,`department`,`status`) VALUES(:code,:name,:dept,'Active')");
            $stmt->execute(['code' => $progCode, 'name' => $progName, 'dept' => $deptNameUsed]);
            $progNameUsed = $progName;
        } else {
            $progNameUsed = $existingProgName;
        }
    } else {
        $progNameUsed = trim($payload['selectedProgramName'] ?? '');
    }

    if (!$progNameUsed) sendResponse(false, null, 'Program is required for curriculum mapping.', 400);
    if (!$currV) sendResponse(false, null, 'Curriculum Version is required.', 400);

    // 3. Delete existing mapping for this Program + Version to support overwrite/edit
    $delStmt = $pdo->prepare("DELETE FROM `curriculum` WHERE `program` = :prog AND `curriculum_version` = :curr_v");
    $delStmt->execute(['prog' => $progNameUsed, 'curr_v' => $currV]);

    // 4. Bulk Insert mappings
    if (!empty($mappings)) {
        $sql = "INSERT INTO `curriculum`(`program`,`subject`,`year_level`,`semester`,`elective`,`curriculum_version`) VALUES ";
        $vals = [];
        $placeholders = [];
        foreach ($mappings as $i => $m) {
            $placeholders[] = "(:prog_$i, :sub_$i, :yl_$i, :sem_$i, :el_$i, :curr_v_$i)";
            $vals["prog_$i"]   = $progNameUsed;
            $vals["sub_$i"]    = $m['subject'];
            $vals["yl_$i"]     = $m['yearLevel'];
            $vals["sem_$i"]    = $m['semester'];
            $vals["el_$i"]     = (int)($m['elective'] ?? 0);
            $vals["curr_v_$i"] = $currV;
        }
        $sql .= implode(', ', $placeholders);
        $stmt = $pdo->prepare($sql);
        $stmt->execute($vals);
    }

    // Return updated data
    $rows = $pdo->query("SELECT c.*,s.code as subject_code,s.lecture_units,s.lab_units,s.lab_fee,s.prerequisites FROM `curriculum` c LEFT JOIN `subjects` s ON (c.subject=s.title OR c.subject=s.code) ORDER BY c.id DESC")->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'id'           => (int)$r['id'],
            'program'      => $r['program'],
            'subject'      => $r['subject'],
            'subjectCode'  => $r['subject_code'] ?? '',
            'lectureUnits' => (int)($r['lecture_units'] ?? 0),
            'labUnits'     => (int)($r['lab_units'] ?? 0),
            'labFee'       => (float)($r['lab_fee'] ?? 0),
            'prerequisites'=> $r['prerequisites'] ?? 'None',
            'yearLevel'    => $r['year_level'],
            'semester'     => $r['semester'],
            'elective'     => (bool)$r['elective'],
            'curriculumVersion' => $r['curriculum_version'] ?? '2022 Curriculum'
        ];
    }
    
    $depts = $pdo->query("SELECT * FROM `departments` ORDER BY `id` DESC")->fetchAll();
    $progs = $pdo->query("SELECT * FROM `programs` ORDER BY `id` DESC")->fetchAll();

    sendResponse(true, [
        'curriculum'  => $out,
        'departments' => $depts,
        'programs'    => $progs
    ], 'Curriculum wizard configuration saved successfully!');

} elseif ($action === 'save_fee') {
    $fee = json_decode(file_get_contents('php://input'), true)['fee'] ?? null;
    if (!$fee) sendResponse(false, null, 'Fee data missing.', 400);

    $params = ['type'=>$fee['type'],'label'=>$fee['label'],'amt'=>(float)($fee['amount']??0),'pu'=>(int)($fee['perUnit']??0)];
    if (!empty($fee['id'])) {
        $params['id'] = (int)$fee['id'];
        $stmt = $pdo->prepare("UPDATE `fee_schedule` SET `type`=:type,`label`=:label,`amount`=:amt,`per_unit`=:pu WHERE `id`=:id");
    } else {
        $stmt = $pdo->prepare("INSERT INTO `fee_schedule`(`type`,`label`,`amount`,`per_unit`) VALUES(:type,:label,:amt,:pu)");
    }
    $stmt->execute($params);
    $rows = $pdo->query("SELECT * FROM `fee_schedule` ORDER BY `id` DESC")->fetchAll();
    $out  = [];
    foreach ($rows as $r) {
        $out[] = ['id'=>(int)$r['id'],'type'=>$r['type'],'label'=>$r['label'],'amount'=>(float)$r['amount'],'perUnit'=>(bool)$r['per_unit']];
    }
    sendResponse(true, $out);

} elseif ($action === 'delete_fee') {
    $id = json_decode(file_get_contents('php://input'), true)['id'] ?? null;
    if ($id === null) sendResponse(false, null, 'ID missing.', 400);
    $pdo->prepare("DELETE FROM `fee_schedule` WHERE `id`=:id")->execute(['id'=>(int)$id]);
    $rows = $pdo->query("SELECT * FROM `fee_schedule` ORDER BY `id` DESC")->fetchAll();
    $out  = [];
    foreach ($rows as $r) {
        $out[] = ['id'=>(int)$r['id'],'type'=>$r['type'],'label'=>$r['label'],'amount'=>(float)$r['amount'],'perUnit'=>(bool)$r['per_unit']];
    }
    sendResponse(true, $out);
}

