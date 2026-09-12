<?php
/**
 * GNCP Shared Student Core Utilities
 * Centralized business logic for student promotions, tracking ID generation, and document requirements.
 */

/**
 * Fetches mapped curriculum subjects, units, and fees for a given program, year level, and semester.
 */
if (!function_exists('getCurriculumSubjects')) {
function getCurriculumSubjects($pdo, $programCode, $yearLevel = '1st Year', $semester = '1st Semester', $curriculumVersion = null) {
    $aliasMap = [
        'BSCPE' => 'BS Computer Engineering',
        'BSCOE' => 'BS Computer Engineering',
        'BSCS'  => 'BS Computer Science',
        'BSIT'  => 'BS Information Technology',
        'BSN'   => 'BS Nursing',
        'BSBA'  => 'BS Business Administration'
    ];
    $cleanCode = strtoupper(trim($programCode));
    $programName = $aliasMap[$cleanCode] ?? null;

    if (!$programName) {
        $stmt = $pdo->prepare("SELECT name FROM `programs` WHERE `code` = :code OR `name` = :name");
        $stmt->execute(['code' => $programCode, 'name' => $programCode]);
        $programName = $stmt->fetchColumn() ?: $programCode;
    }

    $versionClause = "";
    $params = [
        'progName' => $programName,
        'progCode' => $programCode,
        'year'     => $yearLevel,
        'sem'      => $semester
    ];

    if (!empty($curriculumVersion)) {
        $versionClause = " AND c.curriculum_version = :ver";
        $params['ver'] = $curriculumVersion;
    }

    $sql = "SELECT s.code, s.title, s.lecture_units, s.lab_units, (s.lecture_units + s.lab_units) AS units, s.lab_fee, s.prerequisites, c.curriculum_version 
            FROM `curriculum` c
            JOIN `subjects` s ON (c.subject = s.title OR c.subject = s.code)
            WHERE (c.program = :progName OR c.program = :progCode) AND c.year_level = :year AND c.semester = :sem" . $versionClause . "
            GROUP BY s.code
            ORDER BY s.code ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($results) && !empty($curriculumVersion)) {
        // Fallback: If specific version is not found, fallback query without version filter
        unset($params['ver']);
        $sqlFallback = "SELECT s.code, s.title, s.lecture_units, s.lab_units, (s.lecture_units + s.lab_units) AS units, s.lab_fee, s.prerequisites, c.curriculum_version 
                        FROM `curriculum` c
                        JOIN `subjects` s ON (c.subject = s.title OR c.subject = s.code)
                        WHERE (c.program = :progName OR c.program = :progCode) AND c.year_level = :year AND c.semester = :sem
                        GROUP BY s.code
                        ORDER BY s.code ASC";
        $stmtFB = $pdo->prepare($sqlFallback);
        $stmtFB->execute($params);
        $results = $stmtFB->fetchAll(PDO::FETCH_ASSOC);
    }

    return $results;
}
}

/**
 * Returns requirements array for a given student type and SHS track.
 */
if (!function_exists('getRequirementsForType')) {
function getRequirementsForType($studentType, $shsTrack = '') {
    $studentType = strtoupper($studentType);
    if ($studentType === 'FRESHMAN') {
        if ($shsTrack === 'ALS') {
            return [
                'ALS Certificate of Rating (COR) with Passing Marks (Original & Photocopy)',
                'ALS Certificate of Completion (Original)',
                'PSA Birth Certificate (Photocopy)',
                '2 pieces recent 2x2 color pictures (white background with name tag)'
            ];
        } else if ($shsTrack === 'OLD_CURRICULUM') {
            return [
                'Old High School Report Card (Form 138-A) / Transcript of Record (Original)',
                'Original Certificate of Good Moral Character (with dry seal)',
                'PSA Birth Certificate (Photocopy)',
                '2 pieces recent 2x2 color pictures (white background with name tag)'
            ];
        } else {
            return [
                'Form 138 (Original Senior High School Report Card)',
                'Original Certificate of Good Moral Character (with dry seal)',
                'PSA Birth Certificate (Photocopy)',
                '2 pieces recent 2x2 color pictures (white background with name tag)'
            ];
        }
    } else if ($studentType === 'TRANSFEREE') {
        return [
            'Original Honorable Dismissal / Transfer Credentials',
            'Official Transcript of Records (TOR) or Copy of Grades (for evaluation)',
            'Original Certificate of Good Moral Character',
            'PSA Birth Certificate (Photocopy)',
            '2 pieces recent 2x2 color pictures (white background with name tag)'
        ];
    } else {
        return [
            'GNCP Student Clearance Form from the previous semester attended',
            'Evaluation Form signed by the Registrar Coordinator / Academic Dean',
            'Student ID Card (for renewal)'
        ];
    }
}
}

/**
 * Generates a collision-free sequential student ID based on existing records for the academic year.
 */
