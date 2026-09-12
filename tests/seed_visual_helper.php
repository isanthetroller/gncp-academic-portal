<?php
require_once __DIR__ . '/../shared/backend/config/database.php';
$pdo = Database::getInstance();

$action = $argv[1] ?? 'complete';
$testId = 'REF-2026-VISUAL';
$testEmail = 'visual.test.student@gncp.edu.ph';

if ($action === 'cleanup') {
    $pdo->prepare("DELETE FROM `pre_enrollments` WHERE `temp_student_id` = :id")->execute(['id' => $testId]);
    echo "Cleaned up test student.\n";
    exit(0);
}

if ($action === 'complete') {
    $pdo->prepare("DELETE FROM `pre_enrollments` WHERE `temp_student_id` = :id")->execute(['id' => $testId]);
    $docs = [
        'docs' => [
            'reportCard'  => ['status' => 'ORIGINAL'],
            'psa'         => ['status' => 'PHOTOCOPY'],
            'goodMoral'   => ['status' => 'ORIGINAL'],
            '2x2_picture' => ['status' => 'ORIGINAL']
        ]
    ];
    $stmt = $pdo->prepare("INSERT INTO `pre_enrollments` (
        `temp_student_id`, `temp_pin`, `student_type`, `course_code`, `nstp`,
        `first_name`, `last_name`, `email`, `phone`, `birth_date`, `gender`,
        `address`, `elementary_school`, `junior_high_school`, `senior_high_school`,
        `payment_mode`, `scholarship`, `status`, `requirements_data`, `created_at`
    ) VALUES (
        :id, '1234', 'FRESHMAN', 'BSIT', 'ROTC',
        'Lucas', 'Vanguardia', :email, '09171234567', '2005-01-01', 'Male',
        'Manila', 'Elem', 'JHS', 'SHS', 'CASH', 'NONE', 'VERIFIED',
        :reqs, NOW()
    )");
    $stmt->execute(['id' => $testId, 'email' => $testEmail, 'reqs' => json_encode($docs)]);
    echo "Seeded Complete Requirements student.\n";
} elseif ($action === 'undertaking') {
    $docs = [
        'docs' => [
            'reportCard'  => ['status' => 'ORIGINAL'],
            'psa'         => ['status' => 'VERIFIED', 'fileName' => 'psa_cert.pdf', 'softCopyUrl' => 'uploads/docs/psa_cert.pdf'],
            'goodMoral'   => ['status' => 'UNDERTAKING', 'isUndertaking' => true, 'remarks' => 'Delayed release from SHS', 'deadline' => '2026-10-31']
        ]
    ];
    $stmt = $pdo->prepare("UPDATE `pre_enrollments` SET `requirements_data` = :reqs WHERE `temp_student_id` = :id");
    $stmt->execute(['id' => $testId, 'reqs' => json_encode($docs)]);
    echo "Seeded Undertaking student.\n";
}
