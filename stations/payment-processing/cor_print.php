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

    // Access control check: allow logged in cashier/admin/staff with valid session, active student session, or valid PIN
    $isAuthorized = false;
    $authValidation = validateSession(['CASHIER', 'REGISTRAR', 'ADMIN', 'SUPER_ADMIN', 'HELPDESK', 'MEDICAL', 'IT_CENTER', 'STUDENT']);
    if (!empty($authValidation['authenticated'])) {
        $role = strtoupper($authValidation['role'] ?? '');
        if ($role !== 'STUDENT') {
            $isAuthorized = true;
        } else {
            $sId = strtolower($authValidation['identity'] ?? '');
            $targetId = strtolower($student['temp_student_id'] ?? ($student['id'] ?? ''));
            if ($sId && ($sId === $targetId || strcasecmp($sId, $ref) === 0)) {
                $isAuthorized = true;
            }
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

    $paymentBalance    = AssessmentService::calculateBalance($assessment, $paymentData);
    $amountPaid        = (float)($paymentBalance['amountPaid'] ?? 0.00);
    $balance           = (float)($paymentBalance['balance'] ?? 0.00);
    $totalFee          = (float)($paymentBalance['totalFee'] ?? $cashTotal);
    $orNumber          = $paymentData['orNumber'] ?? ($paymentData['transactionRef'] ?? ($student['or_number'] ?? 'N/A'));
    $paymentStatus     = $paymentData['status'] ?? ($balance <= 0 ? 'PAID' : 'PARTIALLY_PAID');

} catch (Exception $e) {
    die("<h1 style='font-family:sans-serif; text-align:center; margin-top:50px;'>Database error: " . $e->getMessage() . "</h1>");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Certificate of Registration &ndash; <?php echo htmlspecialchars($student['temp_student_id']); ?></title>
    <style>
        @page { size: Letter portrait; margin: 0.75in; }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Times New Roman', Times, serif;
            font-size: 10pt;
            color: #000;
            background: #e8e8e8;
            padding: 24px;
            line-height: 1.35;
        }

        /* White Google-Docs-style page shell */
        .page {
            background: #fff;
            width: 816px;
            min-height: 1056px;
            margin: 0 auto;
            padding: 72px 72px 60px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.18);
        }

        /* Print button */
        .no-print { text-align: center; margin-bottom: 18px; }
        .no-print button {
            font-family: Arial, sans-serif;
            font-size: 12px;
            padding: 7px 22px;
            background: #1a73e8;
            color: #fff;
            border: none;
            border-radius: 4px;
            cursor: pointer;
        }
        .no-print button:hover { background: #1558b0; }

        /* Letterhead */
        .letterhead {
            text-align: center;
            margin-bottom: 10px;
            border-bottom: 2.5pt solid #000;
            padding-bottom: 8px;
        }
        .letterhead .school-name {
            font-size: 14pt;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .letterhead .school-addr { font-size: 8.5pt; margin-top: 2px; }

        .doc-title {
            text-align: center;
            font-size: 11pt;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 2px;
            margin: 7px 0 10px;
            border-bottom: 1pt solid #000;
            padding-bottom: 6px;
        }

        /* Info grid */
        .info-table { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
        .info-table td { border: 0.75pt solid #000; padding: 3px 5px; vertical-align: top; }
        .field-label {
            display: block;
            font-size: 7.5pt;
            text-transform: uppercase;
            font-family: Arial, Helvetica, sans-serif;
            color: #333;
            margin-bottom: 1px;
        }
        .field-value { display: block; font-size: 10pt; font-weight: bold; }
        .mono { font-family: 'Courier New', Courier, monospace; }

        /* Section headings */
        .section-heading {
            font-size: 9pt;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 0.75pt solid #000;
            padding-bottom: 2px;
            margin: 8px 0 4px;
        }

        /* Schedule table */
        .sched-table { width: 100%; border-collapse: collapse; font-size: 8.5pt; margin-bottom: 10px; }
        .sched-table th {
            border: 0.75pt solid #000;
            background: #f2f2f2;
            padding: 3px 4px;
            text-align: center;
            font-family: Arial, Helvetica, sans-serif;
            font-size: 7.5pt;
            text-transform: uppercase;
            font-weight: bold;
        }
        .sched-table td { border: 0.75pt solid #000; padding: 2.5px 4px; }
        .sched-table .total-row td { font-weight: bold; background: #f9f9f9; }
        .text-center { text-align: center; }
        .text-right  { text-align: right; }

        /* Two-column layout */
        .two-col { display: table; width: 100%; margin-bottom: 10px; }
        .col-left, .col-right { display: table-cell; vertical-align: top; width: 50%; }
        .col-left  { padding-right: 14px; }
        .col-right { padding-left: 14px; border-left: 0.75pt solid #ccc; }

        /* Fee rows */
        .fee-row { display: flex; justify-content: space-between; padding: 1.5px 0; font-size: 9.5pt; }
        .fee-row.total-line {
            font-weight: bold;
            border-top: 0.75pt solid #000;
            margin-top: 4px;
            padding-top: 4px;
        }
        .fee-row.discount { color: #a00; }
        .double-line { text-decoration: underline; text-decoration-style: double; }

        /* Payment table */
        .payment-table { width: 100%; border-collapse: collapse; font-size: 8.5pt; }
        .payment-table th {
            border: 0.75pt solid #000;
            background: #f2f2f2;
            padding: 3px 5px;
            text-align: center;
            font-family: Arial, Helvetica, sans-serif;
            font-size: 7.5pt;
            text-transform: uppercase;
        }
        .payment-table td { border: 0.75pt solid #000; padding: 3px 5px; }
        .paid-cell { color: #166534; font-style: italic; }

        /* Signature row */
        .sig-row { display: table; width: 100%; margin-top: 22px; margin-bottom: 14px; }
        .sig-cell { display: table-cell; width: 33.33%; text-align: center; vertical-align: bottom; padding: 0 10px; }
        .sig-line { border-top: 0.75pt solid #000; margin-top: 36px; padding-top: 3px; font-size: 9pt; font-weight: bold; }
        .sig-sub { font-size: 8pt; font-family: Arial, Helvetica, sans-serif; color: #333; }
        .stamp-box {
            border: 1.5pt dashed #999;
            height: 60px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-direction: column;
            font-size: 10pt;
            font-weight: bold;
            color: #777;
            text-transform: uppercase;
        }
        .stamp-box.paid { border-color: #166534; color: #166534; }

        /* Footer */
        .footer-note {
            font-size: 7.5pt;
            font-style: italic;
            border-top: 0.75pt solid #000;
            padding-top: 5px;
            margin-top: 10px;
            text-align: justify;
            line-height: 1.4;
        }
        .footer-meta {
            display: flex;
            justify-content: space-between;
            margin-top: 6px;
            font-size: 7.5pt;
            font-family: 'Courier New', Courier, monospace;
            color: #444;
        }

        /* Print */
        @media print {
            body { background: none; padding: 0; }
            .page { box-shadow: none; width: 100%; padding: 0; min-height: auto; }
            .no-print { display: none !important; }
        }
    </style>
</head>
<body>

<div class="no-print">
    <button onclick="window.print()">&#128438; Print / Save as PDF</button>
</div>

<div class="page">

    <div class="letterhead">
        <div class="school-name">Go-on National College of the Philippines</div>
        <div class="school-addr">Registrar&rsquo;s Office &middot; Official Academic Record</div>
    </div>
    <div class="doc-title">Certificate of Registration</div>

    <table class="info-table">
        <tr>
            <td style="width:18%">
                <span class="field-label">Student No.</span>
                <span class="field-value mono"><?php echo htmlspecialchars($student['temp_student_id']); ?></span>
            </td>
            <td style="width:22%">
                <span class="field-label">Family Name</span>
                <span class="field-value"><?php echo htmlspecialchars($student['last_name']); ?></span>
            </td>
            <td style="width:22%">
                <span class="field-label">Given Name</span>
                <span class="field-value"><?php echo htmlspecialchars($student['first_name']); ?></span>
            </td>
            <td style="width:18%">
                <span class="field-label">Middle Name</span>
                <span class="field-value"><?php echo htmlspecialchars($student['middle_name'] ?: '&mdash;'); ?></span>
            </td>
            <td style="width:20%">
                <span class="field-label">Course</span>
                <span class="field-value"><?php echo htmlspecialchars($student['course_code']); ?></span>
            </td>
        </tr>
        <tr>
            <td colspan="3">
                <span class="field-label">Address</span>
                <span class="field-value"><?php echo htmlspecialchars($student['address']); ?></span>
            </td>
            <td>
                <span class="field-label">Contact No.</span>
                <span class="field-value mono"><?php echo htmlspecialchars($student['phone']); ?></span>
            </td>
            <td>
                <span class="field-label">Year Level</span>
                <span class="field-value"><?php echo $yearLevel; ?></span>
            </td>
        </tr>
        <tr>
            <td colspan="2">
                <span class="field-label">Gender</span>
                <span class="field-value"><?php echo htmlspecialchars($student['gender']); ?></span>
            </td>
            <td colspan="2">
                <span class="field-label">Semester</span>
                <span class="field-value"><?php echo htmlspecialchars($student['ap_semester'] ?: '1st Semester'); ?></span>
            </td>
            <td>
                <span class="field-label">Academic Year</span>
                <span class="field-value"><?php echo htmlspecialchars($student['academic_year'] ?: '2026-2027'); ?></span>
            </td>
        </tr>
    </table>

    <div class="section-heading">Class Schedule</div>
    <table class="sched-table">
        <thead>
            <tr>
                <th style="width:9%">Code</th>
                <th style="width:30%">Description</th>
                <th style="width:6%" class="text-center">Units</th>
                <th style="width:7%">Type</th>
                <th style="width:6%">Days</th>
                <th style="width:8%">Start</th>
                <th style="width:8%">End</th>
                <th style="width:13%">Section</th>
                <th style="width:7%">Room</th>
                <th style="width:10%">Instructor</th>
                <th style="width:4%" class="text-center">S</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($schedule as $row): ?>
            <tr>
                <td class="mono"><?php echo htmlspecialchars($row['code']); ?></td>
                <td><?php echo htmlspecialchars($row['description']); ?></td>
                <td class="text-center mono"><?php echo $row['units']; ?></td>
                <td><?php echo htmlspecialchars($row['type']); ?></td>
                <td class="text-center"><?php echo htmlspecialchars($row['days']); ?></td>
                <td><?php echo htmlspecialchars($row['start']); ?></td>
                <td><?php echo htmlspecialchars($row['end']); ?></td>
                <td class="mono"><?php echo htmlspecialchars($row['section']); ?></td>
                <td><?php echo htmlspecialchars($row['room']); ?></td>
                <td><?php echo htmlspecialchars($row['instructor']); ?></td>
                <td class="text-center mono"><?php echo $row['s']; ?></td>
            </tr>
            <?php endforeach; ?>
            <tr class="total-row">
                <td colspan="2" class="text-right">Total Units:</td>
                <td class="text-center mono"><?php echo number_format($totalUnits, 2); ?></td>
                <td colspan="8" style="font-weight:normal; font-size:7.5pt; color:#444;">
                    Status Codes [S]:&nbsp; A = Added &nbsp;&middot;&nbsp; D = Dropped &nbsp;&middot;&nbsp; Blank = Regular Enrollment
                </td>
            </tr>
        </tbody>
    </table>

    <!-- Accounts Ledger Assessment & Payment Record -->
    <div style="margin-bottom: 12px; border: 1pt solid #000; padding: 6px 10px; background: #fafafa;">
        <div style="font-family: Arial, Helvetica, sans-serif; font-size: 8pt; font-weight: bold; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 0.75pt solid #ccc; padding-bottom: 3px; margin-bottom: 6px; display: flex; justify-content: space-between; align-items: center;">
            <span>Accounts Ledger Assessment &amp; Official Receipt</span>
            <span>Status: <strong><?php echo htmlspecialchars($balance <= 0 ? 'OFFICIALLY ENROLLED / FULLY PAID' : ($amountPaid > 0 ? 'DOWNPAYMENT CLEARED / PARTIALLY PAID' : 'PENDING PAYMENT')); ?></strong></span>
        </div>
        <div style="display: table; width: 100%;">
            <div style="display: table-row;">
                <div style="display: table-cell; width: 25%;">
                    <span class="field-label">Overall Semester Assessment</span>
                    <span class="mono" style="font-size: 9.5pt; font-weight: bold;">&#8369; <?php echo number_format($totalFee, 2); ?></span>
                </div>
                <div style="display: table-cell; width: 25%;">
                    <span class="field-label">Amount Paid</span>
                    <span class="mono" style="font-size: 9.5pt; font-weight: bold; color: #166534;">&#8369; <?php echo number_format($amountPaid, 2); ?></span>
                </div>
                <div style="display: table-cell; width: 25%;">
                    <span class="field-label">Remaining Balance</span>
                    <span class="mono" style="font-size: 9.5pt; font-weight: bold; <?php echo $balance > 0 ? 'color: #991b1b;' : 'color: #333;'; ?>">&#8369; <?php echo number_format($balance, 2); ?></span>
                </div>
                <div style="display: table-cell; width: 25%;">
                    <span class="field-label">Official Receipt (OR) / Ref</span>
                    <span class="mono" style="font-size: 9.5pt; font-weight: bold;"><?php echo htmlspecialchars($orNumber); ?></span>
                </div>
            </div>
        </div>
    </div>

    <div class="two-col">
        <div class="col-left">
            <div class="section-heading">Assessment of Fees</div>
            <div class="fee-row">
                <span>Tuition Fee (&#8369;<?php echo number_format($tuitionRate, 2); ?>/unit):</span>
                <span class="mono"><?php echo number_format($tuitionFee, 2); ?></span>
            </div>
            <div class="fee-row">
                <span>Laboratory Fee:</span>
                <span class="mono"><?php echo number_format($totalLabFee, 2); ?></span>
            </div>
            <div class="fee-row">
                <span>Miscellaneous:</span>
                <span class="mono"><?php echo number_format($miscFee, 2); ?></span>
            </div>
            <div class="fee-row">
                <span>LMS Fee:</span>
                <span class="mono"><?php echo number_format($lmsFee, 2); ?></span>
            </div>
            <div class="fee-row">
                <span>NSTP / ROTC:</span>
                <span class="mono"><?php echo number_format($nstpFee, 2); ?></span>
            </div>
            <div class="fee-row">
                <span>OMR:</span>
                <span class="mono"><?php echo number_format($omrFee, 2); ?></span>
            </div>
            <?php if ($discount > 0): ?>
            <div class="fee-row discount">
                <span>Scholarship Discount:</span>
                <span class="mono">&ndash; <?php echo number_format($discount, 2); ?></span>
            </div>
            <?php endif; ?>
            <div class="fee-row total-line">
                <span>Cash Total:</span>
                <span class="mono double-line">&#8369; <?php echo number_format($cashTotal, 2); ?></span>
            </div>
            <div style="margin-top:8px;">
                <div class="fee-row">
                    <span>Installment Charge (8%):</span>
                    <span class="mono"><?php echo number_format($installmentCharge, 2); ?></span>
                </div>
                <div class="fee-row total-line">
                    <span>Installment Total:</span>
                    <span class="mono double-line">&#8369; <?php echo number_format($installmentTotal, 2); ?></span>
                </div>
                <div style="font-size:7.5pt; margin-top:4px; font-style:italic; color:#555;">
                    Installment charge does not apply to full-payment transactions.
                </div>
            </div>
        </div>

        <div class="col-right">
            <div class="section-heading">Schedule of Payments</div>
            <table class="payment-table">
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
                            <td style="<?php echo $item['milestone'] === 'Upon Registration' ? 'font-weight:bold;' : ''; ?>">
                                <?php echo htmlspecialchars($item['milestone']); ?>
                            </td>
                            <td class="<?php echo ($item['status'] === 'CLEARED' || $item['status'] === 'PAID') ? 'paid-cell' : ''; ?>">
                                <?php echo htmlspecialchars($item['dueDate']); ?>
                            </td>
                            <td class="text-right mono" style="font-weight:bold;<?php echo ($item['status'] === 'PAID' || $item['status'] === 'CLEARED') ? 'color:#166534;' : ''; ?>">
                                <?php echo htmlspecialchars($item['formattedAmount']); ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                    <tr>
                        <td style="font-weight:bold;">Upon Registration</td>
                        <td>Upon Enrollment</td>
                        <td class="text-right mono" style="font-weight:bold;">
                            <?php echo $student['payment_mode'] === 'Installment'
                                ? '&#8369; ' . number_format($installmentTotal, 2)
                                : '&#8369; ' . number_format($cashTotal, 2); ?>
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
            <div style="font-size:7.5pt; margin-top:6px; font-style:italic; color:#555; text-align:justify;">
                Outright payment of adding/dropping charge is required when changing class schedule(s).
            </div>
        </div>
    </div>

    <div class="sig-row">
        <div class="sig-cell">
            <div class="stamp-box <?php echo !empty($student['or_number']) ? 'paid' : ''; ?>">
                <?php if (!empty($student['or_number'])): ?>
                    <span>PAID / ENROLLED</span>
                    <span style="font-size:8pt;font-family:'Courier New',monospace;font-weight:normal;margin-top:4px;">
                        OR #<?php echo htmlspecialchars($student['or_number']); ?>
                    </span>
                <?php else: ?>
                    Cashier Stamp
                <?php endif; ?>
            </div>
        </div>
        <div class="sig-cell">
            <div class="sig-line">
                Cashier Representative
                <div class="sig-sub">Treasury Department</div>
            </div>
        </div>
        <div class="sig-cell">
            <div class="sig-line">
                Registrar Officer
                <div class="sig-sub">Office of the University Registrar</div>
            </div>
        </div>
    </div>

    <div class="footer-note">
        Note to the students: Enrollment is valid only upon acceptance of payment by the Treasury Department within the next working day from the day of encoding.
        GNCP reserves the right, at its sole discretion, to displace/delete transactions that are deemed inactive and/or unpaid after the allotted enrollment period without incurring any liability whatsoever.
    </div>
    <div class="footer-meta">
        <span>Print Date: <?php echo date('d/m/Y h:i:sa'); ?></span>
        <span>Enrollment Date: <?php echo date('d/m/Y h:i:sa', strtotime($student['created_at'])); ?></span>
        <span>Encoder: <?php echo htmlspecialchars($student['cashier_name'] ?: 'sbaltazar3'); ?></span>
    </div>

</div>

<script>
    window.onload = function() {
        const p = new URLSearchParams(window.location.search);
        if (p.get('autoprint') === 'true') window.print();
    };
</script>
</body>
</html>
