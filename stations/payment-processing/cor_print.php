<?php
require_once __DIR__ . '/../../shared/backend/config/database.php';
require_once __DIR__ . '/../../shared/backend/utils/student.php';
require_once __DIR__ . '/../../shared/backend/services/AssessmentService.php';

require_once __DIR__ . '/../../shared/backend/utils/session_guard.php';

$ref = $_GET['ref'] ?? $_GET['id'] ?? $_GET['student_id'] ?? '';
$pin = trim($_GET['pin'] ?? '');

if (empty($ref)) {
    http_response_code(400);
    die("<h1 style='font-family:sans-serif; text-align:center; margin-top:50px;'>Error: Student reference number or Student ID is required.</h1>");
}

initSession();
$isAuthorized = false;

// 1. Staff session check
$adminUser = $_SESSION['gncp_admin_user'] ?? null;
$stationUser = $_SESSION['gncp_station_user'] ?? null;
if ($adminUser || $stationUser) {
    $u = $adminUser ?: $stationUser;
    $role = strtoupper(is_array($u) ? ($u['role'] ?? '') : (json_decode($u, true)['role'] ?? ''));
    if (in_array($role, ['CASHIER', 'REGISTRAR', 'ADMIN', 'SUPER_ADMIN', 'HELPDESK', 'IT_CENTER'])) {
        $isAuthorized = true;
    }
}

// 2. Student session check
$studentSess = $_SESSION['gncp_student'] ?? null;
if ($studentSess) {
    $sessId = is_array($studentSess) ? ($studentSess['id'] ?? '') : '';
    if (!empty($sessId) && strcasecmp($sessId, $ref) === 0) {
        $isAuthorized = true;
    }
}
session_write_close();

