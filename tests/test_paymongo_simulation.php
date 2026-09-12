<?php
/**
 * Automated Test Suite - PayMongo Payment Gateway Simulation
 * Validates checkout generation, centavo calculations, Rule-002 enforcement, and ACID settlement.
 */
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('CLI execution only.');
}

require_once __DIR__ . '/../shared/backend/config/database.php';
require_once __DIR__ . '/../shared/backend/services/PayMongoService.php';

$pdo = Database::getInstance();

$totalTests = 0;
$passedTests = 0;
$failedTests = 0;

function assertCondition(string $desc, bool $condition, string $details = '') {
    global $totalTests, $passedTests, $failedTests;
    $totalTests++;
    if ($condition) {
        $passedTests++;
        echo "  [\033[32mPASS\033[0m] {$desc}\n";
    } else {
        $failedTests++;
        echo "  [\033[31mFAIL\033[0m] {$desc}" . ($details ? " ({$details})" : "") . "\n";
    }
}

echo "========================================================================\n";
echo "       GNCP ACADEMIC SYSTEM - PAYMONGO SIMULATION TEST SUITE\n";
echo "========================================================================\n\n";

// --- Scenario 1: Configuration Verification ---
echo "[Scenario 1: Centralized Configuration]\n";
$config = require __DIR__ . '/../shared/backend/config/paymongo.php';
assertCondition("Currency is configured as PHP", ($config['currency'] ?? '') === 'PHP');
assertCondition("Simulation mode is enabled", !empty($config['simulation_mode']));
assertCondition("Payment methods include gcash and qrph", in_array('gcash', $config['payment_method_types'] ?? []) && in_array('qrph', $config['payment_method_types'] ?? []));

// --- Scenario 2: Checkout Session Generation ---
echo "\n[Scenario 2: Checkout Session Generation & Centavo Arithmetic]\n";
$refNo = 'TEST-PM-' . time();
$amount = 3500.50;

try {
    $session = PayMongoService::createCheckoutSession($refNo, $amount, 'Tuition Downpayment Test');
    assertCondition("Checkout session created successfully", $session['success'] === true);
    assertCondition("Session ID follows cs_test format", strpos($session['sessionId'], 'cs_test_') === 0);
    assertCondition("Centavos calculated correctly (3500.50 -> 350050)", $session['amountInCentavos'] === 350050);
    assertCondition("QR Ph payload generated", !empty($session['qrPhPayload']));
    assertCondition("Transaction reference generated", strpos($session['transactionRef'], 'PM-TXN-') === 0);
} catch (Exception $e) {
    assertCondition("Checkout session threw error", false, $e->getMessage());
}

// --- Scenario 3: Negative / Zero Amount Validation ---
echo "\n[Scenario 3: Zero / Negative Amount Validation]\n";
try {
    PayMongoService::createCheckoutSession($refNo, 0.00);
    assertCondition("Zero amount rejected", false, "Allowed zero amount");
} catch (InvalidArgumentException $e) {
    assertCondition("Zero amount properly rejected with InvalidArgumentException", true);
}

try {
    PayMongoService::createCheckoutSession($refNo, -150.00);
    assertCondition("Negative amount rejected", false, "Allowed negative amount");
} catch (InvalidArgumentException $e) {
    assertCondition("Negative amount properly rejected with InvalidArgumentException", true);
}

// --- Scenario 4: Seed Test Applicant & Test Rule-002 Enforcement ---
echo "\n[Scenario 4: Rule-002 Payment Eligibility Enforcement]\n";
$testStudentRef = 'TEST-STUDENT-PM-' . time();
$pdo->prepare("DELETE FROM `pre_enrollments` WHERE `temp_student_id` = :ref")->execute([':ref' => $testStudentRef]);

$initialPaymentData = json_encode([
    'totalFee'   => 18500.00,
    'amountPaid' => 0.00,
    'balance'    => 18500.00,
    'status'     => 'PENDING',
    'history'    => []
]);

$initialRoadmap = json_encode([
    ['stepId' => 'registrar_verification', 'status' => 'COMPLETED'],
    ['stepId' => 'helpdesk_advising',      'status' => 'COMPLETED'],
    ['stepId' => 'medical_checkup',        'status' => 'COMPLETED'],
    ['stepId' => 'cashier_payment',        'status' => 'IN_PROGRESS'],
    ['stepId' => 'it_center_account',      'status' => 'PENDING']
]);

// 4A: Insert as PRE_REGISTERED (Unverified) -> Must Fail Rule-002
$pdo->prepare("INSERT INTO `pre_enrollments` (
    `temp_student_id`, `temp_pin`, `student_type`, `course_code`, `nstp`, 
    `first_name`, `last_name`, `email`, `phone`, `birth_date`, `gender`, 
    `address`, `elementary_school`, `junior_high_school`, `senior_high_school`, 
    `health_status`, `emergency_contact_name`, `emergency_contact_phone`, `payment_mode`, 
    `status`, `payment_data`, `roadmap`
) VALUES (
    :ref, '123456', 'Freshman', 'BSIT', 'CWTS',
    'Test', 'Applicant', 'test.paymongo@gncp.edu.ph', '09123456789', '2005-01-01', 'Male',
    'Dasmarinas Cavite', 'Elem School', 'JHS School', 'SHS School',
    'Good', 'Parent Name', '09123456780', 'Cash',
    'PRE_REGISTERED', :pdata, :rmap
)")->execute([':ref' => $testStudentRef, ':pdata' => $initialPaymentData, ':rmap' => $initialRoadmap]);

