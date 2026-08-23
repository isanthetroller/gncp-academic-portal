<?php
require_once __DIR__ . '/../../shared/backend/config/database.php';
require_once __DIR__ . '/../../shared/backend/utils/response.php';
require_once __DIR__ . '/../../shared/backend/utils/session_guard.php';

// Enforce session authentication for OR generation
requireAuth(['CASHIER', 'ADMIN', 'SUPER_ADMIN']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, null, 'Method not allowed. Use POST.', 405);
}

$rawInput = file_get_contents('php://input');
$payload = json_decode($rawInput, true);

if (!$payload || !isset($payload['referenceNumber']) || !isset($payload['cashierName'])) {
    sendResponse(false, null, 'Invalid payload or missing referenceNumber / cashierName.', 400);
}

$refNo = $payload['referenceNumber'];
$cashierName = $payload['cashierName'];

try {
    $pdo = Database::getInstance();

    // Check if OR number is already generated
    $checkStmt = $pdo->prepare("SELECT `or_number`, `enrolled_at` FROM `pre_enrollments` WHERE `temp_student_id` = :ref");
    $checkStmt->execute(['ref' => $refNo]);
    $student = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if (!$student) {
        sendResponse(false, null, 'Student record not found.', 404);
    }

    $orNumber = $student['or_number'];
    $enrolledAt = $student['enrolled_at'];

    if (empty($orNumber)) {
        $year = date('Y');
        do {
            $randomDigits = str_pad(random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
            $orNumber = "OR-{$year}-{$randomDigits}";
            $orCheck = $pdo->prepare("SELECT COUNT(*) FROM `pre_enrollments` WHERE `or_number` = :or_num");
            $orCheck->execute(['or_num' => $orNumber]);
        } while ((int)$orCheck->fetchColumn() > 0);
        $enrolledAt = date('Y-m-d H:i:s');

        // Update pre_enrollments table
        $updateStmt = $pdo->prepare("
            UPDATE `pre_enrollments` 
            SET `or_number` = :or_num, 
                `enrolled_at` = :enrolled_at, 
                `cashier_name` = :cashier 
            WHERE `temp_student_id` = :ref
        ");
        $updateStmt->execute([
            'or_num' => $orNumber,
            'enrolled_at' => $enrolledAt,
            'cashier' => $cashierName,
            'ref' => $refNo
        ]);
    }

    sendResponse(true, [
        'orNumber' => $orNumber,
        'enrolledAt' => $enrolledAt
    ], 'Official Receipt successfully generated.');

} catch (Exception $e) {
    sendResponse(false, null, 'Database error: ' . $e->getMessage(), 500);
}
