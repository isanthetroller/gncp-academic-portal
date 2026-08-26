<?php
/**
 * GNCP Comprehensive Security Hardening Test Suite
 * Validates fixes for Authentication, RBAC, File Uploads, Session Security, and Data Privacy.
 */

if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

require_once __DIR__ . '/../../shared/backend/config/database.php';
require_once __DIR__ . '/../../api/controllers/AuthController.php';
require_once __DIR__ . '/../../api/controllers/StudentController.php';
require_once __DIR__ . '/../../shared/backend/services/StudentPortalService.php';

$pdo = Database::getInstance();

$passed = 0;
$failed = 0;

function assertSecurity($condition, $testName, $detail = '') {
    global $passed, $failed;
    if ($condition) {
        echo "  [PASS] {$testName}\n";
        $passed++;
    } else {
        echo "  [FAIL] {$testName} - {$detail}\n";
        $failed++;
    }
}

echo "====================================================\n";
echo " GNCP SECURITY AUDIT & HARDENING VERIFICATION SUITE\n";
echo "====================================================\n\n";

// ── TEST 1: Authentication & Password Security ──
echo "1. Authentication & Password Security\n";
$authCtrl = new AuthController($pdo);

// Invalid credentials should return 401 with generic message
$invalidLogin = $authCtrl->login(['username' => 'nonexistent_user', 'password' => 'wrong_pass']);
assertSecurity(
    $invalidLogin['success'] === false && ($invalidLogin['code'] ?? 0) === 401,
    "Invalid login returns 401 Unauthorized"
);
assertSecurity(
    $invalidLogin['message'] === 'Invalid username or password.',
    "Login error does not reveal account existence"
);

// ── TEST 2: Authorization & Session Initialization ──
echo "\n2. Authorization & RBAC Session Guarding\n";
$_SESSION['gncp_admin_user'] = [
    'username' => 'admin',
    'role' => 'SUPER_ADMIN',
    'name' => 'System Administrator'
];

$studentCtrl = new StudentController($pdo);
$cleanupAttempt = $studentCtrl->cleanupTestRecords(['email_pattern' => 'nonexistent_pattern_%']);
assertSecurity(
    $cleanupAttempt['success'] === true,
    "Admin session successfully authorizes cleanup action"
);

// ── TEST 3: Profile Picture Upload Security (JPEG Only & GD Re-encoding) ──
echo "\n3. Profile Picture Upload Security (JPEG Only & GD Re-encoding)\n";

// 3.1: Valid minimal JPEG Binary Payload (1x1 pure red JPEG)
$validJpgBytes = base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=');

$validJpgPayload = [
    'username' => 'admin',
    'photoData' => 'data:image/jpeg;base64,' . base64_encode($validJpgBytes)
];
$jpgRes = $authCtrl->uploadAvatar($validJpgPayload);
assertSecurity(
    $jpgRes['success'] === true && !empty($jpgRes['data']['avatar']),
    "Legitimate JPEG image successfully verified and saved"
);

// 3.2: Reject PNG file renamed or supplied as avatar (1x1 PNG)
$pngBytes = base64_decode('iVBORw0KGgoAAAANSUhEAAAAAAAAlAAAAAgAAAABAAAAAQCAYAAAAA1k8AAAAAAA==');
$pngPayload = [
    'username' => 'admin',
    'photoData' => 'data:image/png;base64,' . base64_encode($pngBytes)
];
$pngRes = $authCtrl->uploadAvatar($pngPayload);
assertSecurity(
    $pngRes['success'] === false && str_contains($pngRes['message'], 'ONLY legitimate JPG/JPEG'),
    "PNG image rejected by JPEG-only validation rule"
);

// 3.3: Reject SVG file
$svgData = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>';
$svgPayload = [
    'username' => 'admin',
    'photoData' => 'data:image/svg+xml;base64,' . base64_encode($svgData)
];
$svgRes = $authCtrl->uploadAvatar($svgPayload);
assertSecurity(
    $svgRes['success'] === false,
    "SVG file rejected by JPEG-only validation rule"
);

// 3.4: Reject PHP payload masquerading as JPEG
$fakeJpg = "<?php echo 'malicious php payload'; ?>";
$fakePayload = [
    'username' => 'admin',
    'photoData' => 'data:image/jpeg;base64,' . base64_encode($fakeJpg)
];
$fakeRes = $authCtrl->uploadAvatar($fakePayload);
assertSecurity(
    $fakeRes['success'] === false,
    "PHP script masquerading as JPEG rejected by image signature check"
);

// ── TEST 4: Softcopy Requirements Upload Security (PDF Only) ──
echo "\n4. Softcopy Requirements Upload Security (PDF Only)\n";

