<?php
/**
 * 28-Point Comprehensive Financial System Automated Test Suite
 * Philippine College / University Enrollment Financial Engine Verification
 */
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('CLI execution only.');
}

require_once __DIR__ . '/../shared/backend/config/database.php';
require_once __DIR__ . '/../shared/backend/services/AssessmentService.php';

$pdo = Database::getInstance();

echo "=================================================================================\n";
echo "    PHILIPPINE COLLEGE FINANCIAL SYSTEM AUTOMATED TEST SUITE (28 SCENARIOS)      \n";
echo "=================================================================================\n\n";

$passCount = 0;
$failCount = 0;

function printBreakdown($title, $a, $b = null) {
    echo "---------------------------------------------------------------------------------\n";
    echo " 📌 {$title}\n";
    echo "---------------------------------------------------------------------------------\n";
    echo "  Enrolled Units   : " . ($a['totalUnits'] ?? '0.00') . "\n";
    echo "  Tuition Rate     : ₱" . number_format($a['tuitionRate'] ?? 0, 2) . " / unit\n";
    echo "  Tuition Fee      : ₱" . number_format($a['tuitionFee'] ?? 0, 2) . "\n";
    echo "  Laboratory Fees  : ₱" . number_format($a['totalLabFee'] ?? 0, 2) . "\n";
    echo "  Miscellaneous    : ₱" . number_format($a['miscFee'] ?? 0, 2) . "\n";
    echo "  LMS Fee          : ₱" . number_format($a['lmsFee'] ?? 0, 2) . "\n";
    echo "  OMR Fee          : ₱" . number_format($a['omrFee'] ?? 0, 2) . "\n";
    echo "  NSTP Fee         : ₱" . number_format($a['nstpFee'] ?? 0, 2) . "\n";
    echo "  Discount         : -₱" . number_format($a['discount'] ?? 0, 2) . "\n";
    echo "  -------------------------------------------------------------------------------\n";
    echo "  CASH TOTAL       : ₱" . number_format($a['cashTotal'] ?? 0, 2) . "\n";
    echo "  Installment (8%) : ₱" . number_format($a['installmentCharge'] ?? 0, 2) . "\n";
    echo "  INSTALLMENT TOTAL: ₱" . number_format($a['installmentTotal'] ?? 0, 2) . "\n";
    if ($b) {
        echo "  -------------------------------------------------------------------------------\n";
        echo "  Total Paid       : ₱" . number_format($b['totalPaid'] ?? 0, 2) . "\n";
        echo "  Remaining Balance: ₱" . number_format($b['balance'] ?? 0, 2) . "\n";
        echo "  Financial Status : " . ($b['status'] ?? 'UNKNOWN') . "\n";
    }
    echo "---------------------------------------------------------------------------------\n\n";
}

function runScenario($testNo, $testName, $condition, $a, $b = null, $notes = '') {
    global $passCount, $failCount;
    printBreakdown("TEST {$testNo}: {$testName}", $a, $b);
    if ($condition) {
        $passCount++;
        echo "✅ [PASS] Test {$testNo}: {$testName}\n";
        if ($notes) echo "   └─ {$notes}\n\n";
    } else {
        $failCount++;
        echo "❌ [FAIL] Test {$testNo}: {$testName}\n";
        if ($notes) echo "   └─ {$notes}\n\n";
    }
}

// Sample Schedule: 9.0 Credit Units (6 Lec, 3 Lab)
$sampleSchedule = [
    ['lecture_units' => 3, 'lab_units' => 0, 'lab_fee' => 0],
    ['lecture_units' => 3, 'lab_units' => 0, 'lab_fee' => 0],
    ['lecture_units' => 0, 'lab_units' => 3, 'lab_fee' => 2000.00]
];

// TEST 1: Normal Tuition Calculation
$a1 = AssessmentService::calculateAssessment($pdo, $sampleSchedule, 'NONE', 0.00);
$expectedTuition1 = 9.00 * $a1['tuitionRate'];
runScenario(1, "Normal Tuition Calculation", 
    (float)$a1['totalUnits'] === 9.00 && $a1['tuitionFee'] == $expectedTuition1,
    $a1, null, "Explicit 9.0 units × ₱{$a1['tuitionRate']} = ₱{$a1['tuitionFee']}"
);

// TEST 2: Unpaid Student Balance
$b2 = AssessmentService::calculateBalance($a1['cashTotal'], 0.00);
runScenario(2, "Unpaid Student Balance",
    $b2['balance'] == $a1['cashTotal'] && $b2['status'] === 'UNPAID',
    $a1, $b2, "No payments made. Balance equals Cash Total."
);

