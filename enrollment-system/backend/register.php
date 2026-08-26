<?php
/**
 * GNCP Enrollment Portal — API Registration Handler
 * Handles pre-enrollment data submission and database persistence.
 */

require_once __DIR__ . '/../../shared/backend/config/database.php';
require_once __DIR__ . '/../../shared/backend/utils/response.php';

// ─── GET: Fetch active academic period and program catalog ───────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'get_active_programs') {
    try {
        $pdo = Database::getInstance();
        
        // Fetch active academic period
        $periodStmt = $pdo->query("SELECT * FROM `academic_periods` WHERE `status` = 'Active' LIMIT 1");
        $activePeriod = $periodStmt->fetch();
        
        // Fetch all active programs
        $programs = $pdo->query("SELECT * FROM `programs` WHERE `status` = 'Active' ORDER BY `name` ASC")->fetchAll();
        
        sendResponse(true, [
            'activePeriod' => $activePeriod ? [
                'id' => (int)$activePeriod['id'],
                'name' => $activePeriod['name'],
                'academicYear' => $activePeriod['academic_year'],
                'semester' => $activePeriod['semester'],
                'enrollmentStart' => $activePeriod['enrollment_start'],
                'enrollmentEnd' => $activePeriod['enrollment_end'],
                'status' => $activePeriod['status']
            ] : null,
            'programs' => array_map(function($prog) {
                return [
                    'id' => (int)$prog['id'],
                    'code' => $prog['code'],
                    'name' => $prog['name'],
                    'department' => $prog['department'],
                    'status' => $prog['status']
                ];
            }, $programs)
        ]);
    } catch (PDOException $e) {
        error_log("Failed to fetch active programs: " . $e->getMessage());
        sendResponse(false, null, "Database error occurred.", 500);
    }
    exit;
}

// ─── GET: Look up a returning student by email (institutional or personal) ─────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'lookup_returning_student') {
    $identifier = trim($_GET['identifier'] ?? '');

    if (empty($identifier)) {
        sendResponse(false, null, 'An email address is required to verify your record.', 400);
        exit;
    }

    if (!filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
        sendResponse(false, null, 'Please enter a valid email address.', 400);
        exit;
    }

    try {
        $pdo = Database::getInstance();

        // Search by institutional email (students.email) OR personal email stored in personal_info JSON
        $stmt = $pdo->prepare(
            "SELECT `id`, `name`, `program`, `email`, `year_level`, `status`, `personal_info`
             FROM `students`
             WHERE (`email` = :identifier
                OR JSON_UNQUOTE(JSON_EXTRACT(`personal_info`, '\$.email')) = :identifier2)
             LIMIT 1"
        );
        $stmt->execute(['identifier' => $identifier, 'identifier2' => $identifier]);
        $student = $stmt->fetch();

        if (!$student) {
            sendResponse(false, null, 'No student record found with that email address. Please check and try again.', 404);
            exit;
        }

        if ($student['status'] === 'Active') {
            sendResponse(false, null, 'This student is already enrolled for the current semester.', 403);
            exit;
        }

        if ($student['status'] !== 'Inactive' && $student['status'] !== 'Re-enrolling') {
            sendResponse(false, null, 'This student record is not eligible for re-enrollment (current status: ' . htmlspecialchars($student['status']) . ').', 403);
            exit;
        }

        // Update status to 'Re-enrolling'
        $updateStmt = $pdo->prepare("UPDATE `students` SET `status` = 'Re-enrolling' WHERE `id` = :id");
        $updateStmt->execute(['id' => $student['id']]);
        $student['status'] = 'Re-enrolling';

        // Decode personal_info JSON blob for pre-filling the wizard
        $personalInfo = [];
        if (!empty($student['personal_info'])) {
            $personalInfo = json_decode($student['personal_info'], true) ?? [];
        }

        // Return only safe, public fields needed for pre-fill
        sendResponse(true, [
            'id'          => $student['id'],
            'name'        => $student['name'],
            'program'     => $student['program'],
            'email'       => $student['email'],
            'year_level'  => $student['year_level'],
            'status'      => $student['status'],
            'firstName'   => $personalInfo['firstName']   ?? '',
            'middleName'  => $personalInfo['middleName']  ?? '',
            'lastName'    => $personalInfo['lastName']    ?? '',
            'phone'       => $personalInfo['phone']       ?? '',
            'birthDate'   => $personalInfo['birthDate']   ?? '',
            'gender'      => $personalInfo['gender']      ?? '',
            'address'     => $personalInfo['address']     ?? '',
        ]);
    } catch (PDOException $e) {
        error_log('[lookup_returning_student] DB error: ' . $e->getMessage());
        sendResponse(false, null, 'Database error occurred.', 500);
    }
    exit;
}