try {
    PayMongoService::processPaymentSuccess($testStudentRef, 3000.00, 'GCash');
    assertCondition("RULE-002: Blocked payment on PRE_REGISTERED status", false, "Allowed payment on PRE_REGISTERED");
} catch (RuntimeException $e) {
    assertCondition("RULE-002: Successfully blocked payment for PRE_REGISTERED applicant", strpos($e->getMessage(), 'PRE_REGISTERED') !== false);
}

// --- Scenario 5: Partial Installment Settlement (ADVISED -> PARTIAL) ---
echo "\n[Scenario 5: Partial Installment Online Settlement]\n";
// Update status to ADVISED / MEDICAL_CLEARED
$pdo->prepare("UPDATE `pre_enrollments` SET `status` = 'ADVISED' WHERE `temp_student_id` = :ref")->execute([':ref' => $testStudentRef]);

$partialRes = PayMongoService::processPaymentSuccess($testStudentRef, 3000.00, 'GCash', '', 'Cashier Officer (PayMongo)', 'Downpayment collection test');
assertCondition("Partial settlement succeeded", $partialRes['success'] === true);
assertCondition("Amount paid is 3000.00", (float)$partialRes['amountPaid'] === 3000.00);
assertCondition("Remaining balance is 15500.00", (float)$partialRes['balance'] === 15500.00);
assertCondition("Status updated to PARTIAL", $partialRes['status'] === 'PARTIAL');
assertCondition("isFullPayment is false", $partialRes['isFullPayment'] === false);

// Assert DB state after partial payment
$stmt = $pdo->prepare("SELECT `status`, `payment_data`, `roadmap` FROM `pre_enrollments` WHERE `temp_student_id` = :ref");
$stmt->execute([':ref' => $testStudentRef]);
$dbRow = $stmt->fetch(PDO::FETCH_ASSOC);

assertCondition("DB status is PARTIAL", $dbRow['status'] === 'PARTIAL');
$pData = json_decode($dbRow['payment_data'], true);
assertCondition("DB payment_data has 1 history record", count($pData['history'] ?? []) === 1);
assertCondition("History entry notes PayMongo (GCASH)", ($pData['history'][0]['paymentType'] ?? '') === 'PayMongo (GCASH)');

$rMap = json_decode($dbRow['roadmap'], true);
$cashierStep = null;
$itStep = null;
foreach ($rMap as $step) {
    if ($step['stepId'] === 'cashier_payment') $cashierStep = $step;
    if ($step['stepId'] === 'it_center_account') $itStep = $step;
}
assertCondition("Roadmap cashier_payment step is COMPLETED", $cashierStep['status'] === 'COMPLETED');
assertCondition("Roadmap it_center_account step advanced to IN_PROGRESS", $itStep['status'] === 'IN_PROGRESS');

// --- Scenario 6: Full Balance Settlement (PARTIAL -> PAID) ---
echo "\n[Scenario 6: Full Settlement Final Installment]\n";
$fullRes = PayMongoService::processPaymentSuccess($testStudentRef, 15500.00, 'Maya', '', 'PayMongo Gateway', 'Final balance settlement');
assertCondition("Full balance settlement succeeded", $fullRes['success'] === true);
assertCondition("Total paid equals 18500.00", (float)$fullRes['totalPaid'] === 18500.00);
assertCondition("Remaining balance equals 0.00", (float)$fullRes['balance'] === 0.00);
assertCondition("Status updated to PAID", $fullRes['status'] === 'PAID');
assertCondition("isFullPayment is true", $fullRes['isFullPayment'] === true);

// Assert DB state after full payment
$stmt->execute([':ref' => $testStudentRef]);
$dbRowFinal = $stmt->fetch(PDO::FETCH_ASSOC);
assertCondition("DB final status is PAID", $dbRowFinal['status'] === 'PAID');
$pDataFinal = json_decode($dbRowFinal['payment_data'], true);
assertCondition("DB payment_data has 2 history records", count($pDataFinal['history'] ?? []) === 2);

// Cleanup test student
$pdo->prepare("DELETE FROM `pre_enrollments` WHERE `temp_student_id` = :ref")->execute([':ref' => $testStudentRef]);

// --- Summary Matrix ---
echo "\n========================================================================\n";
echo "PAYMONGO TEST RESULTS: {$passedTests} / {$totalTests} Passed (" . ($failedTests === 0 ? "\033[32m100% SUCCESS\033[0m" : "\033[31m{$failedTests} FAILED\033[0m") . ")\n";
echo "========================================================================\n";

exit($failedTests === 0 ? 0 : 1);