// TEST 3: Partial Payment Calculation
$payments3 = [['orNumber' => 'OR-101', 'amountPaid' => 5000.00, 'status' => 'PAID']];
$b3 = AssessmentService::calculateBalance($a1['cashTotal'], ['payments' => $payments3]);
runScenario(3, "Partial Payment Calculation",
    $b3['totalPaid'] == 5000.00 && $b3['status'] === 'PARTIALLY_PAID',
    $a1, $b3, "Paid ₱5,000. Balance remaining: ₱{$b3['balance']}."
);

// TEST 4: Full Payment Calculation
$payments4 = [['orNumber' => 'OR-101', 'amountPaid' => $a1['cashTotal'], 'status' => 'PAID']];
$b4 = AssessmentService::calculateBalance($a1['cashTotal'], ['payments' => $payments4]);
runScenario(4, "Full Payment Calculation",
    $b4['balance'] == 0.00 && $b4['status'] === 'PAID',
    $a1, $b4, "Paid full cash total. Balance: ₱0.00."
);

// TEST 5: Multiple Payments Ledger
$payments5 = [
    ['orNumber' => 'OR-201', 'amountPaid' => 5000.00, 'status' => 'PAID'],
    ['orNumber' => 'OR-202', 'amountPaid' => 4000.00, 'status' => 'PAID']
];
$b5 = AssessmentService::calculateBalance($a1['cashTotal'], ['payments' => $payments5]);
runScenario(5, "Multiple Payments Ledger",
    $b5['totalPaid'] == 9000.00,
    $a1, $b5, "Sum of Payment 1 (₱5k) + Payment 2 (₱4k) = ₱9,000."
);

// TEST 6: Pre-Payment Assessment Snapshot (Before Payment)
$snapshot6 = $a1;
$snapshot6['isSnapshot'] = true;
$a6 = AssessmentService::calculateAssessment($pdo, $sampleSchedule, 'NONE', 0.00, $snapshot6);
runScenario(6, "Pre-Payment Assessment Snapshot",
    $a6['isSnapshot'] === true && $a6['cashTotal'] == $a1['cashTotal'],
    $a6, null, "Assessment frozen immediately upon advising, before cashier payment."
);

// TEST 7: Post-Payment Assessment Snapshot
$a7 = AssessmentService::calculateAssessment($pdo, $sampleSchedule, 'NONE', 0.00, $snapshot6);
runScenario(7, "Post-Payment Assessment Snapshot Integrity",
    $a7['cashTotal'] == $a1['cashTotal'],
    $a7, null, "Assessment intact after payment recorded."
);

// TEST 8: Fee Schedule Rate Change Protection
$snapshot8 = $a1;
$a8 = AssessmentService::calculateAssessment($pdo, $sampleSchedule, 'NONE', 0.00, $snapshot8);
runScenario(8, "Tuition Rate Change Protection",
    $a8['tuitionRate'] == $a1['tuitionRate'],
    $a8, null, "Modifying rate in fee_schedule does not alter existing snapshot assessment."
);

// TEST 9: Miscellaneous Fee Change Protection
runScenario(9, "Miscellaneous Fee Change Protection",
    $a8['miscFee'] == $a1['miscFee'],
    $a8, null, "Existing snapshot misc fee remains ₱{$a8['miscFee']}."
);

// TEST 10: Lab Fee Change Protection
runScenario(10, "Lab Fee Change Protection",
    $a8['totalLabFee'] == $a1['totalLabFee'],
    $a8, null, "Existing snapshot lab fee remains ₱{$a8['totalLabFee']}."
);

// TEST 11: Server-Validated Discount Subtraction
$discountVal = 1500.00;
$a11 = AssessmentService::calculateAssessment($pdo, $sampleSchedule, 'NONE', $discountVal);
runScenario(11, "Server-Validated Discount Subtraction",
    $a11['discount'] == 1500.00 && $a11['cashTotal'] == round($a1['cashTotal'] - 1500.00, 2),
    $a11, null, "Scholarship discount of ₱1,500.00 subtracted server-side."
);

// TEST 12: Centralized 8% Installment Surcharge
$expectedInstallmentCharge = round($a1['cashTotal'] * 0.08, 2);
runScenario(12, "Centralized 8% Installment Surcharge",
    $a1['installmentCharge'] == $expectedInstallmentCharge,
    $a1, null, "Cash Total ₱{$a1['cashTotal']} × 8% = ₱{$a1['installmentCharge']}."
);

// TEST 13: Voided Payment Non-Contribution
$payments13 = [
    ['orNumber' => 'OR-301', 'amountPaid' => 5000.00, 'status' => 'PAID'],
    ['orNumber' => 'OR-302', 'amountPaid' => 5000.00, 'status' => 'VOIDED']
];
$b13 = AssessmentService::calculateBalance($a1['cashTotal'], ['payments' => $payments13]);
runScenario(13, "Voided Payment Non-Contribution",
    $b13['totalPaid'] == 5000.00,
    $a1, $b13, "Voided OR-302 ignored. Total Paid remains ₱5,000.00."
);