if (!function_exists('generateUniqueStudentId')) {
function generateUniqueStudentId($pdo, $year = '2026') {
    $stmt = $pdo->prepare("SELECT MAX(CAST(SUBSTRING_INDEX(id, '-', -1) AS UNSIGNED)) as max_num FROM `students` WHERE `id` LIKE :pattern");
    $stmt->execute(['pattern' => "$year-%"]);
    $row = $stmt->fetch();
    
    if ($row && $row['max_num'] !== null) {
        $nextNum = (int)$row['max_num'] + 1;
        return $year . '-' . str_pad($nextNum, 4, '0', STR_PAD_LEFT);
    }
    
    return "$year-1001";
}
}

/**
 * Promotes a pre-enrollment queue record to a permanent student profile.
 * Creates credentials, seeds directories, logs enrollment, and cleans up staging queues.
 */
if (!function_exists('promotePreEnrollmentToStudent')) {
function promotePreEnrollmentToStudent($pdo, $record, $refNum, $roadmapJson, $itData = []) {
    $startedTx = false;
    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
        $startedTx = true;
    }

    try {
        $itData['temp_pin'] = $record['temp_pin'] ?? $itData['temp_pin'] ?? '';
        if (empty($itData['assignedSection'])) {
            if (!empty($record['section_code'])) {
                $itData['assignedSection'] = $record['section_code'];
            } else {
                $hData = json_decode($record['helpdesk_data'] ?? '{}', true) ?: [];
                if (!empty($hData['section'])) {
                    $itData['assignedSection'] = $hData['section'];
                }
            }
        }
        $studName = trim($record['first_name'] . ' ' . ($record['middle_name'] ? $record['middle_name'] . ' ' : '') . $record['last_name']);
        
        // Determine sequential student ID (or use provided ID if already assigned by IT)
        $permId = $itData['permanentId'] ?? ($itData['studentId'] ?? generateUniqueStudentId($pdo, '2026'));
        
        // Check by permanent ID
        $checkStmt = $pdo->prepare("SELECT * FROM `students` WHERE `id` = :id");
        $checkStmt->execute(['id' => $permId]);
        $existingStudent = $checkStmt->fetch();
        
        // Concurrency / duplicate ID protection:
        // If the ID exists but belongs to a DIFFERENT candidate, regenerate a unique ID
        while ($existingStudent && $existingStudent['temp_reference_no'] !== $refNum) {
            $permId = generateUniqueStudentId($pdo, '2026');
            $checkStmt->execute(['id' => $permId]);
            $existingStudent = $checkStmt->fetch();
        }

        $email = $itData['institutionalEmail'] ?? null;
        if (empty($email)) {
            $cleanFirst = preg_replace('/[^a-z]/', '', strtolower(explode(' ', $record['first_name'])[0]));
            $cleanLast = preg_replace('/[^a-z]/', '', strtolower(explode(' ', $record['last_name'])[0]));
            $randomSuffix = random_int(10, 99);
            $email = "{$cleanFirst}.{$cleanLast}{$randomSuffix}@gncp.edu.ph";
        }

        $defaultLastNamePassword = strtolower(trim(preg_replace('/[^a-zA-Z0-9]/', '', $record['last_name'] ?? '')));
        if (empty($defaultLastNamePassword)) {
            $defaultLastNamePassword = 'password123';
        }
        $plainPassword = !empty($itData['password']) ? $itData['password'] : $defaultLastNamePassword;
        $hashedPassword = password_hash($plainPassword, PASSWORD_DEFAULT);
        $photoFile = $itData['photoFile'] ?? null;

        $personalInfoJson = json_encode([
            'firstName' => $record['first_name'],
            'middleName' => $record['middle_name'],
            'lastName' => $record['last_name'],
            'email' => $record['email'],
            'phone' => $record['phone'],
            'birthDate' => $record['birth_date'],
            'gender' => $record['gender'],
            'address' => $record['address'],
            'temp_pin' => $record['temp_pin'] ?? '' // Persisted to allow recovery in QueueService
        ]);
        $academicInfoJson = json_encode([
            'elementarySchool' => $record['elementary_school'],
            'juniorHighSchool' => $record['junior_high_school'],
            'seniorHighSchool' => $record['senior_high_school'],
            'shsTrack' => $record['shs_track']
        ]);

        $appliedYearLevel = !empty($record['year_level_applied']) ? $record['year_level_applied'] : '1st Year';

        if ($existingStudent) {
            $updateStudentStmt = $pdo->prepare("UPDATE `students` SET 
                                                    `id` = :set_id,
                                                    `program` = :program,
                                                    `email` = :email,
                                                    `password` = :password,
                                                    `photo` = :photo,
                                                    `year_level` = :year_level,
                                                    `status` = 'Active',
                                                    `temp_reference_no` = :temp_ref,
                                                    `personal_info` = :personal,
                                                    `academic_info` = :academic,
                                                    `roadmap` = :roadmap,
                                                    `requirements_data` = :requirements,
                                                    `medical_data` = :medical,
                                                    `scholarship_data` = :scholarship,
                                                    `payment_data` = :payment,
                                                    `helpdesk_data` = :helpdesk,
                                                    `enrollment_data` = :enrollment
                                                WHERE `id` = :where_id");
            $updateStudentStmt->execute([
                'set_id'   => $permId,
                'where_id' => $permId,
                'program'  => $record['course_code'],
                'email'    => $email,
                'password' => $hashedPassword,
                'photo'    => $photoFile,
                'year_level' => $appliedYearLevel,
                'temp_ref' => $refNum,
                'personal' => $personalInfoJson,
                'academic' => $academicInfoJson,
                'roadmap'  => $roadmapJson,
                'requirements' => $record['requirements_data'],
                'medical'  => $record['medical_data'],
                'scholarship' => $record['scholarship_data'],
                'payment'  => $record['payment_data'],
                'helpdesk' => $record['helpdesk_data'],
                'enrollment' => json_encode($itData)
            ]);
        } else {
            $insertStmt = $pdo->prepare("INSERT INTO `students` (
                                            `id`, `name`, `program`, `email`, `password`, `photo`, `year_level`, `status`,
                                            `must_change_password`, `temp_reference_no`, `personal_info`, `academic_info`, `roadmap`, `requirements_data`,
                                            `medical_data`, `scholarship_data`, `payment_data`, `helpdesk_data`, `enrollment_data`
                                         ) VALUES (
                                            :id, :name, :program, :email, :password, :photo, :year_level, 'Active',
                                            1, :temp_ref, :personal, :academic, :roadmap, :requirements,
                                            :medical, :scholarship, :payment, :helpdesk, :enrollment
                                         )");
            $insertStmt->execute([
                'id'      => $permId,
                'name'    => $studName,
                'program' => $record['course_code'],
                'email'   => $email,
                'password' => $hashedPassword,
                'photo'   => $photoFile,
                'year_level' => $appliedYearLevel,
                'temp_ref' => $refNum,
                'personal' => $personalInfoJson,
                'academic' => $academicInfoJson,
                'roadmap'  => $roadmapJson,
                'requirements' => $record['requirements_data'],
                'medical'  => $record['medical_data'],
                'scholarship' => $record['scholarship_data'],
                'payment'  => $record['payment_data'],
                'helpdesk' => $record['helpdesk_data'],
                'enrollment' => json_encode($itData)
            ]);
        }

        // Check existing snapshot in enrollments table by student name and course
        $checkEnroll = $pdo->prepare("SELECT COUNT(*) FROM `enrollments` WHERE `student` = :student AND `course` = :course");
        $checkEnroll->execute(['student' => $studName, 'course' => $record['course_code']]);
        if ($checkEnroll->fetchColumn() == 0) {
            $insertEnrollStmt = $pdo->prepare("INSERT INTO `enrollments` (`student`, `course`, `status`)
                                               VALUES (:student, :course, 'Enrolled')");
            $insertEnrollStmt->execute([
                'student' => $studName,
                'course'  => $record['course_code']
            ]);
        }

        // Soft-delete: mark as PROMOTED instead of hard DELETE to preserve audit trail
        $delStmt = $pdo->prepare("UPDATE `pre_enrollments` SET `status` = 'PROMOTED' WHERE `temp_student_id` = :ref");
        $delStmt->execute(['ref' => $refNum]);

        if ($startedTx) {
            $pdo->commit();
        }

        return [
            'referenceNumber' => $refNum,
            'applicantName'   => $studName,
            'program'         => $record['course_code'],
            'studentType'     => $record['student_type'] ?? 'FRESHMAN',
            'nstp'            => $record['nstp'] ?? 'N/A',
            'dateSubmitted'   => date('Y-m-d', strtotime($record['created_at'])),
            'status'          => 'ENROLLED',
            'reviewedToday'   => true,
            'personalInfo'    => [
                'birthDate' => $record['birth_date'],
                'gender'    => $record['gender'],
                'address'   => $record['address']
            ],
            'contactInfo'     => [
                'email'    => $record['email'],
                'phone'    => $record['phone'],
                'guardian' => $record['emergency_contact_name']
            ],
            'requirements'    => getRequirementsForType($record['student_type'], $record['shs_track']),
            'roadmap'         => json_decode($roadmapJson, true),
            'registrarNotes'  => $record['registrar_notes'] ?? 'Tracking steps established',
            'permanentId'     => $permId,
            'institutionalEmail' => $email,
            'password'           => $plainPassword,
            'assignedSection'    => $record['section_code'] ?? null
        ];

    } catch (Exception $e) {
        if ($startedTx && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
}
