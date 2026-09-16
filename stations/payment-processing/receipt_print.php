<?php
require_once __DIR__ . '/../../shared/backend/config/database.php';
require_once __DIR__ . '/../../shared/backend/services/AssessmentService.php';
require_once __DIR__ . '/../../shared/backend/utils/session_guard.php';

$ref = $_GET['ref'] ?? '';

if (empty($ref)) {
    die("<h1 style='font-family:sans-serif; text-align:center; margin-top:50px;'>Error: Student reference number is required.</h1>");
}

try {
    $pdo = Database::getInstance();



    // Retrieve pre-enrollment details
    $stmt = $pdo->prepare("
        SELECT p.*, pr.name as program_name, ap.semester as ap_semester
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
            SELECT s.*, pr.name as program_name, ap.semester as ap_semester
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
            
            $student = [
                'temp_student_id' => $permStudent['temp_reference_no'] ?: $permStudent['id'],
                'first_name' => $personal['firstName'] ?? '',
                'middle_name' => $personal['middleName'] ?? '',
                'last_name' => $personal['lastName'] ?? '',
                'course_code' => $permStudent['program'],
                'program_name' => $permStudent['program_name'],
                'ap_semester' => $permStudent['ap_semester'],
                'address' => $personal['address'] ?? '',
                'phone' => $personal['phone'] ?? '',
                'gender' => $personal['gender'] ?? '',
                'temp_pin' => $enrollment['temp_pin'] ?? '',
                'section_code' => $enrollment['assignedSection'] ?? $permStudent['section_code'] ?? '',
                'or_number' => $payment['orNumber'] ?? $permStudent['or_number'] ?? '',
                'payment_mode' => $payment['paymentType'] ?? $permStudent['payment_mode'] ?? 'Cash',
                'enrolled_at' => $permStudent['enrolled_at'] ?? null,
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

    // Access control: allow staff with valid single-active session, matching authenticated student, or valid PIN
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
        $reqPin = trim($_GET['pin'] ?? '');
        $storedPin = (string)($student['temp_pin'] ?? '');
        if (!empty($reqPin) && !empty($storedPin) && hash_equals($storedPin, $reqPin)) {
            $isAuthorized = true;
        }
    }

    if (!$isAuthorized) {
        http_response_code(401);
        die("<h1 style='font-family:sans-serif; text-align:center; margin-top:50px; color:#dc2626;'>401 Unauthorized: Cashier authorization or valid security PIN required. Access denied.</h1>");
    }

    // Decode JSON payment details
    $payment = json_decode($student['payment_data'] ?? '{}', true);
    $helpdesk = json_decode($student['helpdesk_data'] ?? '{}', true);
    $advisedSubjects = $helpdesk['advisedSubjects'] ?? [];

    // Calculate fee assessment and payment balance via authoritative AssessmentService
    $nstpType = strtoupper($student['nstp'] ?? 'NONE');
    $scholarshipData = json_decode($student['scholarship_data'] ?? '{}', true);
    $discount = (float)($scholarshipData['discount'] ?? 0.00);
    $snapshot = $payment['assessmentSnapshot'] ?? null;

    $assessment = AssessmentService::calculateAssessment($pdo, $advisedSubjects, $nstpType, $discount, $snapshot);

    $tuitionRate = $assessment['tuitionRate'];
    $tuitionFee  = $assessment['tuitionFee'];
    $totalLabFee = $assessment['totalLabFee'];
    $miscFee     = $assessment['miscFee'];
    $lmsFee      = $assessment['lmsFee'];
    $nstpFee     = $assessment['nstpFee'];
    $omrFee      = $assessment['omrFee'];
    $cashTotal   = $assessment['cashTotal'];
    $totalUnits  = (float)$assessment['totalUnits'];

    $rawPaid = isset($payment['amountPaid']) ? (float)$payment['amountPaid'] : $cashTotal;
    $balInfo = AssessmentService::calculateBalance($cashTotal, $rawPaid);

    $amountPaid  = $balInfo['amountPaid'];
    $balance     = $balInfo['balance'];
    $paymentMode = $payment['paymentType'] ?? $student['payment_mode'] ?? 'Cash';
    $txnRef      = $payment['transactionRef'] ?? 'TXN-' . rand(100000, 999999);

} catch (Exception $e) {
    die("<h1 style='font-family:sans-serif; text-align:center; margin-top:50px;'>Database error: " . $e->getMessage() . "</h1>");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Official Receipt - <?php echo htmlspecialchars((string)($student['or_number'] ?? 'PENDING')); ?></title>
    <link rel="stylesheet" href="../../shared/libs/bootstrap.bundle.min.js">
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: 'Courier New', Courier, monospace;
            color: #1a1a1a;
            background-color: #f1f5f9;
            margin: 0;
            padding: 30px 20px;
            font-size: 13px;
        }
        .receipt-container {
            width: 100%;
            max-width: 820px;
            margin: 0 auto;
            background: #ffffff;
            border: 2px solid #004A3C;
            border-radius: 12px;
            padding: 24px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.08);
        }
        .header-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 2px solid #004A3C;
            padding-bottom: 12px;
            margin-bottom: 16px;
        }
        .school-title {
            color: #004A3C;
            font-family: sans-serif;
            font-size: 1.25rem;
            font-weight: 800;
            margin: 0 0 4px 0;
            letter-spacing: 0.5px;
        }
        .school-subtitle {
            color: #64748b;
            font-size: 11px;
            font-family: sans-serif;
        }
        .receipt-badge-title {
            color: #cda819;
            font-weight: 800;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 1px;
            text-align: right;
        }
        .or-number-badge {
            font-family: monospace;
            font-weight: 700;
            color: #475569;
            font-size: 12px;
            text-align: right;
        }
        .meta-grid {
            display: flex;
            justify-content: space-between;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 12px 16px;
            margin-bottom: 16px;
        }
        .meta-col {
            flex: 1;
        }
        .meta-col:last-child {
            border-left: 1px solid #cbd5e1;
            padding-left: 16px;
        }
        .meta-item {
            margin-bottom: 4px;
        }
        .meta-item:last-child {
            margin-bottom: 0;
        }
        .particulars-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 16px;
        }
        .particulars-table th, .particulars-table td {
            border: 1px solid #334155;
            padding: 6px 10px;
        }
        .particulars-table th {
            background: #f1f5f9;
            font-weight: bold;
            text-align: center;
        }
        .text-end { text-align: right !important; }
        .text-center { text-align: center !important; }
        .text-start { text-align: left !important; }
        .fw-bold { font-weight: bold; }
        .text-success { color: #008000; }
        .text-danger { color: #dc2626; }
        
        .footer-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 16px;
            padding-top: 12px;
            border-top: 1px solid #cbd5e1;
        }
        .disclaimer-text {
            font-size: 10px;
            color: #64748b;
            max-width: 65%;
            line-height: 1.3;
        }
        .signature-box {
            text-align: center;
            border-top: 1px solid #000;
            padding-top: 6px;
            width: 180px;
        }
        .signature-title {
            font-size: 10px;
            font-weight: bold;
        }
        .signature-sub {
            font-size: 9px;
            color: #64748b;
        }
        .print-btn-container {
            text-align: center;
            margin-bottom: 20px;
        }
        .btn-print {
            background-color: #006A4E;
            color: #fff;
            border: none;
            padding: 8px 24px;
            font-size: 13px;
            font-weight: bold;
            cursor: pointer;
            border-radius: 6px;
            font-family: sans-serif;
            box-shadow: 0 4px 10px rgba(0, 106, 78, 0.2);
        }
        @media print {
            body {
                background: #ffffff !important;
                padding: 0 !important;
            }
            .print-btn-container {
                display: none !important;
            }
            .receipt-container {
                border: 1px solid #000000 !important;
                box-shadow: none !important;
                max-width: 100% !important;
                border-radius: 0 !important;
                padding: 16px !important;
            }
        }
    </style>
</head>
<body>

<div class="print-btn-container">
    <button class="btn-print" onclick="window.print()">Print Official Receipt</button>
</div>

<div class="receipt-container">
    <div class="header-row">
        <div>
            <h4 class="school-title">GO-ON NATIONAL COLLEGE OF THE PHILIPPINES</h4>
            <div class="school-subtitle">Emilio Aguinaldo Highway, Dasmariñas City, Cavite</div>
        </div>
        <div>
            <div class="receipt-badge-title">Official Receipt</div>
            <div class="or-number-badge">OR: <?php echo htmlspecialchars((string)($student['or_number'] ?: $txnRef)); ?></div>
        </div>
    </div>

    <div class="meta-grid">
        <div class="meta-col">
            <div class="meta-item"><strong>Student Name:</strong> <?php echo htmlspecialchars($student['last_name'] . ', ' . $student['first_name'] . ' ' . $student['middle_name']); ?></div>
            <div class="meta-item"><strong>Reference No:</strong> <?php echo htmlspecialchars($student['temp_student_id']); ?></div>
            <div class="meta-item"><strong>Degree Course:</strong> <?php echo htmlspecialchars($student['course_code'] . ' - ' . ($student['program_name'] ?? '')); ?></div>
        </div>
        <div class="meta-col">
            <div class="meta-item"><strong>Payment Date:</strong> <?php echo date('F d, Y h:i A', strtotime($student['enrolled_at'] ?: 'now')); ?></div>
            <div class="meta-item"><strong>Payment Mode:</strong> <?php echo htmlspecialchars($paymentMode); ?></div>
            <div class="meta-item"><strong>Cashier:</strong> <?php echo htmlspecialchars($student['cashier_name'] ?: 'Cashier Representative'); ?></div>
        </div>
    </div>

    <table class="particulars-table">
        <thead>
            <tr>
                <th class="text-start">Particulars</th>
                <th>Total Assessment</th>
                <th>Amount Paid</th>
                <th>Outstanding Balance</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td class="text-start fw-bold">
                    GNCP College Matriculation (AY 2026-2027)
                    <div style="font-size: 11px; font-weight: normal; color: #475569; margin-top: 2px;">
                        Tuition (<?php echo $totalUnits; ?> Units): ₱<?php echo number_format($tuitionFee, 2); ?> | Lab: ₱<?php echo number_format($totalLabFee, 2); ?> | Misc: ₱<?php echo number_format($miscFee, 2); ?> | LMS: ₱<?php echo number_format($lmsFee, 2); ?>
                        <?php if ($discount > 0): ?> | Discount: -₱<?php echo number_format($discount, 2); ?><?php endif; ?>
                    </div>
                </td>
                <td class="text-center">₱<?php echo number_format($cashTotal, 2); ?></td>
                <td class="text-center fw-bold text-success">₱<?php echo number_format($amountPaid, 2); ?></td>
                <td class="text-center fw-bold <?php echo $balance > 0 ? 'text-danger' : 'text-success'; ?>">₱<?php echo number_format($balance, 2); ?></td>
            </tr>
        </tbody>
    </table>

    <div class="footer-row">
        <div class="disclaimer-text">
            Disclaimer: This serves as an official electronic receipt of payment validation for Go-on National College of the Philippines. Keep this copy for records.
        </div>
        <div class="signature-box">
            <div class="signature-title">AUTHORIZED SIGNATURE</div>
            <div class="signature-sub">GNCP Finance &amp; Treasury</div>
        </div>
    </div>
</div>

<script>
    window.onload = function() {
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('autoprint') === 'true') {
            window.print();
        }
    }
</script>
</body>
</html>