// Ensure it's a POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, null, 'Method not allowed. Use POST.', 405);
}

// Get raw input body or form_data field if multipart/form-data
$rawInput = file_get_contents('php://input');
$formData = json_decode($rawInput, true);

if (!empty($_POST['form_data'])) {
    $formData = json_decode($_POST['form_data'], true);
}

if ($rawInput && !$formData && json_last_error() !== JSON_ERROR_NONE) {
    sendResponse(false, null, 'Invalid JSON payload received.', 400);
}

if (empty($formData)) {
    sendResponse(false, null, 'No input data provided.', 400);
}

try {
    $pdo = Database::getInstance();

    // Check if there is an active academic period and today is within enrollment window
    $todayDate = date('Y-m-d');
    $periodStmt = $pdo->query("SELECT * FROM `academic_periods` WHERE `status` = 'Active' LIMIT 1");
    $activePeriod = $periodStmt->fetch();
    
    if (!$activePeriod) {
        sendResponse(false, null, 'Enrollment is currently closed. No active academic period is configured.', 403);
    }
    
    if (!empty($activePeriod['enrollment_start']) && !empty($activePeriod['enrollment_end'])) {
        if ($todayDate < $activePeriod['enrollment_start'] || $todayDate > $activePeriod['enrollment_end']) {
            sendResponse(false, null, "Enrollment is currently closed. The active registration window is from " . date('F j, Y', strtotime($activePeriod['enrollment_start'])) . " to " . date('F j, Y', strtotime($activePeriod['enrollment_end'])) . ".", 403);
        }
    }

    // ─── RETURNING student: verify existence and eligibility before proceeding ────
    $isReturning      = ($formData['studentType'] ?? '') === 'RETURNING';
    $existingStudentId = trim($formData['existingStudentId'] ?? '');

    if ($isReturning) {
        if (empty($existingStudentId)) {
            sendResponse(false, null, 'Existing Student ID is required for returning students.', 422);
            exit;
        }

        // 1a. Check the student exists in the official students table
        $checkStmt = $pdo->prepare(
            "SELECT `id`, `status` FROM `students` WHERE `id` = :id LIMIT 1"
        );
        $checkStmt->execute(['id' => $existingStudentId]);
        $existingStudent = $checkStmt->fetch();

        if (!$existingStudent) {
            sendResponse(false, null, 'Returning student verification failed: No record found for ID "' . htmlspecialchars($existingStudentId) . '".', 404);
            exit;
        }

        if ($existingStudent['status'] !== 'Inactive' && $existingStudent['status'] !== 'Re-enrolling') {
            sendResponse(false, null, 'Student ID "' . htmlspecialchars($existingStudentId) . '" is not eligible for re-enrollment (status: ' . htmlspecialchars($existingStudent['status']) . ').', 403);
            exit;
        }

        // 1b. Prevent duplicate re-enrollment applications for the same student this period
        $dupStmt = $pdo->prepare(
            "SELECT `id` FROM `pre_enrollments`
             WHERE `existing_student_id` = :sid
               AND `status` NOT IN ('CANCELLED', 'REJECTED')
             LIMIT 1"
        );
        $dupStmt->execute(['sid' => $existingStudentId]);
        if ($dupStmt->fetch()) {
            sendResponse(false, null, 'A pending re-enrollment application already exists for Student ID "' . htmlspecialchars($existingStudentId) . '".', 409);
            exit;
        }
    }

    // ─── DUPLICATE CHECK: Prevent new-student duplicate applications by email ────
    $applicantEmail = trim($formData['email'] ?? '');
    if (!empty($applicantEmail)) {
        $dupEmailStmt = $pdo->prepare(
            "SELECT `temp_student_id` FROM `pre_enrollments`
             WHERE `email` = :email AND `status` NOT IN ('CANCELLED', 'Rejected')
             LIMIT 1"
        );
        $dupEmailStmt->execute(['email' => $applicantEmail]);
        if ($dupEmailStmt->fetch()) {
            sendResponse(false, null, 'A pre-enrollment application with this email address already exists. If you need to check your status, use your tracking credentials.', 409);
            exit;
        }
    }

    // 1. Generate Tracking Credentials
    $randomDigits = str_pad(rand(100000, 999999), 6, '0', STR_PAD_LEFT);
    $tempStudentId = "GNCP-2026-" . $randomDigits; // Using GNCP prefix as researched
    $tempPin = str_pad(rand(100000, 999999), 6, '0', STR_PAD_LEFT);
    $now = date('Y-m-d H:i:s');

    // 2. Generate initial roadmap — branched by student type
    $hasScholarship = ($formData['scholarship'] ?? 'NONE') !== 'NONE';

    if ($isReturning) {
        // Returning students: skip Academic Advising (already have a course record).
        // IT Center issues ID renewal instead of initial ID creation.
        $roadmap = [
            [
                'stepId'      => 'online_registration',
                'station'     => 'Online Portal',
                'location'    => 'Remote',
                'title'       => 'Online Re-Enrollment Application',
                'description' => 'Program and payment selection, contact details update.',
                'status'      => 'COMPLETED',
                'updatedAt'   => date('c')
            ],
            [
                'stepId'      => 'registrar_verification',
                'station'     => 'Registrar Desk',
                'location'    => 'Room 1109',
                'title'       => 'Registrar — Academic Evaluation & Re-Admission',
                'description' => 'Submit GNCP clearance form and Registrar evaluation form. Registrar reviews academic standing and validates re-admission eligibility.',
                'status'      => 'PENDING',
                'updatedAt'   => null
            ],
            [
                'stepId'      => 'clinic_checkup',
                'station'     => 'School Clinic',
                'location'    => 'Room 1105',
                'title'       => 'Clinic — Medical Clearance Update',
                'description' => 'Brief medical interview and health status update for returning students.',
                'status'      => 'PENDING',
                'updatedAt'   => null
            ],
            [
                'stepId'      => 'cashier_payment',
                'station'     => 'Treasury / Cashier',
                'location'    => 'Room 1111',
                'title'       => 'Treasury / Cashier — Payment',
                'description' => 'Pay tuition and miscellaneous fees to clear billing ledger balance.',
                'status'      => 'PENDING',
                'updatedAt'   => null
            ],
            [
                'stepId'      => 'id_email_final',
                'station'     => 'Student Portal Account Activation',
                'location'    => 'IT Desk',
                'title'       => 'Student Portal Account Activation',
                'description' => 'Activate your official student portal credentials to complete enrollment.',
                'status'      => 'PENDING',
                'updatedAt'   => null
            ]
        ];
    } else {
        // Standard freshman / transferee 6-step roadmap
        $roadmap = [
            [
                'stepId'      => 'online_registration',
                'station'     => 'Online Portal',
                'location'    => 'Remote',
                'title'       => 'Online Pre-Registration',
                'description' => 'Program selection, personal details, academic history, and medical questionnaire.',
                'status'      => 'COMPLETED',
                'updatedAt'   => date('c')
            ],
            [
                'stepId'      => 'registrar_verification',
                'station'     => 'Registrar Desk',
                'location'    => 'Room 1109',
                'title'       => 'Registrar Verification & Admission',
                'description' => 'Submit original hardcopy documents for validation and official admission approval.',
                'status'      => 'PENDING',
                'updatedAt'   => null
            ],
            [
                'stepId'      => 'advising_assessment',
                'station'     => 'Advising Desk',
                'location'    => 'Room 1107',
                'title'       => 'Academic Advising & NSTP Confirmation',
                'description' => 'Review academic program selection and confirm the student\'s NSTP choice (CWTS/ROTC/LTS) in case they change their mind.',
                'status'      => 'PENDING',
                'updatedAt'   => null
            ],
            [
                'stepId'      => 'clinic_checkup',
                'station'     => 'School Clinic',
                'location'    => 'Room 1105',
                'title'       => 'School Clinic — Medical Clearance',
                'description' => 'Physical exam and medical interview for fitness clearance.',
                'status'      => 'PENDING',
                'updatedAt'   => null
            ],
            [
                'stepId'      => 'cashier_payment',
                'station'     => 'Treasury / Cashier',
                'location'    => 'Room 1111',
                'title'       => 'Treasury / Cashier — Payment',
                'description' => 'Pay tuition and miscellaneous fees to clear billing ledger balance.',
                'status'      => 'PENDING',
                'updatedAt'   => null
            ],
            [
                'stepId'      => 'id_email_final',
                'station'     => 'Student Portal Account Activation',
                'location'    => 'IT Desk',
                'title'       => 'Student Portal Account Activation',
                'description' => 'Activate your official student portal credentials to complete enrollment.',
                'status'      => 'PENDING',
                'updatedAt'   => null
            ]
        ];
    }

    // Process uploaded document soft copies if provided
    $uploadedFilesMeta = [];
    $uploadDir = __DIR__ . '/../../uploads/documents/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    if (!empty($_FILES)) {
        foreach ($_FILES as $inputKey => $fileInfo) {
            if (strpos($inputKey, 'doc_') === 0 && $fileInfo['error'] === UPLOAD_ERR_OK && $fileInfo['size'] > 0 && $fileInfo['size'] <= 10 * 1024 * 1024) {
                $rawKey = substr($inputKey, 4);
                $ext = strtolower(pathinfo($fileInfo['name'], PATHINFO_EXTENSION));
                
                // Strictly accept PDF files only
                if ($ext === 'pdf') {
                    $finfo = finfo_open(FILEINFO_MIME_TYPE);
                    $detectedMime = finfo_file($finfo, $fileInfo['tmp_name']);
                    finfo_close($finfo);

                    // Check MIME type
                    if ($detectedMime === 'application/pdf') {
                        // Check %PDF- header magic bytes
                        $handle = @fopen($fileInfo['tmp_name'], 'rb');
                        $header = $handle ? @fread($handle, 5) : '';
                        if ($handle) @fclose($handle);

                        if ($header === '%PDF-') {
                            $rawContent = @file_get_contents($fileInfo['tmp_name']);
                            // Reject executable script injections
                            if (!$rawContent || !preg_match('/<\?php|<\?=|<script\b|eval\s*\(|base64_decode\s*\(/i', $rawContent)) {
                                $sanitizedKey = preg_replace('/[^a-zA-Z0-9_]/', '', $rawKey);
                                $randomToken = bin2hex(random_bytes(8));
                                $newFileName = 'doc_' . $sanitizedKey . '_' . preg_replace('/[^a-zA-Z0-9_]/', '', $tempStudentId) . '_' . $randomToken . '.pdf';
                                $targetPath = $uploadDir . $newFileName;
                                
                                if (is_uploaded_file($fileInfo['tmp_name']) && move_uploaded_file($fileInfo['tmp_name'], $targetPath)) {
                                    $uploadedFilesMeta[$sanitizedKey] = [
                                        'fileName'   => basename($fileInfo['name']),
                                        'filePath'   => 'uploads/documents/' . $newFileName,
                                        'fileType'   => 'application/pdf',
                                        'uploadedAt' => date('Y-m-d H:i:s')
                                    ];
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    $requirementsDocs = [
        'psa'        => isset($uploadedFilesMeta['psa']) ? 'submitted' : 'not-submitted',
        'reportCard' => isset($uploadedFilesMeta['reportCard']) ? 'submitted' : 'not-submitted',
        'goodMoral'  => isset($uploadedFilesMeta['goodMoral']) ? 'submitted' : 'not-submitted'
    ];
    foreach ($uploadedFilesMeta as $k => $v) {
        $requirementsDocs[$k] = 'submitted';
    }

    // Seed defaults matching Vue client models
    $requirementsData = [
        'status' => 'PENDING',
        'docs'   => $requirementsDocs,
        'files'  => $uploadedFilesMeta,
        'notes'  => '',
        'verifiedBy'   => '',
        'dateVerified' => ''
    ];

    $medicalData = [
        'status' => 'pending',
        'physicalExam' => 'not-assessed',
        'medicalInterview' => 'not-assessed',
        'peFitness' => 'not-assessed',
        'nstpFitness' => 'not-assessed',
        'notes' => '',
        'verifiedBy' => '',
        'dateVerified' => ''
    ];

    $scholarshipData = [
        'status' => 'PENDING',
        'notes' => '',
        'verifiedBy' => '',
        'dateVerified' => ''
    ];

    $paymentData = [
        'status' => 'PENDING',
        'totalFee' => 24000,
        'amountPaid' => 0,
        'balance' => 24000,
        'paymentType' => $formData['paymentMode'] ?? 'Cash',
        'transactionRef' => '',
        'notes' => '',
        'verifiedBy' => '',
        'dateVerified' => ''
    ];

    $helpdeskData = [
        'nstp' => $formData['nstp'] ?? 'CWTS',
        'hasScholarship' => $hasScholarship,
        'scholarshipName' => $formData['scholarship'] ?? 'NONE',
        'isWalkIn' => false,
        'status' => 'PENDING',
        'tlcNotes' => ''
    ];

    // Convert arrays/objects to strings for database storage
    $conditionsStr = is_array($formData['medicalConditions'] ?? null) 
        ? implode(', ', $formData['medicalConditions']) 
        : (string)($formData['medicalConditions'] ?? '');
    
    $roadmapJson = json_encode($roadmap);
    $requirementsJson = json_encode($requirementsData);
    $medicalJson = json_encode($medicalData);
    $scholarshipJson = json_encode($scholarshipData);
    $paymentJson = json_encode($paymentData);
    $helpdeskJson = json_encode($helpdeskData);

    // 3. Insert into the database
    $sql = "INSERT INTO `pre_enrollments` (
                `temp_student_id`, `temp_pin`, `student_type`, `course_code`, `nstp`,
                `first_name`, `middle_name`, `last_name`, `email`, `phone`, `birth_date`, `gender`, `address`,
                `elementary_school`, `junior_high_school`, `senior_high_school`, `previous_college`, `shs_track`, `honors`,
                `health_status`, `medical_conditions`, `allergies`, `current_medication`, `medication_details`,
                `fitness_participation`, `emergency_contact_name`, `emergency_contact_phone`,
                `payment_mode`, `scholarship`, `status`, `roadmap`,
                `requirements_data`, `medical_data`, `scholarship_data`, `payment_data`, `helpdesk_data`,
                `existing_student_id`, `year_level_applied`
            ) VALUES (
                :temp_student_id, :temp_pin, :student_type, :course_code, :nstp,
                :first_name, :middle_name, :last_name, :email, :phone, :birth_date, :gender, :address,
                :elementary_school, :junior_high_school, :senior_high_school, :previous_college, :shs_track, :honors,
                :health_status, :medical_conditions, :allergies, :current_medication, :medication_details,
                :fitness_participation, :emergency_contact_name, :emergency_contact_phone,
                :payment_mode, :scholarship, 'PRE_REGISTERED', :roadmap,
                :requirements_data, :medical_data, :scholarship_data, :payment_data, :helpdesk_data,
                :existing_student_id, :year_level_applied
            )";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        'temp_student_id'        => $tempStudentId,
        'temp_pin'               => $tempPin,
        'student_type'           => $formData['studentType'] ?? '',
        'course_code'            => $formData['courseCode'] ?? '',
        'nstp'                   => $formData['nstp'] ?? '',
        'first_name'             => $formData['firstName'] ?? '',
        'middle_name'            => $formData['middleName'] ?? null,
        'last_name'              => $formData['lastName'] ?? '',
        'email'                  => $formData['email'] ?? '',
        'phone'                  => $formData['phone'] ?? '',
        'birth_date'             => $formData['birthDate'] ?? '',
        'gender'                 => $formData['gender'] ?? '',
        'address'                => $formData['address'] ?? '',
        'elementary_school'      => $formData['elementarySchool'] ?? '',
        'junior_high_school'     => $formData['juniorHighSchool'] ?? '',
        'senior_high_school'     => $formData['seniorHighSchool'] ?? '',
        'previous_college'       => ($formData['studentType'] ?? '') === 'TRANSFEREE' ? ($formData['previousCollege'] ?? null) : null,
        'shs_track'              => $formData['shsTrack'] ?? null,
        'honors'                 => $formData['honors'] ?? null,
        'health_status'          => $formData['healthStatus'] ?? 'GOOD',
        'medical_conditions'     => $conditionsStr,
        'allergies'              => $formData['allergies'] ?? null,
        'current_medication'     => ($formData['currentMedication'] ?? false) ? 1 : 0,
        'medication_details'     => $formData['medicationDetails'] ?? null,
        'fitness_participation'  => ($formData['fitnessParticipation'] ?? true) ? 1 : 0,
        'emergency_contact_name' => $formData['emergencyContactName'] ?? '',
        'emergency_contact_phone'=> $formData['emergencyContactPhone'] ?? '',
        'payment_mode'           => $formData['paymentMode'] ?? '',
        'scholarship'            => $formData['scholarship'] ?? 'NONE',
        'roadmap'                => $roadmapJson,
        'requirements_data'      => $requirementsJson,
        'medical_data'           => $medicalJson,
        'scholarship_data'       => $scholarshipJson,
        'payment_data'           => $paymentJson,
        'helpdesk_data'          => $helpdeskJson,
        'existing_student_id'    => $isReturning ? $existingStudentId : null,
        'year_level_applied'     => !empty($formData['yearLevelApplied']) ? $formData['yearLevelApplied'] : null
    ]);

    // 4. Return success response envelope
    sendResponse(true, [
        'tempStudentId' => $tempStudentId,
        'tempPin'       => $tempPin,
        'status'        => 'PRE_REGISTERED',
        'roadmap'       => $roadmap
    ], null, 201);

} catch (PDOException $e) {
    error_log("Database insertion failed: " . $e->getMessage());
    sendResponse(false, null, "Database transaction error occurred. Please try again.", 500);
}
