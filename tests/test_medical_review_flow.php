<?php
/**
 * Automated Test Suite - Medical Check-up & Review Backend Flow
 * Tests registration health declarations, queue extraction, medical review submission,
 * roadmap transitions, ACID persistence, and Cashier step unlocking.
 */

require_once __DIR__ . '/../shared/backend/config/database.php';
require_once __DIR__ . '/../stations/backend/services/QueueService.php';
require_once __DIR__ . '/../stations/backend/services/EnrollmentService.php';
require_once __DIR__ . '/../stations/backend/services/PaymentService.php';
require_once __DIR__ . '/../shared/backend/services/RegistrarService.php';

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
echo "       GNCP ACADEMIC SYSTEM - MEDICAL REVIEW END-TO-END TEST\n";
echo "========================================================================\n\n";

$testRef = 'TEST-MED-' . time();
$testEmail = 'test.medical.' . time() . '@gncp.edu.ph';

try {
    // -------------------------------------------------------------
    // Scenario 1: Create Pre-Registration Record with Health Profile
    // -------------------------------------------------------------
    echo "[Scenario 1: Pre-Registration with Health Declarations]\n";

    $initialRoadmap = [
        ['id' => 1, 'stepId' => 'online_prereg', 'name' => 'Online Pre-Reg', 'status' => 'COMPLETED'],
        ['id' => 2, 'stepId' => 'registrar_verification', 'name' => 'Registrar Verification', 'status' => 'COMPLETED'],
        ['id' => 3, 'stepId' => 'advising_assessment', 'name' => 'Academic Advising', 'status' => 'COMPLETED'],
        ['id' => 4, 'stepId' => 'clinic_checkup', 'name' => 'Medical Clearance', 'status' => 'IN_PROGRESS'],
        ['id' => 5, 'stepId' => 'scholarship_validation', 'name' => 'Scholarship', 'status' => 'LOCKED'],
        ['id' => 6, 'stepId' => 'cashier_payment', 'name' => 'Cashier Payment', 'status' => 'LOCKED'],
        ['id' => 7, 'stepId' => 'it_activation', 'name' => 'IT Center ID', 'status' => 'LOCKED']
    ];

    $insStmt = $pdo->prepare("
        INSERT INTO `pre_enrollments` (
            `temp_student_id`, `temp_pin`, `student_type`, `course_code`, `nstp`,
            `first_name`, `last_name`, `email`, `phone`, `birth_date`, `gender`,
            `address`, `elementary_school`, `junior_high_school`, `senior_high_school`,
            `payment_mode`, `scholarship`,
            `health_status`, `medical_conditions`, `allergies`,
            `current_medication`, `medication_details`, `fitness_participation`,
            `emergency_contact_name`, `emergency_contact_phone`, `status`,
            `roadmap`, `medical_data`, `created_at`
        ) VALUES (
            :ref, '1234', 'New Regular', 'BSIT', 'ROTC',
            'Alex', 'Reyes', :email, '09171234567', '2005-05-15', 'Male',
            '123 Test Street, Manila', 'GNCP Elem', 'GNCP JHS', 'GNCP SHS',
            'CASH', 'NONE',
            'GOOD', 'Asthma', 'Peanuts',
            1, 'Inhaler as needed', 1,
            'Maria Reyes', '09179998888', 'ADVISED',
            :roadmap, '{}', NOW()
        )
    ");

    $insStmt->execute([
        'ref'     => $testRef,
        'email'   => $testEmail,
        'roadmap' => json_encode($initialRoadmap)
    ]);

    assertCondition("Pre-enrollment test record created in MariaDB", true);

    // -------------------------------------------------------------
    // Scenario 2: QueueService Data Mapping for Medical Workstation
    // -------------------------------------------------------------
    echo "\n[Scenario 2: Queue Compilation & Health Declarations Mapping]\n";

    $queue = QueueService::fetchQueue($pdo);
    $foundStudent = null;
    foreach ($queue as $qItem) {
        if ($qItem['referenceNumber'] === $testRef) {
            $foundStudent = $qItem;
            break;
        }
    }

    assertCondition("Student present in station queue", $foundStudent !== null);
    assertCondition("Health status mapped as GOOD", ($foundStudent['form']['healthStatus'] ?? '') === 'GOOD');
    assertCondition("Allergies mapped as Peanuts", ($foundStudent['form']['allergies'] ?? '') === 'Peanuts');
    assertCondition("Medication details mapped correctly", ($foundStudent['form']['medicationDetails'] ?? '') === 'Inhaler as needed');
    assertCondition("Roadmap clinic_checkup is IN_PROGRESS", ($foundStudent['roadmap'][3]['status'] ?? '') === 'IN_PROGRESS');

    // -------------------------------------------------------------
    // Scenario 3: Submit Medical Assessment (Doctor Review)
    // -------------------------------------------------------------
    echo "\n[Scenario 3: Doctor Medical Review Submission]\n";

    $updatedRoadmap = $initialRoadmap;
    $updatedRoadmap[3]['status'] = 'COMPLETED';
    $updatedRoadmap[3]['updatedAt'] = date('c');
    $updatedRoadmap[4]['status'] = 'IN_PROGRESS'; // Unlocks scholarship / cashier

    $medicalPayload = [
        'status'           => 'fit',
        'physicalExam'     => 'fit',
        'medicalInterview' => 'fit',
        'peFitness'        => 'fit',
        'nstpFitness'      => 'fit',
        'notes'            => 'Patient is healthy and fit for academic/PE activities.',
        'verifiedBy'       => 'Dr. Ethan (Medical Officer)',
        'dateVerified'     => date('m/d/Y')
    ];

    $updatePayload = [
        'referenceNumber' => $testRef,
        'updateData'      => [
            'medical' => $medicalPayload,
            'roadmap' => $updatedRoadmap,
            'status'  => 'MEDICAL_CLEARED'
        ]
    ];

    $updateResult = EnrollmentService::updateStudent($pdo, $updatePayload);
    assertCondition("EnrollmentService::updateStudent returned success", !empty($updateResult['referenceNumber']));

    // -------------------------------------------------------------
    // Scenario 4: Verify Database Persistence
    // -------------------------------------------------------------
    echo "\n[Scenario 4: MariaDB ACID State Assertions]\n";

    $fetchStmt = $pdo->prepare("SELECT `medical_data`, `roadmap`, `status` FROM `pre_enrollments` WHERE `temp_student_id` = :ref");
    $fetchStmt->execute(['ref' => $testRef]);
    $dbRecord = $fetchStmt->fetch(PDO::FETCH_ASSOC);

    $savedMedical = json_decode($dbRecord['medical_data'] ?? '{}', true);
    $savedRoadmap = json_decode($dbRecord['roadmap'] ?? '[]', true);

    assertCondition("Overall application status updated to 'MEDICAL_CLEARED' in MariaDB", ($dbRecord['status'] ?? '') === 'MEDICAL_CLEARED');
    assertCondition("Medical status saved as 'fit'", ($savedMedical['status'] ?? '') === 'fit');
    assertCondition("Physical exam saved as 'fit'", ($savedMedical['physicalExam'] ?? '') === 'fit');
    assertCondition("PE Fitness saved as 'fit'", ($savedMedical['peFitness'] ?? '') === 'fit');
    assertCondition("Verified by Doctor recorded", ($savedMedical['verifiedBy'] ?? '') === 'Dr. Ethan (Medical Officer)');
    assertCondition("Roadmap Step 4 (clinic_checkup) marked COMPLETED", ($savedRoadmap[3]['status'] ?? '') === 'COMPLETED');
    assertCondition("Roadmap Step 5 (next station) unlocked to IN_PROGRESS", ($savedRoadmap[4]['status'] ?? '') === 'IN_PROGRESS');

    // -------------------------------------------------------------
    // Scenario 5: Downstream Station Payment Eligibility Validation
    // -------------------------------------------------------------
    echo "\n[Scenario 5: Downstream Station Integration]\n";

    // Set step 5 (scholarship) to skipped/completed to test cashier eligibility
    $savedRoadmap[4]['status'] = 'SKIPPED';
    $savedRoadmap[5]['status'] = 'IN_PROGRESS';
    $dbRecord['roadmap'] = json_encode($savedRoadmap);

    $isEligible = PaymentService::validatePaymentEligibility($dbRecord);
    assertCondition("Cashier payment eligibility succeeds when clinic is CLEARED", $isEligible === true);

    // Negative check: If clinic checkup were PENDING, payment MUST be blocked
    $unfitRoadmap = $savedRoadmap;
    $unfitRoadmap[3]['status'] = 'PENDING';
    $unfitRecord = $dbRecord;
    $unfitRecord['status'] = 'ADVISED';
    $unfitRecord['roadmap'] = json_encode($unfitRoadmap);

    $blocked = false;
    try {
        PaymentService::validatePaymentEligibility($unfitRecord);
    } catch (Exception $e) {
        $blocked = true;
    }
    assertCondition("Cashier payment is blocked if clinic checkup is PENDING", $blocked === true);

} catch (Exception $e) {
    echo "\n\033[31mUnexpected Exception:\033[0m " . $e->getMessage() . "\n";
    $failedTests++;
} finally {
    // Cleanup test record
    $cleanStmt = $pdo->prepare("DELETE FROM `pre_enrollments` WHERE `temp_student_id` = :ref");
    $cleanStmt->execute(['ref' => $testRef]);
}

echo "\n========================================================================\n";
echo "  TEST SUMMARY: {$passedTests} PASSED, {$failedTests} FAILED (TOTAL: {$totalTests})\n";
echo "========================================================================\n";

if ($failedTests > 0) {
    exit(1);
}
