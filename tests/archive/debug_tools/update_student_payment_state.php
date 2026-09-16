<?php
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('CLI execution only.');
}
require_once __DIR__ . '/../shared/backend/config/database.php';
$pdo = Database::getInstance();

$paymentData = json_encode([
    'totalFee' => 18300,
    'amountPaid' => 0,
    'balance' => 18300,
    'paymentType' => 'Cash',
    'status' => 'UNPAID',
    'history' => []
]);

$roadmap = json_encode([
    ['id' => 1, 'stepId' => 'online_registration', 'name' => 'Online Registration', 'status' => 'COMPLETED'],
    ['id' => 2, 'stepId' => 'registrar_verification', 'name' => 'Registrar Verification', 'status' => 'COMPLETED'],
    ['id' => 3, 'stepId' => 'advising_assessment', 'name' => 'Program Advising', 'status' => 'COMPLETED'],
    ['id' => 4, 'stepId' => 'clinic_checkup', 'name' => 'Medical Clearance', 'status' => 'COMPLETED'],
    ['id' => 5, 'stepId' => 'cashier_payment', 'name' => 'Cashier Payment', 'status' => 'IN_PROGRESS'],
    ['id' => 6, 'stepId' => 'id_email_final', 'name' => 'IT Account', 'status' => 'PENDING']
]);

$pdo->query("UPDATE `students` SET `payment_data` = '$paymentData', `roadmap` = '$roadmap', `status` = 'MEDICAL_CLEARED'");
$pdo->query("UPDATE `pre_enrollments` SET `payment_data` = '$paymentData', `roadmap` = '$roadmap', `status` = 'MEDICAL_CLEARED'");

echo "DB records updated for Cashier testing!\n";
