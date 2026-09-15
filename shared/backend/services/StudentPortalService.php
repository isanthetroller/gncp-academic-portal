<?php
/**
 * GNCP Academic Portal — Student Portal Domain Service
 * Encapsulates authentication, dashboard compilation, COR generation,
 * profile updates, and password reset flows for student accounts.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../utils/logger.php';
require_once __DIR__ . '/../utils/student.php';
require_once __DIR__ . '/../utils/rate_limit.php';
require_once __DIR__ . '/../utils/session_guard.php';
require_once __DIR__ . '/EmailService.php';
require_once __DIR__ . '/AssessmentService.php';
require_once __DIR__ . '/MilestoneService.php';
require_once __DIR__ . '/AnnouncementService.php';

class StudentPortalService {

    public static function maskEmailAddress(?string $email): string {
        if (!$email || strpos($email, '@') === false) return '***@***.com';
        list($name, $domain) = explode('@', $email, 2);
        $len = strlen($name);
        if ($len <= 2) {
            $maskedName = substr($name, 0, 1) . '*';
        } else {
            $maskedName = substr($name, 0, 1) . str_repeat('*', min(5, $len - 2)) . substr($name, -1);
        }
        return $maskedName . '@' . $domain;
    }

    public static function login(PDO $pdo, string $studentId, string $password): array {
        $studentId = trim($studentId);
        $password = trim($password);

        if (!$studentId || !$password) {
            return ['success' => false, 'message' => 'Student ID and password are required.', 'code' => 400];
        }

        // Rate limit: max 10 failed attempts per IP/student per 5 minutes
        checkLoginRateLimit('student_login', $studentId, 10, 300);

        // Lookup in students directory by ID, Email, or Reference Number
        $stmt = $pdo->prepare("
            SELECT * FROM `students` 
            WHERE LOWER(`id`) = LOWER(:id1) 
               OR LOWER(`email`) = LOWER(:id2) 
               OR LOWER(COALESCE(`temp_reference_no`, '')) = LOWER(:id3) 
            LIMIT 1
        ");
        $stmt->execute(['id1' => $studentId, 'id2' => $studentId, 'id3' => $studentId]);
        $student = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$student) {
            recordLoginFailure('student_login', $studentId, 10, 300);
            return ['success' => false, 'message' => 'Invalid Student ID or password.', 'code' => 401];
        }

        $isValid = password_verify($password, $student['password']);

        // One-time legacy migration fallback for unhashed passwords
        if (!$isValid && !empty($student['password']) && substr($student['password'], 0, 4) !== '$2y$') {
            if ($password === $student['password']) {
                $isValid = true;
                $rehashed = password_hash($password, PASSWORD_DEFAULT);
                $pdo->prepare("UPDATE `students` SET `password` = :pwd WHERE `id` = :id")
                    ->execute(['pwd' => $rehashed, 'id' => $student['id']]);
            }
        }

        if (!$isValid) {
            recordLoginFailure('student_login', $studentId, 10, 300);
            return ['success' => false, 'message' => 'Invalid Student ID or password.', 'code' => 401];
        }

        clearLoginFailures('student_login', $studentId);

        // Single-Active Session Token Generation
        $activeSessionToken = bin2hex(random_bytes(32));
        $clientIp = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $clientIp = trim(explode(',', $clientIp)[0]);

        try {
            $tokenStmt = $pdo->prepare("
                UPDATE `students` 
                SET `active_session_token` = :token, `last_login_at` = NOW(), `last_login_ip` = :ip 
                WHERE `id` = :id
            ");
            $tokenStmt->execute(['token' => $activeSessionToken, 'ip' => $clientIp, 'id' => $student['id']]);
        } catch (Exception $e) {
            error_log('[StudentPortalService::LoginTokenUpdate] ' . $e->getMessage());
        }

        $mustChange = (bool)($student['must_change_password'] ?? false);

        initSession();
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
            $_SESSION = [];
            $_SESSION['last_activity'] = time();
            $_SESSION['gncp_student'] = [
                'id'                   => $student['id'],
                'name'                 => $student['name'],
                'email'                => $student['email'],
                'role'                 => 'STUDENT',
                'session_token'        => $activeSessionToken,
                'must_change_password' => $mustChange
            ];
            session_write_close();
        }

        return [
            'success' => true,
            'data'    => [
                'id'                   => $student['id'],
                'name'                 => $student['name'],
                'program'              => $student['program'],
                'email'                => $student['email'],
                'photo'                => $student['photo'],
                'must_change_password' => $mustChange
            ],
            'message' => 'Student authenticated successfully.'
        ];
    }

    public static function getStudentDashboard(PDO $pdo, string $studentId): array {
        $studentId = trim($studentId);
        if (!$studentId) {
            return ['success' => false, 'message' => 'Student ID is required.', 'code' => 400];
        }

        $stmt = $pdo->prepare("SELECT * FROM `students` WHERE `id` = :id LIMIT 1");
        $stmt->execute(['id' => $studentId]);
        $student = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$student) {
            return ['success' => false, 'message' => 'Student profile not found.', 'code' => 404];
        }

        $roadmap      = json_decode($student['roadmap'] ?? '[]', true) ?: [];
        $requirements = json_decode($student['requirements_data'] ?? '{}', true) ?: null;
        $medical      = json_decode($student['medical_data'] ?? '{}', true) ?: null;
        $scholarship  = json_decode($student['scholarship_data'] ?? '{}', true) ?: null;
        $payment      = json_decode($student['payment_data'] ?? '{}', true) ?: null;
        $helpdesk     = json_decode($student['helpdesk_data'] ?? '{}', true) ?: null;
        $enrollment   = json_decode($student['enrollment_data'] ?? '{}', true) ?: null;
        $personalInfo = json_decode($student['personal_info'] ?? '{}', true) ?: [];

        // Dual-table fallback: If student JSON blobs are incomplete, merge from pre_enrollments staging queue
        $refLookup = !empty($student['temp_reference_no']) ? $student['temp_reference_no'] : $student['id'];
        $peStmt = $pdo->prepare("SELECT * FROM `pre_enrollments` WHERE `temp_student_id` = :r1 OR `existing_student_id` = :r2 LIMIT 1");
        $peStmt->execute(['r1' => $refLookup, 'r2' => $student['id']]);
        $preEnrollment = $peStmt->fetch(PDO::FETCH_ASSOC);

        if ($preEnrollment) {
            if (empty($personalInfo['firstName']))             $personalInfo['firstName']             = $preEnrollment['first_name'] ?? '';
            if (empty($personalInfo['middleName']))            $personalInfo['middleName']            = $preEnrollment['middle_name'] ?? '';
            if (empty($personalInfo['lastName']))              $personalInfo['lastName']              = $preEnrollment['last_name'] ?? '';
            if (empty($personalInfo['phone']))                 $personalInfo['phone']                 = $preEnrollment['phone'] ?? '';
            if (empty($personalInfo['birthDate']))             $personalInfo['birthDate']             = $preEnrollment['birth_date'] ?? '';
            if (empty($personalInfo['gender']))                $personalInfo['gender']                = $preEnrollment['gender'] ?? '';
            if (empty($personalInfo['address']))               $personalInfo['address']               = $preEnrollment['address'] ?? '';
            if (empty($personalInfo['emergencyContactName']))  $personalInfo['emergencyContactName']  = $preEnrollment['emergency_contact_name'] ?? '';
            if (empty($personalInfo['emergencyContactPhone'])) $personalInfo['emergencyContactPhone'] = $preEnrollment['emergency_contact_phone'] ?? '';

            if (empty($helpdesk) && !empty($preEnrollment['helpdesk_data'])) {
                $helpdesk = json_decode($preEnrollment['helpdesk_data'], true) ?: null;
            }
            if (empty($payment) && !empty($preEnrollment['payment_data'])) {
                $payment = json_decode($preEnrollment['payment_data'], true) ?: null;
            }
            if (empty($medical) && !empty($preEnrollment['medical_data'])) {
                $medical = json_decode($preEnrollment['medical_data'], true) ?: null;
            }
            if (empty($requirements) && !empty($preEnrollment['requirements_data'])) {
                $requirements = json_decode($preEnrollment['requirements_data'], true) ?: null;
            }
            if (empty($scholarship) && !empty($preEnrollment['scholarship_data'])) {
                $scholarship = json_decode($preEnrollment['scholarship_data'], true) ?: null;
            }
            if (empty($roadmap) && !empty($preEnrollment['roadmap'])) {
                $roadmap = json_decode($preEnrollment['roadmap'], true) ?: [];
            }
        }

        // Active semester & academic year
        $semStmt = $pdo->query("SELECT `id`, `semester`, `academic_year` FROM `academic_periods` WHERE `status` = 'Active' LIMIT 1");
        $activePeriod = $semStmt ? $semStmt->fetch(PDO::FETCH_ASSOC) : null;
        $activeSemester = $activePeriod ? $activePeriod['semester'] : '1st Semester';
        $academicYear   = $activePeriod ? $activePeriod['academic_year'] : '2026-2027';

        // Program name
        $progStmt = $pdo->prepare("SELECT name FROM `programs` WHERE code = :code LIMIT 1");
        $progStmt->execute(['code' => $student['program']]);
        $progRow = $progStmt->fetch(PDO::FETCH_ASSOC);
        $programName = $progRow ? $progRow['name'] : $student['program'];

        // Prospectus subjects
        $curriculumVersion = $student['curriculum_version'] ?? ($preEnrollment['curriculum_version'] ?? '2026 Revised Curriculum');
        $subjects = getCurriculumSubjects($pdo, $student['program'], $student['year_level'], $activeSemester, $curriculumVersion);

        // Build schedule & fee calculations for official COR
        $advisedSubjects = !empty($helpdesk['advisedSubjects']) ? $helpdesk['advisedSubjects'] : $subjects;
        $schedule = [];
        $totalUnits = 0.00;
        $totalLabFee = 0.00;

        $studentSection = !empty($enrollment['assignedSection']) ? $enrollment['assignedSection'] 
                        : (!empty($helpdesk['section']) ? $helpdesk['section'] 
                        : (!empty($student['section_code']) ? $student['section_code'] 
                        : (!empty($preEnrollment['section_code']) ? $preEnrollment['section_code'] : '')));

        foreach ($advisedSubjects as $sub) {
            $subTitle = $sub['title'] ?? $sub['name'] ?? '';
            $subCode = $sub['code'] ?? '';

            $sectionRow = null;
            if (!empty($studentSection)) {
                $cleanSec = preg_replace('/^(BSCpE|BSCOE|BSIT|BSCS|BSBA|BSED)-[1-4][A-Z]-?/i', '', $studentSection);
                $secStmtSect = $pdo->prepare("
                    SELECT * FROM `subject_sections` 
                    WHERE (`code` LIKE :sect_pattern OR `code` LIKE :clean_pattern) AND (`subject` = :title OR `code` LIKE :code_pattern)
                    LIMIT 1
                ");
                $secStmtSect->execute([
                    'sect_pattern' => '%' . $studentSection . '%',
                    'clean_pattern' => '%' . $cleanSec . '%',
                    'title' => $subTitle,
                    'code_pattern' => '%' . $subCode . '%'
                ]);
                $sectionRow = $secStmtSect ? $secStmtSect->fetch(PDO::FETCH_ASSOC) : null;
            }
            if (!$sectionRow) {
                $secStmt = $pdo->prepare("
                    SELECT * FROM `subject_sections` 
                    WHERE (`subject` = :title OR `code` LIKE :code_pattern)
                    LIMIT 1
                ");
                $secStmt->execute([
                    'title' => $subTitle,
                    'code_pattern' => '%' . $subCode . '%'
                ]);
                $sectionRow = $secStmt ? $secStmt->fetch(PDO::FETCH_ASSOC) : null;
            }

            $lec = isset($sub['lecture_units']) ? (float)$sub['lecture_units'] : (isset($sub['lectureUnits']) ? (float)$sub['lectureUnits'] : 3.00);
            $lab = isset($sub['lab_units']) ? (float)$sub['lab_units'] : (isset($sub['labUnits']) ? (float)$sub['labUnits'] : 0.00);
            $units = $lec + $lab;
            $totalUnits += $units;
            $totalLabFee += isset($sub['lab_fee']) ? (float)$sub['lab_fee'] : (isset($sub['labFee']) ? (float)$sub['labFee'] : 0.00);

            $resolvedSection = !empty($studentSection) ? $studentSection : (!empty($sectionRow['code']) ? $sectionRow['code'] : 'TBA');

            if ($sectionRow) {
                $timeStr = $sectionRow['time'] ?? 'TBA';
                $startTime = ''; $endTime = '';
                if (strpos($timeStr, ' - ') !== false) {
                    $timeParts = explode(' - ', $timeStr);
                    $startTime = $timeParts[0];
                    $endTime = $timeParts[1];
                } else {
                    $startTime = $timeStr;
                }
                $schedule[] = [
                    'code'        => $subCode,
                    'description' => $subTitle,
                    'units'       => number_format($units, 2),
                    'type'        => $lab > 0 ? 'Lec/Lab' : 'Lec',
                    'days'        => $sectionRow['days'] ?? 'MWF',
                    'start'       => $startTime ?: '08:00 AM',
                    'end'         => $endTime ?: '11:00 AM',
                    'section'     => $resolvedSection,
                    'room'        => $sectionRow['room'] ?? 'Lab 1',
                    'instructor'  => $sectionRow['instructor'] ?? 'Prof. Staff',
                    's'           => ''
                ];
            } else {
                $schedule[] = [
                    'code'        => $subCode,
                    'description' => $subTitle,
                    'units'       => number_format($units, 2),
                    'type'        => $lab > 0 ? 'Lec/Lab' : 'Lec',
                    'days'        => 'TBA',
                    'start'       => 'TBA',
                    'end'         => 'TBA',
                    'section'     => $resolvedSection,
                    'room'        => 'TBA',
                    'instructor'  => 'TBA',
                    's'           => ''
                ];
            }
        }

        // Authoritative Assessment Calculation
        $nstpType = strtoupper($preEnrollment['nstp'] ?? $student['nstp'] ?? 'NONE');
        $discount = (float)($scholarship['discount'] ?? 0.00);
        $snapshot = $enrollment['assessmentSnapshot'] ?? $payment['assessmentSnapshot'] ?? null;

        $assessment = AssessmentService::calculateAssessment($pdo, $advisedSubjects, $nstpType, $discount, $snapshot);

        // Authoritative Cashier Payment Resolution
        $rawAmountPaid = 0.00;
        if (!empty($payment)) {
            $rawAmountPaid = (float)($payment['amountPaid'] ?? $payment['amount_paid'] ?? $payment['amount'] ?? 0.00);
            if ($rawAmountPaid <= 0) {
                $txList = !empty($payment['payments']) && is_array($payment['payments'])
                    ? $payment['payments']
                    : (!empty($payment['history']) && is_array($payment['history']) ? $payment['history'] : []);
                foreach ($txList as $p) {
                    $pStatus = strtoupper(trim($p['status'] ?? 'PAID'));
                    if ($pStatus !== 'VOIDED' && $pStatus !== 'CANCELLED') {
                        $rawAmountPaid += (float)($p['amountPaid'] ?? $p['amount'] ?? 0.00);
                    }
                }
            }
        }
        $amountPaid = max(0.00, round($rawAmountPaid, 2));

        $totalFee = (float)($payment['totalFee'] ?? $assessment['cashTotal']);
        if ($totalFee <= 0) {
            $totalFee = (float)$assessment['cashTotal'];
        }

        $balance = isset($payment['balance']) ? (float)$payment['balance'] : max(0.00, round($totalFee - $amountPaid, 2));
        if ($amountPaid >= $totalFee && $totalFee > 0) {
            $balance = 0.00;
        }

        $paymentStatus = strtoupper(trim($payment['status'] ?? ''));
        if (!$paymentStatus) {
            $paymentStatus = ($balance <= 0 && $amountPaid > 0) ? 'PAID' : ($amountPaid > 0 ? 'PARTIAL' : 'UNPAID');
        } elseif ($balance <= 0 && $amountPaid > 0) {
            $paymentStatus = 'PAID';
        }

        $orNumber = $payment['orNumber'] ?? $payment['or_number'] ?? $payment['transactionRef'] ?? $student['or_number'] ?? ($preEnrollment['or_number'] ?? null);
        $encoder  = $payment['processedBy'] ?? $payment['verifiedBy'] ?? $student['cashier_name'] ?? ($preEnrollment['cashier_name'] ?? 'cashier');
        $paymentMode = $payment['paymentMode'] ?? $payment['paymentType'] ?? $student['payment_mode'] ?? ($preEnrollment['payment_mode'] ?? 'Full');

        $paymentSchedule = AssessmentService::calculatePaymentSchedule($assessment['cashTotal'], $assessment['installmentTotal'], $paymentMode, $payment ?: $amountPaid);

        $nameParts = explode(' ', trim($student['name'] ?? ''));
        $lastName  = !empty($personalInfo['lastName']) ? $personalInfo['lastName'] : (!empty($preEnrollment['last_name']) ? $preEnrollment['last_name'] : (count($nameParts) > 1 ? end($nameParts) : $student['name']));
        $firstName = !empty($personalInfo['firstName']) ? $personalInfo['firstName'] : (!empty($preEnrollment['first_name']) ? $preEnrollment['first_name'] : (count($nameParts) > 1 ? implode(' ', array_slice($nameParts, 0, -1)) : $student['name']));
        $middleName= !empty($personalInfo['middleName']) ? $personalInfo['middleName'] : (!empty($preEnrollment['middle_name']) ? $preEnrollment['middle_name'] : '');

        $corData = [
            'studentNo'        => $student['id'],
            'tempReferenceNo'  => $student['temp_reference_no'] ?? $student['id'],
            'lastName'         => $lastName,
            'firstName'        => $firstName,
            'middleName'       => $middleName,
            'courseCode'       => $student['program'],
            'programName'      => $programName,
            'curriculumVersion'=> $curriculumVersion,
            'address'          => $personalInfo['address'] ?? '---',
            'phone'            => $personalInfo['phone'] ?? '---',
            'yearLevel'        => $student['year_level'] ?? '1st Year',
            'gender'           => $personalInfo['gender'] ?? '---',
            'semester'         => $activeSemester,
            'academicYear'     => $academicYear,
            'schedule'         => $schedule,
            'totalUnits'       => number_format($totalUnits, 2),
            'tuitionFee'       => $assessment['tuitionFee'],
            'totalLabFee'      => $assessment['totalLabFee'],
            'miscFee'          => $assessment['miscFee'],
            'lmsFee'           => $assessment['lmsFee'],
            'nstpFee'          => $assessment['nstpFee'],
            'omrFee'           => $assessment['omrFee'],
            'discount'         => $discount,
            'cashTotal'        => $assessment['cashTotal'],
            'installmentCharge'=> $assessment['installmentCharge'],
            'installmentTotal' => $assessment['installmentTotal'],
            'paymentMode'      => $paymentMode,
            'paymentSchedule'  => $paymentSchedule,
            'totalFee'         => $totalFee,
            'amountPaid'       => $amountPaid,
            'balance'          => $balance,
            'paymentStatus'    => $paymentStatus,
            'financialStatus'  => $balance <= 0 ? 'CLEARED' : 'PENDING_BALANCE',
            'orNumber'         => $orNumber,
            'encoder'          => $encoder,
            'createdAt'        => $student['created_at'] ?? date('Y-m-d H:i:s')
        ];

        // Milestones
        $periodId = $activePeriod['id'] ?? null;
        $milestoneRes = MilestoneService::getMilestones($pdo, $periodId ? ['academic_period_id' => $periodId] : []);
        $milestones = ($milestoneRes['success'] && !empty($milestoneRes['data'])) ? $milestoneRes['data'] : [];

        return [
            'success' => true,
            'data'    => [
                'profile'      => [
                    'id'                  => $student['id'],
                    'name'                => $student['name'],
                    'program'             => $student['program'],
                    'email'               => $student['email'],
                    'photo'               => $student['photo'],
                    'yearLevel'           => $student['year_level'],
                    'status'              => $student['status'],
                    'must_change_password'=> (bool)($student['must_change_password'] ?? false),
                    'personalInfo'        => $personalInfo,
                ],
                'roadmap'      => $roadmap,
                'requirements' => $requirements,
                'medical'      => $medical,
                'scholarship'  => $scholarship,
                'payment'      => !empty($payment) ? array_merge($payment, [
                    'amountPaid' => $amountPaid,
                    'balance'    => $balance,
                    'totalFee'   => $totalFee,
                    'status'     => $paymentStatus
                ]) : [
                    'amountPaid'     => $amountPaid,
                    'balance'        => $balance,
                    'totalFee'       => $totalFee,
                    'status'         => $paymentStatus,
                    'transactionRef' => $orNumber,
                    'history'        => []
                ],
                'helpdesk'     => $helpdesk,
                'enrollment'   => $enrollment,
                'subjects'     => $subjects,
                'corData'      => $corData,
                'activePeriod' => $activePeriod,
                'milestones'   => $milestones
            ]
        ];
    }

    public static function updateProfile(PDO $pdo, string $studentId, array $payload): array {
        $studentId = trim($studentId);
        if (!$studentId) {
            return ['success' => false, 'message' => 'Student ID is required.', 'code' => 400];
        }

        $stmt = $pdo->prepare("SELECT * FROM `students` WHERE `id` = :id LIMIT 1");
        $stmt->execute(['id' => $studentId]);
        $student = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$student) {
            return ['success' => false, 'message' => 'Student record not found.', 'code' => 404];
        }

        $personalInfo = json_decode($student['personal_info'] ?? '{}', true) ?: [];

        if (isset($payload['phone'])) {
            $personalInfo['phone'] = trim($payload['phone']);
        }
        if (isset($payload['personalEmail']) && !empty($payload['personalEmail'])) {
            $personalInfo['email'] = trim($payload['personalEmail']);
        } elseif (isset($payload['email']) && !empty($payload['email'])) {
            $personalInfo['email'] = trim($payload['email']);
        }
        if (isset($payload['address'])) {
            $personalInfo['address'] = trim($payload['address']);
        }
        if (isset($payload['emergencyContactName'])) {
            $personalInfo['emergencyContactName'] = trim($payload['emergencyContactName']);
        }
        if (isset($payload['emergencyContactPhone'])) {
            $personalInfo['emergencyContactPhone'] = trim($payload['emergencyContactPhone']);
        }

        $photoFile = $student['photo'];
        $photoData = $payload['photoData'] ?? ($payload['photo'] ?? ($payload['avatar'] ?? null));
        if (!empty($photoData) && is_string($photoData)) {
            $base64Data = $photoData;
            $ext = 'png';
            if (preg_match('/^data:image\/(\w+);base64,/', $base64Data, $type)) {
                $base64Data = substr($base64Data, strpos($base64Data, ',') + 1);
                $rawExt = strtolower($type[1]);
                if (in_array($rawExt, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
                    $ext = ($rawExt === 'jpeg') ? 'jpg' : $rawExt;
                }
            }
            $imageData = base64_decode($base64Data);
            if ($imageData !== false && strlen($imageData) > 0) {
                // Validate image magic bytes / getimagesize
                $imageInfo = @getimagesizefromstring($imageData);
                if ($imageInfo === false && function_exists('imagecreatefromstring')) {
                    $gdImg = @imagecreatefromstring($imageData);
                    if ($gdImg) {
                        $imageInfo = [imagesx($gdImg), imagesy($gdImg)];
                        @imagedestroy($gdImg);
                    }
                }
                if ($imageInfo !== false) {
                    $baseDir = dirname(dirname(dirname(__DIR__)));
                    $uploadDir1 = $baseDir . '/stations/it-center/assets/uploads/';
                    $uploadDir2 = $baseDir . '/shared/assets/uploads/';
                    $uploadDir3 = $baseDir . '/uploads/avatars/';
                    if (!is_dir($uploadDir1)) @mkdir($uploadDir1, 0777, true);
                    if (!is_dir($uploadDir2)) @mkdir($uploadDir2, 0777, true);
                    if (!is_dir($uploadDir3)) @mkdir($uploadDir3, 0777, true);

                    $safeId = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $studentId);
                    $filename = 'portrait_' . $safeId . '_' . time() . '.' . $ext;
                    @file_put_contents($uploadDir1 . $filename, $imageData);
                    @file_put_contents($uploadDir2 . $filename, $imageData);
                    @file_put_contents($uploadDir3 . $filename, $imageData);
                    $photoFile = $filename;
                } else {
                    return ['success' => false, 'message' => 'Invalid image format. Please select a valid JPG, PNG, or WebP photo.', 'code' => 400];
                }
            } else {
                return ['success' => false, 'message' => 'Failed to decode image data.', 'code' => 400];
            }
        }


        $upd = $pdo->prepare("UPDATE `students` SET `personal_info` = :pinfo, `photo` = :photo WHERE `id` = :id");
        $upd->execute([
            'pinfo' => json_encode($personalInfo),
            'photo' => $photoFile,
            'id'    => $studentId
        ]);

        return [
            'success' => true,
            'data'    => [
                'id'           => $student['id'],
                'name'         => $student['name'],
                'program'      => $student['program'],
                'email'        => $student['email'],
                'photo'        => $photoFile,
                'yearLevel'    => $student['year_level'],
                'status'       => $student['status'],
                'personalInfo' => $personalInfo
            ],
            'message' => 'Profile updated successfully.'
        ];
    }

    public static function changePassword(PDO $pdo, string $studentId, string $currentPassword, string $newPassword): array {
        $studentId = trim($studentId);
        $currentPassword = trim($currentPassword);
        $newPassword = trim($newPassword);

        if (!$studentId || !$currentPassword || !$newPassword) {
            return ['success' => false, 'message' => 'Student ID, current password, and new password are required.', 'code' => 400];
        }

        if (strlen($newPassword) < 6) {
            return ['success' => false, 'message' => 'New password must be at least 6 characters.', 'code' => 400];
        }

        $stmt = $pdo->prepare("SELECT * FROM `students` WHERE `id` = :id LIMIT 1");
        $stmt->execute(['id' => $studentId]);
        $student = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$student) {
            return ['success' => false, 'message' => 'Student record not found.', 'code' => 404];
        }

        $isValid = password_verify($currentPassword, $student['password']);
        if (!$isValid && !empty($student['password']) && substr($student['password'], 0, 4) !== '$2y$') {
            if ($currentPassword === $student['password']) {
                $isValid = true;
            }
        }

        if (!$isValid) {
            return ['success' => false, 'message' => 'Current password is incorrect.', 'code' => 401];
        }

        $hashed = password_hash($newPassword, PASSWORD_DEFAULT);
        $pdo->prepare("UPDATE `students` SET `password` = :pwd, `must_change_password` = 0 WHERE `id` = :id")
            ->execute(['pwd' => $hashed, 'id' => $studentId]);

        return [
            'success' => true,
            'data'    => ['studentId' => $studentId, 'must_change_password' => false],
            'message' => 'Password changed successfully.'
        ];
    }

    public static function requestPasswordReset(PDO $pdo, string $identifier): array {
        $identifier = trim($identifier);
        if (!$identifier) {
            return ['success' => false, 'message' => 'Student ID or Email address is required.', 'code' => 400];
        }

        // Rate limit: max 5 password reset attempts per IP per 10 minutes
        checkRateLimit('student_password_reset', 5, 600);

        $stmt = $pdo->prepare("
            SELECT * FROM `students` 
            WHERE `id` = :id 
               OR `email` = :email 
               OR `temp_reference_no` = :ref 
               OR JSON_UNQUOTE(JSON_EXTRACT(`personal_info`, '$.email')) = :pemail
            LIMIT 1
        ");
        $stmt->execute(['id' => $identifier, 'email' => $identifier, 'ref' => $identifier, 'pemail' => $identifier]);
        $student = $stmt->fetch(PDO::FETCH_ASSOC);

        $targetEmail = '';
        $studentName = '';
        $studentId   = '';

        if ($student) {
            $studentId   = $student['id'];
            $studentName = $student['name'];
            $pInfo       = json_decode($student['personal_info'] ?? '{}', true) ?: [];
            $personalEmail = !empty($pInfo['email']) ? trim($pInfo['email']) : '';
            $schoolEmail   = !empty($student['email']) ? trim($student['email']) : '';

            if (empty($personalEmail) || str_contains(strtolower($personalEmail), '@gncp.edu.ph')) {
                $peQuery = $pdo->prepare("SELECT `email` FROM `pre_enrollments` WHERE `existing_student_id` = :sid OR `temp_student_id` = :ref LIMIT 1");
                $peQuery->execute(['sid' => $student['id'], 'ref' => $student['temp_reference_no'] ?? $student['id']]);
                $peRow = $peQuery->fetch(PDO::FETCH_ASSOC);
                if ($peRow && !empty($peRow['email'])) {
                    $personalEmail = trim($peRow['email']);
                }
            }

            if (strcasecmp($identifier, $schoolEmail) === 0 || str_ends_with(strtolower($identifier), '@gncp.edu.ph')) {
                $targetEmail = !empty($schoolEmail) ? $schoolEmail : (!empty($personalEmail) ? $personalEmail : '');
            } else {
                $targetEmail = !empty($personalEmail) ? $personalEmail : (!empty($schoolEmail) ? $schoolEmail : '');
            }
        } else {
            $peStmt = $pdo->prepare("
                SELECT * FROM `pre_enrollments` 
                WHERE `temp_student_id` = :ref 
                   OR `email` = :email 
                   OR `existing_student_id` = :sid 
                LIMIT 1
            ");
            $peStmt->execute(['ref' => $identifier, 'email' => $identifier, 'sid' => $identifier]);
            $pre = $peStmt->fetch(PDO::FETCH_ASSOC);
            if ($pre) {
                $studentId   = $pre['temp_student_id'];
                $studentName = trim(($pre['first_name'] ?? '') . ' ' . ($pre['last_name'] ?? ''));
                $targetEmail = $pre['email'] ?? '';
            }
        }
        if (!$targetEmail) {
            // Neutralize account enumeration: return uniform generic response
            $dummyMasked = 's*****@gncp.edu.ph';
            return [
                'success' => true,
                'data'    => [
                    'maskedEmail' => $dummyMasked,
                    'studentId'   => $identifier
                ],
                'message' => "If an account matching that identifier exists in our records, a verification code has been dispatched."
            ];
        }

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `password_resets` (
                `id`         INT AUTO_INCREMENT PRIMARY KEY,
                `email`      VARCHAR(150) NOT NULL,
                `token`      VARCHAR(255) NOT NULL,
                `code`       VARCHAR(6) NOT NULL,
                `attempts`   INT DEFAULT 0,
                `user_type`  VARCHAR(20) DEFAULT 'STUDENT',
                `expires_at` DATETIME NOT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX `idx_email` (`email`),
                INDEX `idx_code` (`code`)
            ) ENGINE=InnoDB;
        ");
        try {
            $pdo->exec("ALTER TABLE `password_resets` ADD COLUMN IF NOT EXISTS `attempts` INT DEFAULT 0 AFTER `code`");
        } catch (Exception $e) {}

        $pdo->prepare("DELETE FROM `password_resets` WHERE `email` = :email")->execute(['email' => $targetEmail]);

        $resetCode  = sprintf('%06d', random_int(100000, 999999));
        $resetToken = bin2hex(random_bytes(16));

        $insStmt = $pdo->prepare("
            INSERT INTO `password_resets` (`email`, `token`, `code`, `attempts`, `user_type`, `expires_at`)
            VALUES (:email, :token, :code, 0, 'STUDENT', DATE_ADD(NOW(), INTERVAL 30 MINUTE))
        ");
        $insStmt->execute([
            'email' => $targetEmail,
            'token' => password_hash($resetToken, PASSWORD_DEFAULT),
            'code'  => $resetCode
        ]);

        $mailResult = EmailService::sendPasswordResetCode($targetEmail, $studentName ?: 'Student', $resetCode);
        $masked = self::maskEmailAddress($targetEmail);
        return [
            'success' => true,
            'data'    => [
                'maskedEmail' => $masked,
                'studentId'   => $studentId
            ],
            'message' => "If an account matching that identifier exists in our records, a verification code has been dispatched to $masked."
        ];
    }

    public static function resetPasswordWithCode(PDO $pdo, string $identifier, string $code, string $newPassword): array {
        $identifier = trim($identifier);
        $code = trim($code);
        $newPassword = trim($newPassword);

        if (!$identifier || !$code || !$newPassword) {
            return ['success' => false, 'message' => 'Student ID/Email, verification code, and new password are required.', 'code' => 400];
        }

        if (strlen($newPassword) < 6) {
            return ['success' => false, 'message' => 'New password must be at least 6 characters.', 'code' => 400];
        }

        $stmt = $pdo->prepare("
            SELECT * FROM `students` 
            WHERE `id` = :id 
               OR `email` = :email 
               OR `temp_reference_no` = :ref 
            LIMIT 1
        ");
        $stmt->execute(['id' => $identifier, 'email' => $identifier, 'ref' => $identifier]);
        $student = $stmt->fetch(PDO::FETCH_ASSOC);

        $emailsToCheck = [];
        if ($student) {
            if (!empty($student['email'])) $emailsToCheck[] = $student['email'];
            $pInfo = json_decode($student['personal_info'] ?? '{}', true) ?: [];
            if (!empty($pInfo['email'])) $emailsToCheck[] = $pInfo['email'];
        } else {
            $peStmt = $pdo->prepare("
                SELECT * FROM `pre_enrollments` 
                WHERE `temp_student_id` = :ref 
                   OR `email` = :email 
                   OR `existing_student_id` = :sid 
                LIMIT 1
            ");
            $peStmt->execute(['ref' => $identifier, 'email' => $identifier, 'sid' => $identifier]);
            $pre = $peStmt->fetch(PDO::FETCH_ASSOC);
            if ($pre && !empty($pre['email'])) {
                $emailsToCheck[] = $pre['email'];
            }
        }

        $emailsToCheck = array_unique(array_filter($emailsToCheck));

        if (empty($emailsToCheck)) {
            return ['success' => false, 'message' => 'Student account record not found.', 'code' => 404];
        }

        $placeholders = implode(',', array_fill(0, count($emailsToCheck), '?'));
        $chkStmt = $pdo->prepare("
            SELECT * FROM `password_resets` 
            WHERE `email` IN ($placeholders) AND `expires_at` > NOW()
            ORDER BY `id` DESC LIMIT 1
        ");
        $chkStmt->execute($emailsToCheck);
        $resetRow = $chkStmt->fetch(PDO::FETCH_ASSOC);

        if (!$resetRow) {
            return ['success' => false, 'message' => 'Invalid or expired verification code. Please request a new code.', 'code' => 400];
        }

        // Throttle failed brute force attempts (max 5)
        if (($resetRow['attempts'] ?? 0) >= 5) {
            $pdo->prepare("DELETE FROM `password_resets` WHERE `id` = :id")->execute(['id' => $resetRow['id']]);
            return ['success' => false, 'message' => 'Too many failed verification attempts. This code has been invalidated for security.', 'code' => 429];
        }

        if (!hash_equals($resetRow['code'], $code)) {
            $pdo->prepare("UPDATE `password_resets` SET `attempts` = `attempts` + 1 WHERE `id` = :id")->execute(['id' => $resetRow['id']]);
            $remaining = 5 - (($resetRow['attempts'] ?? 0) + 1);
            return ['success' => false, 'message' => "Invalid 6-digit verification code. ($remaining attempt(s) remaining)", 'code' => 400];
        }

        $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);

        if ($student) {
            $upd = $pdo->prepare("UPDATE `students` SET `password` = :pwd, `must_change_password` = 0 WHERE `id` = :id");
            $upd->execute(['pwd' => $hashedPassword, 'id' => $student['id']]);
        }

        $delStmt = $pdo->prepare("DELETE FROM `password_resets` WHERE `email` IN ($placeholders)");
        $delStmt->execute($emailsToCheck);

        return [
            'success' => true,
            'message' => 'Password reset successfully! You can now log into your GNCP Student Portal with your new password.'
        ];
    }
}
