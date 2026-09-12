<?php
/**
 * Automated Verification Script:
 * Tests the Employee Password Reset Flow with Temporary Password Email Dispatch via Gmail SMTP
 */

if (php_sapi_name() !== 'cli') {
    die('CLI only');
}

if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

echo "=== GNCP EMPLOYEE PASSWORD RESET VERIFICATION ===\n\n";

require_once __DIR__ . '/../shared/backend/config/database.php';
require_once __DIR__ . '/../shared/backend/services/EmailService.php';
require_once __DIR__ . '/../api/models/UserModel.php';
require_once __DIR__ . '/../api/controllers/UserAdminController.php';

$pdo = Database::getInstance();
$userModel = new UserModel($pdo);

// 1. Prepare or identify a test operator in station_users
$testUsername = 'test_reset_emp_01';
$testEmail = 'goontech1@gmail.com'; // Deliver to verified email for live check
$testName = 'Demo Registrar Officer';
$testRole = 'REGISTRAR';

echo "1. Checking/Preparing test operator '{$testUsername}' in station_users...\n";
$stmt = $pdo->prepare("SELECT id, username, email FROM station_users WHERE username = :u LIMIT 1");
$stmt->execute(['u' => $testUsername]);
$existing = $stmt->fetch();

if ($existing) {
    $testUserId = (int)$existing['id'];
    $stmt = $pdo->prepare("UPDATE station_users SET email = :e, name = :n, role = :r, status = 'ACTIVE' WHERE id = :id");
    $stmt->execute(['e' => $testEmail, 'n' => $testName, 'r' => $testRole, 'id' => $testUserId]);
    echo "   [OK] Existing test user updated (ID: {$testUserId}).\n";
} else {
    $testUserId = (int)$userModel->createUser([
        'username' => $testUsername,
        'password' => 'OldPass#1234!',
        'name'     => $testName,
        'email'    => $testEmail,
        'role'     => $testRole,
        'status'   => 'ACTIVE',
        'must_change_password' => 0
    ]);
    echo "   [OK] Created test user (ID: {$testUserId}).\n";
}

// 2. Mock Admin Session for requireAuth
$_SESSION['gncp_admin_user'] = [
    'id'       => 1,
    'username' => 'admin',
    'name'     => 'System Super Administrator',
    'role'     => 'SUPER_ADMIN'
];

echo "\n2. Executing UserAdminController::resetOperatorPassword...\n";
$userAdminCtrl = new UserAdminController($pdo);
$resetResult = $userAdminCtrl->resetOperatorPassword([
    'userId'      => $testUserId,
    'newPassword' => '', // Auto-generate
    'email'       => $testEmail
]);

echo "   Result: " . json_encode($resetResult, JSON_PRETTY_PRINT) . "\n";

if (empty($resetResult['success'])) {
    echo "\n[FAILED] resetOperatorPassword returned success = false.\n";
    exit(1);
}

$tempPassword = $resetResult['data']['tempPassword'] ?? '';
echo "   Generated Temporary Password: {$tempPassword}\n";

// 3. Database Assertions
echo "\n3. Validating database state in station_users...\n";
$stmt = $pdo->prepare("SELECT id, username, password, must_change_password, email FROM station_users WHERE id = :id");
$stmt->execute(['id' => $testUserId]);
$updatedUser = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$updatedUser) {
    echo "   [FAILED] User not found in database!\n";
    exit(1);
}

// Assert password verification
$passVerified = password_verify($tempPassword, $updatedUser['password']);
echo "   Password matches new hash: " . ($passVerified ? "[OK]" : "[FAIL]") . "\n";

// Assert must_change_password flag
$mustChange = (int)$updatedUser['must_change_password'];
echo "   must_change_password flag is 1: " . ($mustChange === 1 ? "[OK]" : "[FAIL]") . "\n";

// Assert email matches
$emailMatch = ($updatedUser['email'] === $testEmail);
echo "   Email stored correctly: " . ($emailMatch ? "[OK]" : "[FAIL]") . "\n";

// Assert email delivery
$emailSent = !empty($resetResult['data']['emailSent']);
echo "   Email dispatch via Gmail SMTP: " . ($emailSent ? "[OK SUCCESS]" : "[FAIL]") . "\n";

if ($passVerified && $mustChange === 1 && $emailMatch && $emailSent) {
    echo "\n=======================================================\n";
    echo " [ALL CHECKS PASSED] Employee password reset email\n";
    echo " successfully dispatched with temporary password!\n";
    echo "=======================================================\n";
    exit(0);
} else {
    echo "\n[FAILED] Some assertions failed.\n";
    exit(1);
}