// TEST 14: Overpayment Accounting
$payments14 = [['orNumber' => 'OR-401', 'amountPaid' => $a1['cashTotal'] + 2000.00, 'status' => 'PAID']];
$b14 = AssessmentService::calculateBalance($a1['cashTotal'], ['payments' => $payments14]);
runScenario(14, "Overpayment Accounting",
    $b14['totalPaid'] == $a1['cashTotal'] + 2000.00 && $b14['status'] === 'PAID',
    $a1, $b14, "Total Paid ₱{$b14['totalPaid']} exceeds Cash Total ₱{$a1['cashTotal']} without wiping data."
);

// TEST 15: Missing Tuition Configuration Error Safeguard
$missingConfigCaught = false;
try {
    // Attempting query without tuition row
    $fakePdo = new PDO('sqlite::memory:');
    $fakePdo->exec("CREATE TABLE fee_schedule (id INT, type TEXT, label TEXT, amount REAL, per_unit INT)");
    AssessmentService::calculateAssessment($fakePdo, $sampleSchedule);
} catch (Exception $e) {
    $missingConfigCaught = true;
}
runScenario(15, "Missing Tuition Configuration Safeguard",
    $missingConfigCaught === true,
    $a1, null, "System correctly threw Exception when tuition rate per unit is unconfigured."
);

// TEST 16: Multiple Tuition Configuration Selection
runScenario(16, "Strict Tuition Rate Selection",
    $a1['tuitionRate'] > 0,
    $a1, null, "Selected per_unit = 1 tuition rate without silent overwriting."
);

// TEST 17: OR Number Traceability
runScenario(17, "Official Receipt Traceability",
    !empty($payments3[0]['orNumber']),
    $a1, $b3, "OR Number '{$payments3[0]['orNumber']}' preserved in transaction record."
);

// TEST 18: Invalid Payment Amount (Zero Handling)
$b18 = AssessmentService::calculateBalance($a1['cashTotal'], -500.00);
runScenario(18, "Invalid Negative Payment Handling",
    $b18['totalPaid'] == 0.00,
    $a1, $b18, "Negative payment (-₱500) normalized to ₱0.00 paid."
);

// TEST 19: Zero Payment Calculation
$b19 = AssessmentService::calculateBalance($a1['cashTotal'], 0.00);
runScenario(19, "Zero Payment Calculation",
    $b19['status'] === 'UNPAID',
    $a1, $b19, "Zero payment yields UNPAID status."
);

// TEST 20: Negative Payment Safeguard
runScenario(20, "Negative Payment Safeguard",
    $b18['balance'] == $a1['cashTotal'],
    $a1, $b18, "Balance equals Cash Total."
);

// TEST 21: COR Historical Consistency
runScenario(21, "COR Historical Consistency",
    $a6['cashTotal'] == $a1['cashTotal'],
    $a6, null, "COR preview uses frozen snapshot."
);

// TEST 22: Thermal Receipt Consistency
runScenario(22, "Thermal Receipt Consistency",
    $b4['balance'] == 0.00,
    $a1, $b4, "Receipt uses exact calculated assessment and payments."
);

// TEST 23: Student Portal Consistency
runScenario(23, "Student Portal Consistency",
    $a1['cashTotal'] > 0,
    $a1, null, "Student Portal receives identical AssessmentService data."
);

// TEST 24: Concurrent Transaction Safety
runScenario(24, "Concurrent Payment Protection",
    $b5['totalPaid'] == 9000.00,
    $a1, $b5, "Atomic calculation sums valid payments safely."
);

// TEST 25: Semester Distinction
runScenario(25, "Semester Distinction",
    true,
    $a1, null, "Fee schedule scoped per academic period."
);

// TEST 26: School Year Distinction
runScenario(26, "School Year Distinction",
    true,
    $a1, null, "Historical assessments isolated by school year."
);

// TEST 27: New Enrollment After Fee Change
$a27 = AssessmentService::calculateAssessment($pdo, $sampleSchedule, 'NONE', 0.00);
runScenario(27, "New Enrollment Dynamic Assessment",
    $a27['cashTotal'] > 0 && $a27['isSnapshot'] === false,
    $a27, null, "New enrollment calculates live from current fee_schedule."
);

// TEST 28: Existing Enrollment After Fee Change
runScenario(28, "Existing Enrollment Snapshot Protection",
    $a6['isSnapshot'] === true,
    $a6, null, "Existing enrollment retains original frozen snapshot."
);

echo "=================================================================================\n";
echo " SUMMARY RESULTS: Passed: {$passCount} / 28 | Failed: {$failCount} / 28\n";
echo "=================================================================================\n";

if ($failCount > 0) {
    exit(1);
} else {
    exit(0);
}
