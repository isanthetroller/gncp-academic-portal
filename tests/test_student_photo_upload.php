<?php
/**
 * Automated Verification: Student Profile Picture Upload & Synchronization
 */

require_once __DIR__ . '/../shared/backend/config/database.php';
require_once __DIR__ . '/../shared/backend/utils/session_guard.php';
initSession();

require_once __DIR__ . '/../api/controllers/StudentPortalController.php';

$pdo = Database::getInstance();

// 1. Locate or create a test student
$stmt = $pdo->query("SELECT id, name, email, photo FROM `students` LIMIT 1");
$student = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$student) {
    $testId = 'TEST-STD-001';
    $ins = $pdo->prepare("INSERT INTO `students` (id, name, program, email, password, year_level, status) VALUES (:id, 'Test Student', 'BSIT', 'test.student@gncp.edu.ph', 'secret', '1st Year', 'Active')");
    $ins->execute(['id' => $testId]);
    $student = ['id' => $testId, 'name' => 'Test Student', 'email' => 'test.student@gncp.edu.ph', 'photo' => null];
}

$studentId = $student['id'];

// Initialize controller
$controller = new StudentPortalController($pdo);

// Mock student session
$_SESSION['gncp_student'] = [
    'id' => $studentId,
    'name' => $student['name'],
    'email' => $student['email'],
    'role' => 'STUDENT',
    'photo' => $student['photo']
];
session_write_close();

echo "=== GNCP Student Photo Upload Verification ===\n";
echo "Testing with Student ID: {$studentId} ({$student['name']})\n";

// 2. Generate a valid 1x1 transparent PNG in base64
$samplePngBase64 = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

// 3. Call updateProfile
echo "Uploading test PNG portrait...\n";
$res = $controller->updateProfile([
    'studentId' => $studentId,
    'photoData' => $samplePngBase64
]);

echo "API Response: " . json_encode($res) . "\n";
assert($res['success'] === true, "Expected success to be true");
assert(!empty($res['data']['photo']), "Expected photo filename in response");

$newPhotoFile = $res['data']['photo'];
echo "Generated photo filename: {$newPhotoFile}\n";

// 4. Assert physical files exist
$baseDir = dirname(__DIR__);
$path1 = $baseDir . '/uploads/avatars/' . $newPhotoFile;
$path2 = $baseDir . '/stations/it-center/assets/uploads/' . $newPhotoFile;
$path3 = $baseDir . '/shared/assets/uploads/' . $newPhotoFile;

echo "Checking physical file paths:\n";
echo "1. uploads/avatars: " . (file_exists($path1) ? "OK (" . filesize($path1) . " bytes)" : "FAILED") . "\n";
echo "2. stations/it-center/assets/uploads: " . (file_exists($path2) ? "OK (" . filesize($path2) . " bytes)" : "FAILED") . "\n";
echo "3. shared/assets/uploads: " . (file_exists($path3) ? "OK (" . filesize($path3) . " bytes)" : "FAILED") . "\n";

if (!file_exists($path1) || !file_exists($path2) || !file_exists($path3)) {
    echo "ERROR: One or more upload targets failed to save the photo.\n";
    exit(1);
}

// 5. Assert database update
$chk = $pdo->prepare("SELECT photo FROM `students` WHERE id = :id");
$chk->execute(['id' => $studentId]);
$dbPhoto = $chk->fetchColumn();
echo "Database students.photo: {$dbPhoto}\n";
assert($dbPhoto === $newPhotoFile, "Expected DB students.photo to match generated filename");

// 6. Assert session update
initSession();
$sessPhoto = $_SESSION['gncp_student']['photo'] ?? null;
session_write_close();
echo "Session photo: {$sessPhoto}\n";
assert($sessPhoto === $newPhotoFile, "Expected session photo to match generated filename");

// 7. Test invalid image rejection
echo "Testing invalid non-image payload rejection...\n";
$badRes = $controller->updateProfile([
    'studentId' => $studentId,
    'photoData' => 'data:image/png;base64,bm90YW5pbWFnZWF0YWxsISEh' // "notanimageatall!!!"
]);
echo "Invalid payload response: " . json_encode($badRes) . "\n";
assert($badRes['success'] === false, "Expected invalid image to be rejected");
echo "Rejection message: {$badRes['message']}\n";

// 8. Test JPEG format with static 1x1 JPEG base64
$sampleJpgBase64 = 'data:image/jpeg;base64,/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=';

echo "Uploading test JPEG portrait...\n";
$resJpg = $controller->updateProfile([
    'studentId' => $studentId,
    'photoData' => $sampleJpgBase64
]);

echo "JPEG API Response: " . json_encode($resJpg) . "\n";
assert($resJpg['success'] === true, "Expected JPEG upload success");
$jpgFile = $resJpg['data']['photo'];
assert(str_ends_with($jpgFile, '.jpg'), "Expected filename to end with .jpg");
assert(file_exists($baseDir . '/uploads/avatars/' . $jpgFile), "Expected JPEG in uploads/avatars");
echo "JPEG saved as: {$jpgFile} (" . filesize($baseDir . '/uploads/avatars/' . $jpgFile) . " bytes)\n";

// 9. Clean up test files to leave repository clean
@unlink($baseDir . '/uploads/avatars/' . $newPhotoFile);
@unlink($baseDir . '/stations/it-center/assets/uploads/' . $newPhotoFile);
@unlink($baseDir . '/shared/assets/uploads/' . $newPhotoFile);
@unlink($baseDir . '/uploads/avatars/' . $jpgFile);
@unlink($baseDir . '/stations/it-center/assets/uploads/' . $jpgFile);
@unlink($baseDir . '/shared/assets/uploads/' . $jpgFile);

// Restore original student photo
$restore = $pdo->prepare("UPDATE `students` SET photo = :photo WHERE id = :id");
$restore->execute(['photo' => $student['photo'], 'id' => $studentId]);

echo "\n ALL VERIFICATIONS PASSED SUCCESSFULLY!\n";
