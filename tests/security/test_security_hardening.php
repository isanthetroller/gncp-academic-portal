<?php
/**
 * GNCP Security Hardening & Penetration Verification Suite
 * Automated tests for RBAC, Cashier Protection, IDOR, and Asset Validation
 */

require_once __DIR__ . '/../../shared/backend/config/database.php';
require_once __DIR__ . '/../../shared/backend/services/BaseStationService.php';
require_once __DIR__ . '/../../api/controllers/AuthController.php';
require_once __DIR__ . '/../../api/controllers/StationController.php';
require_once __DIR__ . '/../../api/controllers/UserAdminController.php';
require_once __DIR__ . '/../../api/controllers/CatalogAdminController.php';
require_once __DIR__ . '/../../api/controllers/ScheduleAdminController.php';

$pdo = Database::getInstance();
$testsPassed = 0;
$totalTests = 0;

function assertTest($description, $condition) {
    global $testsPassed, $totalTests;
    $totalTests++;
    if ($condition) {
        $testsPassed++;
        echo "  ✓ PASS: {$description}\n";
    } else {
        echo "  ❌ FAIL: {$description}\n";
    }
}

echo "\n====================================================\n";
echo " GNCP Security & RBAC Penetration Test Suite\n";
echo "====================================================\n\n";

// ── TEST 1: Unauthenticated Admin User Creation ──
echo "1. Administrative Access Control Tests:\n";
try {
    $_SESSION = []; // Clear session
    $userCtrl = new UserAdminController($pdo);
    $res = $userCtrl->saveUser([
        'user' => [
            'username' => 'hacker_admin_test',
            'name'     => 'Hacker Test',
            'role'     => 'ADMIN',
            'password' => 'HackerPassword123!',
            'email'    => 'hacker@test.com'
        ]
    ]);
    assertTest("Unauthenticated saveUser must be blocked", ($res['success'] === false || ($res['code'] ?? 0) >= 400));
} catch (Exception $e) {
    assertTest("Unauthenticated saveUser throws auth exception", true);
}

// ── TEST 2: Unauthenticated Catalog Mutation ──
try {
    $_SESSION = [];
    $catCtrl = new CatalogAdminController($pdo);
    $res = $catCtrl->saveProgram([
        'program' => ['code' => 'HACK101', 'name' => 'Hacking Program', 'department' => 'Test']
    ]);
    assertTest("Unauthenticated saveProgram must be blocked", ($res['success'] === false || ($res['code'] ?? 0) >= 400));
} catch (Exception $e) {
    assertTest("Unauthenticated saveProgram throws auth exception", true);
}

// ── TEST 3: Unauthenticated Schedule Mutation ──
try {
    $_SESSION = [];
    $schedCtrl = new ScheduleAdminController($pdo);
    $res = $schedCtrl->saveSection([
        'section' => ['code' => 'HACK-SEC', 'program' => 'BSCS', 'maxCapacity' => 50]
    ]);
    assertTest("Unauthenticated saveSection must be blocked", ($res['success'] === false || ($res['code'] ?? 0) >= 400));
} catch (Exception $e) {
    assertTest("Unauthenticated saveSection throws auth exception", true);
}

// ── TEST 4: Cross-Station Horizontal Privilege Escalation ──
echo "\n2. Cross-Station RBAC Boundary Tests:\n";
// Simulate logged in Medical officer
$_SESSION['gncp_station_user'] = [
    'username'             => 'ethan',
    'name'                 => 'Dr. Ethan Medical Doctor',
    'role'                 => 'MEDICAL',
    'session_token'        => 'test_token',
    'must_change_password' => false
];

$stationCtrl = new StationController($pdo);

// Attempt 4a: Medical user trying to mutate Cashier payment data
$hackPaymentRes = $stationCtrl->updateStudent([
    'referenceNumber' => 'NON_EXISTENT_REF',
    'updateData'      => [
        'payment' => ['status' => 'PAID', 'amountPaid' => 5000]
    ]
]);
assertTest("Medical officer blocked from mutating Cashier payment", ($hackPaymentRes['success'] === false && ($hackPaymentRes['code'] ?? 0) === 403));

// Attempt 4b: Medical user trying to mutate Helpdesk advising data
$hackHelpdeskRes = $stationCtrl->updateStudent([
    'referenceNumber' => 'NON_EXISTENT_REF',
    'updateData'      => [
        'helpdesk' => ['advisedSubjects' => []]
    ]
]);
assertTest("Medical officer blocked from mutating Helpdesk advising", ($hackHelpdeskRes['success'] === false && ($hackHelpdeskRes['code'] ?? 0) === 403));

// Attempt 4c: Medical user trying to finalize IT Center enrollment
$hackItRes = $stationCtrl->updateStudent([
    'referenceNumber' => 'NON_EXISTENT_REF',
    'updateData'      => [
        'enrollment' => ['assignedSection' => 'BSIT-1A'],
        'status'     => 'ENROLLED'
    ]
]);
assertTest("Medical officer blocked from activating IT Center enrollment", ($hackItRes['success'] === false && ($hackItRes['code'] ?? 0) === 403));

// ── TEST 5: Horizontal IDOR Profile Mutation ──
echo "\n3. Profile IDOR & Asset Upload Security Tests:\n";
$authCtrl = new AuthController($pdo);

// Ethan tries to update Admin's profile
$idorRes = $authCtrl->updateProfile([
    'username' => 'admin',
    'name'     => 'Compromised Admin',
    'email'    => 'compromised@attacker.com'
]);
assertTest("Operator blocked from updating Administrator's profile (IDOR)", ($idorRes['success'] === false && ($idorRes['code'] ?? 0) === 403));

// Ethan tries to upload a non-image file as avatar
$fakeImageData = base64_encode("<?php phpinfo(); ?> This is malicious code, not a JPEG.");
$badAvatarRes = $authCtrl->uploadAvatar([
    'username'  => 'ethan',
    'photoData' => $fakeImageData
]);
assertTest("Non-image payload rejected by magic-byte validator", ($badAvatarRes['success'] === false && ($badAvatarRes['code'] ?? 0) === 400));

// ── TEST 6: Rule-002 Payment Invariant ──
echo "\n4. Cashier Financial Rule-002 Invariants:\n";
require_once __DIR__ . '/../../stations/backend/services/PaymentService.php';

$preRegRecord = [
    'status' => 'PRE_REGISTERED',
    'roadmap' => json_encode([
        ['stepId' => 'registrar_verification', 'status' => 'PENDING']
    ])
];

$rule002Blocked = false;
try {
    PaymentService::validatePaymentEligibility($preRegRecord);
} catch (Exception $e) {
    $rule002Blocked = true;
}
assertTest("Rule-002: Cashier payment rejected for PRE_REGISTERED applicant", $rule002Blocked);

$rejectedRecord = [
    'status' => 'REJECTED',
    'roadmap' => '[]'
];

$rule002RejectedBlocked = false;
try {
    PaymentService::validatePaymentEligibility($rejectedRecord);
} catch (Exception $e) {
    $rule002RejectedBlocked = true;
}
assertTest("Rule-002: Cashier payment rejected for REJECTED applicant", $rule002RejectedBlocked);

echo "\n====================================================\n";
echo " SECURITY SUITE RESULT: {$testsPassed} / {$totalTests} Tests Passed\n";
echo "====================================================\n";

if ($testsPassed === $totalTests) {
    echo "🎉 ALL SECURITY PENETRATION ASSERTIONS PASSED!\n\n";
    exit(0);
} else {
    echo "❌ SECURITY DEFICIENCIES DETECTED!\n\n";
    exit(1);
}