// Ensure seed student exists in students table
$checkStd = $pdo->prepare("SELECT id FROM `students` WHERE `id` = 'GNCP-2026-9999' LIMIT 1");
$checkStd->execute();
if (!$checkStd->fetch()) {
    $pdo->prepare("
        INSERT INTO `students` (`id`, `name`, `email`, `program`, `password`, `status`, `personal_info`)
        VALUES ('GNCP-2026-9999', 'Security Audit Student', 'audit.student@gncp.edu.ph', 'BSIT', 'hashed_pwd', 'ACTIVE', '{\"requirements\":{}}')
    ")->execute();
}

// 4.1: Legitimate PDF with %PDF- header
$validPdf = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF";
$pdfPayload = [
    'studentId' => 'GNCP-2026-9999',
    'docKey'    => 'report_card',
    'fileName'  => 'report_card.pdf',
    'fileData'  => 'data:application/pdf;base64,' . base64_encode($validPdf)
];
$pdfRes = $studentCtrl->uploadDocument($pdfPayload);
assertSecurity(
    $pdfRes['success'] === true,
    "Legitimate PDF with %PDF- header accepted and verified"
);

// 4.2: Reject JPG uploaded as softcopy requirement
$jpgDocPayload = [
    'studentId' => 'GNCP-2026-9999',
    'docKey'    => 'report_card',
    'fileName'  => 'report_card.jpg',
    'fileData'  => 'data:image/jpeg;base64,' . base64_encode($validJpgBytes)
];
$jpgDocRes = $studentCtrl->uploadDocument($jpgDocPayload);
assertSecurity(
    $jpgDocRes['success'] === false && str_contains($jpgDocRes['message'], 'Security Error'),
    "JPG image rejected for softcopy requirement (PDF-only rule enforced)"
);

// 4.3: Reject PHP payload renamed to .pdf
$fakePdf = "%PDF-1.4 <?php system(\$_GET['cmd']); ?>";
$fakePdfPayload = [
    'studentId' => 'GNCP-2026-9999',
    'docKey'    => 'good_moral',
    'fileName'  => 'exploit.pdf',
    'fileData'  => 'data:application/pdf;base64,' . base64_encode($fakePdf)
];
$fakePdfRes = $studentCtrl->uploadDocument($fakePdfPayload);
assertSecurity(
    $fakePdfRes['success'] === false && str_contains($fakePdfRes['message'], 'Malicious executable'),
    "Polyglot PDF with embedded PHP script tags rejected"
);

// ── TEST 5: Password Reset Data Privacy ──
echo "\n5. Password Reset Data Privacy & Email Masking\n";

// Seed a test student in students directory if not present
$checkStd = $pdo->prepare("SELECT id FROM `students` WHERE `id` = 'GNCP-2026-9999' LIMIT 1");
$checkStd->execute();
if (!$checkStd->fetch()) {
    $pdo->prepare("
        INSERT INTO `students` (`id`, `name`, `email`, `program`, `password`, `status`)
        VALUES ('GNCP-2026-9999', 'Security Audit Student', 'audit.student@gncp.edu.ph', 'BSIT', 'hashed_pwd', 'ACTIVE')
    ")->execute();
}

$resetReq = StudentPortalService::requestPasswordReset($pdo, 'GNCP-2026-9999');
if ($resetReq['success']) {
    assertSecurity(
        !isset($resetReq['data']['targetEmail']),
        "Password reset response does not leak raw targetEmail"
    );
    assertSecurity(
        isset($resetReq['data']['maskedEmail']) && str_contains($resetReq['data']['maskedEmail'], '*'),
        "Password reset response returns properly masked email address"
    );
}

// ── TEST 6: Uploads .htaccess Protection ──
echo "\n6. Filesystem Security & .htaccess Engine Protection\n";
$htaccessPath = __DIR__ . '/../../uploads/.htaccess';
assertSecurity(
    file_exists($htaccessPath),
    "uploads/.htaccess file exists"
);
$htaccessContent = file_get_contents($htaccessPath);
assertSecurity(
    str_contains($htaccessContent, 'php_flag engine off') && str_contains($htaccessContent, 'Deny from all'),
    "uploads/.htaccess disables PHP engine and denies script execution"
);

// ── SUMMARY ──
echo "\n====================================================\n";
echo " SECURITY VERIFICATION RESULTS\n";
echo "====================================================\n";
echo " Passed: {$passed}\n";
echo " Failed: {$failed}\n";

if ($failed === 0) {
    echo "\n 🎉 ALL SECURITY HARDENING TESTS PASSED WITH ZERO FAILURES!\n\n";
    exit(0);
} else {
    echo "\n ❌ SOME SECURITY TESTS FAILED. PLEASE REVIEW AUDIT LOGS.\n\n";
    exit(1);
}