try {
    $pdo = Database::getInstance();

    // Retrieve pre-enrollment details
    $stmt = $pdo->prepare("
        SELECT p.*, pr.name as program_name, ap.name as academic_period_name, ap.academic_year, ap.semester as ap_semester
        FROM `pre_enrollments` p
        LEFT JOIN `programs` pr ON p.course_code = pr.code
        LEFT JOIN `academic_periods` ap ON ap.status = 'Active'
        WHERE p.temp_student_id = :ref
    ");
    $stmt->execute(['ref' => $ref]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$student) {
        // Fallback: search in the permanent students table (after promotion)
        $stmt = $pdo->prepare("
            SELECT s.*, pr.name as program_name, ap.name as academic_period_name, ap.academic_year, ap.semester as ap_semester
            FROM `students` s
            LEFT JOIN `programs` pr ON s.program = pr.code
            LEFT JOIN `academic_periods` ap ON ap.status = 'Active'
            WHERE s.id = :ref_id OR s.temp_reference_no = :ref_temp
        ");
        $stmt->execute(['ref_id' => $ref, 'ref_temp' => $ref]);
        $permStudent = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($permStudent) {
            $personal = json_decode($permStudent['personal_info'] ?? '{}', true);
            $enrollment = json_decode($permStudent['enrollment_data'] ?? '{}', true);
            $payment = json_decode($permStudent['payment_data'] ?? '{}', true);
            
            $nameParts = explode(' ', trim($permStudent['name'] ?? ''));
            $lastName = !empty($personal['lastName']) ? $personal['lastName'] : (count($nameParts) > 1 ? end($nameParts) : ($permStudent['name'] ?? ''));
            $firstName = !empty($personal['firstName']) ? $personal['firstName'] : (count($nameParts) > 1 ? implode(' ', array_slice($nameParts, 0, -1)) : ($permStudent['name'] ?? ''));
            $middleName = !empty($personal['middleName']) ? $personal['middleName'] : '';

            $student = [
                'temp_student_id' => $permStudent['temp_reference_no'] ?: $permStudent['id'],
                'first_name' => $firstName,
                'middle_name' => $middleName,
                'last_name' => $lastName,
                'course_code' => $permStudent['program'],
                'program_name' => $permStudent['program_name'],
                'academic_period_name' => $permStudent['academic_period_name'],
                'academic_year' => $permStudent['academic_year'],
                'ap_semester' => $permStudent['ap_semester'],
                'address' => $personal['address'] ?? '',
                'phone' => $personal['phone'] ?? '',
                'gender' => $personal['gender'] ?? '',
                'temp_pin' => $enrollment['temp_pin'] ?? '',
                'section_code' => $enrollment['assignedSection'] ?? $permStudent['section_code'] ?? '',
                'helpdesk_data' => $permStudent['helpdesk_data'],
                'scholarship_data' => $permStudent['scholarship_data'],
                'payment_data' => $permStudent['payment_data'],
                'student_type' => $permStudent['year_level']
            ];
        }
    }

    if (!$student) {
        die("<h1 style='font-family:sans-serif; text-align:center; margin-top:50px;'>Error: Student record not found.</h1>");
    }

    // Access control check: allow logged in cashier/admin/staff, active student session, or valid PIN
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $isAuthorized = false;
    $storedUser = $_SESSION['gncp_station_user'] ?? $_SESSION['gncp_admin_user'] ?? null;
    if ($storedUser) {
        $user = is_array($storedUser) ? $storedUser : (is_string($storedUser) ? json_decode($storedUser, true) : []);
        $role = strtoupper($user['role'] ?? '');
        if (in_array($role, ['CASHIER', 'REGISTRAR', 'ADMIN', 'SUPER_ADMIN', 'HELPDESK', 'MEDICAL', 'IT_CENTER'])) {
            $isAuthorized = true;
        }
    }

    if (!$isAuthorized && !empty($_SESSION['gncp_student'])) {
        $studentUser = is_array($_SESSION['gncp_student']) ? $_SESSION['gncp_student'] : json_decode($_SESSION['gncp_student'], true);
        $sId = strtolower($studentUser['id'] ?? ($studentUser['username'] ?? ''));
        $targetId = strtolower($student['temp_student_id'] ?? ($student['id'] ?? ''));
        if ($sId && ($sId === $targetId || strcasecmp($sId, $ref) === 0)) {
            $isAuthorized = true;
        }
    }

    if (!$isAuthorized) {
        $storedPin = (string)($student['temp_pin'] ?? '');
        if (!empty($pin) && !empty($storedPin) && hash_equals($storedPin, $pin)) {
            $isAuthorized = true;
        }
    }

    if (!$isAuthorized) {
        http_response_code(401);
        die("<h1 style='font-family:sans-serif; text-align:center; margin-top:50px; color:#dc2626;'>401 Unauthorized: Invalid security PIN or staff authorization required. Access denied.</h1>");
    }

    // Year level translation
    $studentType = strtoupper($student['student_type'] ?? 'FRESHMAN');
    $yearLevel = '1st Year';
    
    // First try to find active period
    $activePeriodId = null;
    $activeSem = $student['ap_semester'] ?: '1st Semester';
    $periodStmt = $pdo->query("SELECT id, semester FROM `academic_periods` WHERE status = 'Active' LIMIT 1");
    if ($periodStmt) {
        $pRow = $periodStmt->fetch(PDO::FETCH_ASSOC);
        if ($pRow) {
            $activePeriodId = (int)$pRow['id'];
            $activeSem = $pRow['semester'];
        }
    }

    $programName = $student['program_name'] ?: $student['course_code'];

    // Try to get year level from assigned section
    $sectionId = null;
    if (!empty($student['section_code'])) {
        $secQuery = $pdo->prepare("
            SELECT id, year_level 
            FROM `sections` 
            WHERE code = :code 
              AND program = :prog 
              AND academic_period_id = :active_period_id
            LIMIT 1
        ");
        $secQuery->execute([
            'code' => $student['section_code'],
            'prog' => $programName,
            'active_period_id' => $activePeriodId
        ]);
        $mappedSec = $secQuery->fetch(PDO::FETCH_ASSOC);
        if ($mappedSec) {
            $yearLevel = $mappedSec['year_level'];
            $sectionId = (int)$mappedSec['id'];
        }
    }
    
    if (!$sectionId) {
        // Fallback to student type translation
        if ($studentType === 'SOPHOMORE') $yearLevel = '2nd Year';
        elseif ($studentType === 'JUNIOR') $yearLevel = '3rd Year';
        elseif ($studentType === 'SENIOR') $yearLevel = '4th Year';
    }

    // Get advised subjects
    $helpdesk = json_decode($student['helpdesk_data'] ?? '{}', true);
    $advisedSubjects = $helpdesk['advisedSubjects'] ?? [];

    if (empty($advisedSubjects)) {
        // Fallback to curriculum mapping
        $advisedSubjects = getCurriculumSubjects($pdo, $student['course_code'], $yearLevel, $activeSem);
    }

    // Determine cohort letter from section_code
    $studentSection = $student['section_code'] ?? '';
    $cohortLetter = 'A'; // Default
    if (preg_match('/-([A-Z])$/', $studentSection, $matches)) {
        $cohortLetter = $matches[1];
    } elseif (in_array(strtoupper($studentSection), ['A', 'B', 'C', 'D'])) {
        $cohortLetter = strtoupper($studentSection);
    }

    $schedule = [];
    $totalUnits = 0.00;
    $totalLabFee = 0.00;

    foreach ($advisedSubjects as $sub) {
        $subTitle = $sub['title'] ?? $sub['name'] ?? '';
        $subCode = $sub['code'] ?? '';
        $sectionRow = null;

        // Strategy A: Query using section_id if we matched a cohort record
        if ($sectionId !== null) {
            $secStmt = $pdo->prepare("
                SELECT * FROM `subject_sections` 
                WHERE section_id = :section_id
                  AND (`subject` = :title OR `code` LIKE :code_pattern)
                LIMIT 1
            ");
            $secStmt->execute([
                'section_id' => $sectionId,
                'title' => $subTitle,
                'code_pattern' => '%' . $subCode . '%'
            ]);
            $sectionRow = $secStmt->fetch(PDO::FETCH_ASSOC);
        }

        // Strategy B: Query matching program, year, semester, subject, and cohort suffix
        if (!$sectionRow) {
            $secStmt = $pdo->prepare("
                SELECT * FROM `subject_sections` 
                WHERE program = :program
                  AND year_level = :year_level
                  AND semester = :semester
                  AND (`subject` = :title OR `code` LIKE :code_pattern)
                  AND code LIKE :cohort_pattern
                LIMIT 1
            ");
            $secStmt->execute([
                'program' => $programName,
                'year_level' => $yearLevel,
                'semester' => $activeSem,
                'title' => $subTitle,
                'code_pattern' => '%' . $subCode . '%',
                'cohort_pattern' => '%' . $cohortLetter
            ]);
            $sectionRow = $secStmt->fetch(PDO::FETCH_ASSOC);
        }

        // Strategy C: Loose fallback to cohort suffix
        if (!$sectionRow) {
            $secStmt = $pdo->prepare("
                SELECT * FROM `subject_sections` 
                WHERE (`subject` = :title OR `code` LIKE :code_pattern)
                  AND `code` LIKE :cohort_pattern
                LIMIT 1
            ");
            $secStmt->execute([
                'title' => $subTitle,
                'code_pattern' => '%' . $subCode . '%',
                'cohort_pattern' => '%' . $cohortLetter
            ]);
            $sectionRow = $secStmt->fetch(PDO::FETCH_ASSOC);
        }

        // Strategy D: Global fallback (any class offering of this subject)
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
            $sectionRow = $secStmt->fetch(PDO::FETCH_ASSOC);
        }

        $lec = isset($sub['lecture_units']) ? (float)$sub['lecture_units'] : (isset($sub['lectureUnits']) ? (float)$sub['lectureUnits'] : 3.00);
        $lab = isset($sub['lab_units']) ? (float)$sub['lab_units'] : (isset($sub['labUnits']) ? (float)$sub['labUnits'] : 0.00);
        $units = $lec + $lab;

        $totalUnits += $units;
        $totalLabFee += isset($sub['lab_fee']) ? (float)$sub['lab_fee'] : (isset($sub['labFee']) ? (float)$sub['labFee'] : 0.00);

        if ($sectionRow) {
            $timeString = $sectionRow['time'] ?? 'TBA';
            $startTime = '';
            $endTime = '';
            if (strpos($timeString, ' - ') !== false) {
                $parts = explode(' - ', $timeString);
                $startTime = $parts[0];
                $endTime = $parts[1];
            } else {
                $startTime = $timeString;
            }

            $schedule[] = [
                'code' => $subCode,
                'description' => $subTitle,
                'units' => number_format($units, 2),
                'type' => $lab > 0 ? 'Lec/Lab' : 'Lec',
                'days' => $sectionRow['days'],
                'start' => $startTime,
                'end' => $endTime,
                'section' => $sectionRow['code'],
                'room' => $sectionRow['room'],
                'instructor' => $sectionRow['instructor'],
                's' => ''
            ];
        } else {
            $schedule[] = [
                'code' => $subCode,
                'description' => $subTitle,
                'units' => number_format($units, 2),
                'type' => $lab > 0 ? 'Lec/Lab' : 'Lec',
                'days' => 'TBA',
                'start' => 'TBA',
                'end' => 'TBA',
                'section' => 'TBA',
                'room' => 'TBA',
                'instructor' => 'TBA',
                's' => ''
            ];
        }
    }

    // Calculate fee assessment via authoritative AssessmentService
    $nstpType = strtoupper($student['nstp'] ?? 'NONE');
    $scholarshipData = json_decode($student['scholarship_data'] ?? '{}', true);
    $discount = (float)($scholarshipData['discount'] ?? 0.00);
    $paymentData = json_decode($student['payment_data'] ?? '{}', true);
    $snapshot = $paymentData['assessmentSnapshot'] ?? null;

    $assessment = AssessmentService::calculateAssessment($pdo, $advisedSubjects, $nstpType, $discount, $snapshot);

    $tuitionRate       = $assessment['tuitionRate'];
    $tuitionFee        = $assessment['tuitionFee'];
    $totalLabFee       = $assessment['totalLabFee'];
    $miscFee           = $assessment['miscFee'];
    $lmsFee            = $assessment['lmsFee'];
    $nstpFee           = $assessment['nstpFee'];
    $omrFee            = $assessment['omrFee'];
    $cashTotal         = $assessment['cashTotal'];
    $installmentCharge = $assessment['installmentCharge'];
    $installmentTotal  = $assessment['installmentTotal'];
    $paymentMode       = $student['payment_mode'] ?? 'Full';
    $paymentSchedule   = AssessmentService::calculatePaymentSchedule($cashTotal, $installmentTotal, $paymentMode, $paymentData);

} catch (Exception $e) {
    die("<h1 style='font-family:sans-serif; text-align:center; margin-top:50px;'>Database error: " . $e->getMessage() . "</h1>");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Certificate of Registration - <?php echo htmlspecialchars($student['temp_student_id']); ?></title>
    <style>
        body {
            font-family: Arial, sans-serif;
            color: #000;
            background-color: #f8fafc;
            margin: 0;
            padding: 20px;
            font-size: 11px;
            line-height: 1.3;
        }
        .container {
            width: 100%;
            max-width: 800px;
            margin: 0 auto;
            background-color: #fff;
            padding: 28px 32px;
            border: 1px solid #d1d5db;
            border-radius: 0 !important;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }
        .header-table, .schedule-table, .assessment-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 10px;
        }
        .header-table td {
            border: 1px solid #000;
            padding: 5px;
            vertical-align: top;
        }
        .header-title {
            text-align: center;
            font-weight: bold;
            font-size: 14px;
            border: none !important;
            padding-bottom: 15px !important;
        }
        .label {
            font-size: 9px;
            text-transform: uppercase;
            color: #333;
            display: block;
            margin-bottom: 2px;
        }
        .value {
            font-weight: bold;
            font-size: 11px;
        }
        .schedule-table th, .schedule-table td {
            border: 1px solid #000;
            padding: 4px 6px;
            text-align: left;
        }
        .schedule-table th {
            background-color: #f2f2f2;
            font-weight: bold;
            text-transform: uppercase;
            font-size: 10px;
        }
        .text-center {
            text-align: center !important;
        }
        .text-right {
            text-align: right !important;
        }
        .font-mono {
            font-family: Courier, monospace;
        }
        .flex-container {
            display: flex;
            justify-content: space-between;
            margin-top: 10px;
            margin-bottom: 15px;
        }
        .left-col {
            width: 48%;
        }
        .right-col {
            width: 48%;
        }
        .section-title {
            font-weight: bold;
            text-decoration: underline;
            margin-bottom: 8px;
            font-size: 12px;
        }
        .fee-row {
            display: flex;
            justify-content: space-between;
            padding: 3px 0;
        }
        .fee-total {
            font-weight: bold;
            border-top: 1px solid #000;
            margin-top: 5px;
            padding-top: 5px;
        }
        .double-underline {
            border-bottom: 3px double #000;
            padding-bottom: 1px;
        }
        .schedule-payments-table {
            width: 100%;
            border-collapse: collapse;
        }
        .schedule-payments-table th, .schedule-payments-table td {
            border: 1px solid #000;
            padding: 4px;
            text-align: center;
        }
        .schedule-payments-table th {
            background-color: #f2f2f2;
            font-size: 9px;
        }
        .stamps-signatures {
            display: flex;
            justify-content: space-between;
            margin-top: 30px;
            margin-bottom: 20px;
            border: 1px dashed #666;
            padding: 15px;
            background-color: #fafafa;
        }
        .stamp-box {
            width: 30%;
            border: 2px dashed #000;
            height: 70px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            font-weight: bold;
            color: #666;
            text-transform: uppercase;
        }
        .stamp-active {
            border-color: #008000;
            color: #008000;
        }
        .signature-line {
            width: 30%;
            text-align: center;
            display: flex;
            flex-direction: column;
            justify-content: flex-end;
        }
        .sig-border {
            border-top: 1px solid #000;
            margin-top: 45px;
            font-weight: bold;
        }
        .footer-note {
            font-size: 9px;
            font-style: italic;
            border-top: 1px solid #000;
            padding-top: 5px;
            margin-top: 15px;
            text-align: justify;
        }
        .footer-metadata {
            display: flex;
            justify-content: space-between;
            margin-top: 8px;
            font-size: 9px;
            font-family: monospace;
        }
        .print-btn-container {
            text-align: center;
            margin-bottom: 15px;
        }
        .btn-print {
            background-color: #007bff;
            color: #fff;
            border: none;
            padding: 8px 20px;
            font-size: 13px;
            font-weight: bold;
            cursor: pointer;
            border-radius: 4px;
        }
        .btn-print:hover {
            background-color: #0056b3;
        }
        @media print {
            .print-btn-container {
                display: none;
            }
            body {
                padding: 0;
            }
        }
    </style>
</head>
<body>

<div class="print-btn-container">
    <button class="btn-print" onclick="window.print()">Print Document</button>
</div>

<div class="container">
    <table class="header-table">
        <tr>
            <td colspan="5" class="header-title">
                GNCP ACADEMIC PORTAL<br>
                <span style="font-size: 12px; font-weight: normal;">CERTIFICATE OF REGISTRATION</span>
            </td>
        </tr>
        <tr>
            <td style="width: 20%;">
                <span class="label">Student No.</span>
                <span class="value font-mono"><?php echo htmlspecialchars($student['temp_student_id']); ?></span>
            </td>
            <td style="width: 25%;">
                <span class="label">Family Name</span>
                <span class="value"><?php echo htmlspecialchars($student['last_name']); ?></span>
            </td>
            <td style="width: 25%;">
                <span class="label">Given Name</span>
                <span class="value"><?php echo htmlspecialchars($student['first_name']); ?></span>
            </td>
            <td style="width: 15%;">
                <span class="label">Middle Name</span>
                <span class="value"><?php echo htmlspecialchars($student['middle_name'] ?: '---'); ?></span>
            </td>
            <td style="width: 15%;">
                <span class="label">Course Code</span>
                <span class="value"><?php echo htmlspecialchars($student['course_code']); ?></span>
            </td>
        </tr>
        <tr>
            <td colspan="3">
                <span class="label">Address</span>
                <span class="value"><?php echo htmlspecialchars($student['address']); ?></span>
            </td>
            <td>
                <span class="label">Contact No.</span>
                <span class="value"><?php echo htmlspecialchars($student['phone']); ?></span>
            </td>
            <td>
                <span class="label">Year Level</span>
                <span class="value"><?php echo $yearLevel; ?></span>
            </td>
        </tr>
        <tr>
            <td colspan="2">
                <span class="label">Gender</span>
                <span class="value"><?php echo htmlspecialchars($student['gender']); ?></span>
            </td>
            <td colspan="2">
                <span class="label">Semester</span>
                <span class="value"><?php echo htmlspecialchars($student['ap_semester'] ?: '1st Semester'); ?></span>
            </td>
            <td>
                <span class="label">S.Y.</span>
                <span class="value"><?php echo htmlspecialchars($student['academic_year'] ?: '2026-2027'); ?></span>
            </td>
        </tr>
    </table>

    <table class="schedule-table">
        <thead>
            <tr>
                <th style="width: 10%;">Code</th>
                <th style="width: 32%;">Description</th>
                <th style="width: 6%;" class="text-center">Units</th>
                <th style="width: 8%;">Type</th>
                <th style="width: 6%;">Days</th>
                <th style="width: 8%;">Start</th>
                <th style="width: 8%;">End</th>
                <th style="width: 12%;">Section</th>
                <th style="width: 8%;">Room</th>
                <th style="width: 12%;">Instructor</th>
                <th style="width: 4%;" class="text-center">S</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($schedule as $row): ?>
                <tr>
                    <td class="font-mono"><?php echo htmlspecialchars($row['code']); ?></td>
                    <td><?php echo htmlspecialchars($row['description']); ?></td>
                    <td class="text-center font-mono"><?php echo $row['units']; ?></td>
                    <td><?php echo htmlspecialchars($row['type']); ?></td>
                    <td><?php echo htmlspecialchars($row['days']); ?></td>
                    <td><?php echo htmlspecialchars($row['start']); ?></td>
                    <td><?php echo htmlspecialchars($row['end']); ?></td>
                    <td class="font-mono"><?php echo htmlspecialchars($row['section']); ?></td>
                    <td><?php echo htmlspecialchars($row['room']); ?></td>
                    <td><?php echo htmlspecialchars($row['instructor']); ?></td>
                    <td class="text-center font-mono"><?php echo $row['s']; ?></td>
                </tr>
            <?php endforeach; ?>
            <tr style="font-weight: bold; background-color: #fafafa;">
                <td colspan="2" class="text-right">TOTAL UNITS:</td>
                <td class="text-center font-mono"><?php echo number_format($totalUnits, 2); ?></td>
                <td colspan="8">Status Codes [S]: A=Added, D=Dropped, Blank=Regular Enrollment</td>
            </tr>
        </tbody>
    </table>

    <div class="flex-container">
        <div class="left-col">
            <div class="section-title">Assessment of Fees (Cash)</div>
            <div class="fee-row">
                <span>Tuition Fee:</span>
                <span class="font-mono"><?php echo number_format($tuitionFee, 2); ?></span>
            </div>
            <div class="fee-row">
                <span>Laboratory Fee:</span>
                <span class="font-mono"><?php echo number_format($totalLabFee, 2); ?></span>
            </div>
            <div class="fee-row">
                <span>Miscellaneous:</span>
                <span class="font-mono"><?php echo number_format($miscFee, 2); ?></span>
            </div>
            <div class="fee-row">
                <span>LMS Fee:</span>
                <span class="font-mono"><?php echo number_format($lmsFee, 2); ?></span>
            </div>
            <div class="fee-row">
                <span>NSTP/ROTC:</span>
                <span class="font-mono"><?php echo number_format($nstpFee, 2); ?></span>
            </div>
            <div class="fee-row">
                <span>OMR:</span>
                <span class="font-mono"><?php echo number_format($omrFee, 2); ?></span>
            </div>
            <?php if ($discount > 0): ?>
            <div class="fee-row" style="color: #c00;">
                <span>Scholarship Discount:</span>
                <span class="font-mono">-<?php echo number_format($discount, 2); ?></span>
            </div>
            <?php endif; ?>
            <div class="fee-row fee-total">
                <span>Cash Total:</span>
                <span class="font-mono double-underline">₱ <?php echo number_format($cashTotal, 2); ?></span>
            </div>
            <br>
            <div class="section-title" style="margin-top: 5px;">Installment</div>
            <div class="fee-row">
                <span>Installment Charge (8%):</span>
                <span class="font-mono"><?php echo number_format($installmentCharge, 2); ?></span>
            </div>
            <div class="fee-row fee-total">
                <span>Installment Total:</span>
                <span class="font-mono">₱ <?php echo number_format($installmentTotal, 2); ?></span>
            </div>
            <div style="font-size: 8.5px; margin-top: 5px; font-style: italic;">
                Note: Installment charge does not apply to full-payment transaction(s).
            </div>
        </div>

        <div class="right-col">
            <div class="section-title">Schedule of Payment(s)</div>
            <table class="schedule-payments-table">
                <thead>
                    <tr>
                        <th>Milestone</th>
                        <th>Due Date</th>
                        <th>Amount Due</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($paymentSchedule['items'])): ?>
                        <?php foreach ($paymentSchedule['items'] as $item): ?>
                            <tr>
                                <td style="<?php echo $item['milestone'] === 'Upon Registration' ? 'font-weight: bold;' : ''; ?>"><?php echo htmlspecialchars($item['milestone']); ?></td>
                                <td style="<?php echo $item['status'] === 'CLEARED' ? 'color: #059669; font-style: italic;' : ''; ?>"><?php echo htmlspecialchars($item['dueDate']); ?></td>
                                <td class="font-mono" style="font-weight: bold; <?php echo ($item['status'] === 'PAID' || $item['status'] === 'CLEARED') ? 'color: #059669;' : ''; ?>">
                                    <?php echo htmlspecialchars($item['formattedAmount']); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td style="font-weight: bold;">Upon Registration</td>
                            <td>Upon Enrollment</td>
                            <td class="font-mono" style="font-style: italic; font-weight: bold;">
                                <?php echo $student['payment_mode'] === 'Installment' ? '₱ ' . number_format($installmentTotal, 2) : '₱ ' . number_format($cashTotal, 2); ?>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
            <div style="font-size: 8.5px; margin-top: 8px; font-style: italic; text-align: justify;">
                Note: Outright payment of adding/dropping charge is required when adding/dropping class schedule(s).
            </div>
        </div>
    </div>

    <div class="stamps-signatures">
        <div class="stamp-box <?php echo !empty($student['or_number']) ? 'stamp-active' : ''; ?>">
            <?php 
            if (!empty($student['or_number'])) {
                echo "PAID ENROLLED<br><span style='font-size: 9px; font-weight: normal; font-family: monospace;'>" . htmlspecialchars($student['or_number']) . "</span>";
            } else {
                echo "CASHIER STAMP";
            }
            ?>
        </div>
        <div class="signature-line">
            <div class="sig-border">Cashier Representative</div>
        </div>
        <div class="signature-line">
            <div class="sig-border">Registrar Officer</div>
        </div>
    </div>

    <div class="footer-note">
        Note to the students: Enrollment is valid only upon acceptance of payment by the Treasury Department within the next working day from the day of encoding. GNCP reserves the right, at its sole discretion, to displace/delete transactions that are deemed inactive and/or unpaid after the allotted enrollment period without incurring any liability or whatsoever.
    </div>

    <div class="footer-metadata">
        <span>Print Date: <?php echo date('d/m/Y h:i:sa'); ?></span>
        <span>Enrollment Date: <?php echo date('d/m/Y h:i:sa', strtotime($student['created_at'])); ?></span>
        <span>Encoder: <?php echo htmlspecialchars($student['cashier_name'] ?: 'sbaltazar3'); ?></span>
    </div>
</div>

<script>
    // Auto print if requested via query param or if in print mode
    window.onload = function() {
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('autoprint') === 'true') {
            window.print();
        }
    }
</script>
</body>
</html>
