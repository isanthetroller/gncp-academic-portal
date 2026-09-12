<?php
/**
 * Test Suite: Student Portal Requirements & Workflow Scenarios
 * Verifies Scenarios A, B, C, D, and E for Student Documents & Undertakings.
 */

require_once __DIR__ . '/../shared/backend/config/database.php';
require_once __DIR__ . '/../api/models/StudentModel.php';
require_once __DIR__ . '/../api/controllers/StudentController.php';

$pdo = Database::getInstance();
$studentModel = new StudentModel($pdo);
$studentController = new StudentController($pdo);

$testRef = 'TEST-REQ-2026-001';
$testEmail = 'test.requirements.workflow@gncp.edu.ph';

echo "========================================================\n";
echo "🧪 Running Student Portal Requirements Workflow Test Suite\n";
echo "========================================================\n\n";

$passedCount = 0;
$totalCount = 0;

function assertTrue($condition, $message) {
    global $passedCount, $totalCount;
    $totalCount++;
    if ($condition) {
        $passedCount++;
        echo "  [PASS] {$message}\n";
    } else {
        echo "  [FAIL] {$message}\n";
    }
}

// Cleanup any stale test data
$pdo->prepare("DELETE FROM `pre_enrollments` WHERE `temp_student_id` = :id OR `email` = :email")->execute(['id' => $testRef, 'email' => $testEmail]);
$pdo->prepare("DELETE FROM `students` WHERE `id` = :id OR `email` = :email OR `temp_reference_no` = :ref")->execute(['id' => $testRef, 'email' => $testEmail, 'ref' => $testRef]);

// Helper to seed a test pre_enrollment record
function seedApplicant(PDO $pdo, string $id, string $email, string $status, array $reqData) {
    $stmt = $pdo->prepare("INSERT INTO `pre_enrollments` (
        `temp_student_id`, `first_name`, `last_name`, `email`, `course_code`, `student_type`, `status`, `requirements_data`, `created_at`
    ) VALUES (
        :id, 'Test', 'Applicant', :email, 'BSIT', 'FRESHMAN', :status, :reqs, NOW()
    )");
    $stmt->execute([
        'id' => $id,
        'email' => $email,
        'status' => $status,
        'reqs' => json_encode($reqData)
    ]);
}

// -------------------------------------------------------------
// SCENARIO A: Uploaded Document
// Student uploads a required document. Before approval -> UNDER_REVIEW.
// After Registrar processes it -> VERIFIED, no longer pending.
// -------------------------------------------------------------
echo "Scenario A: Uploaded document\n";
seedApplicant($pdo, $testRef, $testEmail, 'PRE_REGISTERED', [
    'docs' => [
        'reportCard' => [
            'status' => 'SUBMITTED',
            'softCopyUrl' => 'uploads/docs/test_card.pdf',
            'fileName' => 'test_card.pdf',
            'submittedAt' => '2026-09-10 10:00:00'
        ]
    ]
]);

$reqA1 = $studentModel->getStudentRequirements($testRef);
$reportCardBefore = null;
foreach ($reqA1['requirements'] as $r) {
    if ($r['key'] === 'reportCard' || $r['key'] === 'form_138') {
        $reportCardBefore = $r;
        break;
    }
}
assertTrue($reportCardBefore !== null, "Report card found in applicant requirements");
assertTrue($reportCardBefore['status'] === 'UNDER_REVIEW', "Before Registrar processing, uploaded doc status is UNDER_REVIEW");
assertTrue($reportCardBefore['isActionable'] === false, "Student does not need to re-upload while doc is under review");

// Registrar approves the application
$pdo->prepare("UPDATE `pre_enrollments` SET `status` = 'VERIFIED' WHERE `temp_student_id` = :id")->execute(['id' => $testRef]);
$reqA2 = $studentModel->getStudentRequirements($testRef);
$reportCardAfter = null;
foreach ($reqA2['requirements'] as $r) {
    if ($r['key'] === 'reportCard' || $r['key'] === 'form_138') {
        $reportCardAfter = $r;
        break;
    }
}
assertTrue($reportCardAfter['status'] === 'VERIFIED', "After Registrar processing, uploaded doc is VERIFIED");
assertTrue($reportCardAfter['isActionable'] === false, "Registrar processed uploaded doc is NOT actionable");
$inPendingA = false;
foreach ($reqA2['pendingRequirements'] as $pr) {
    if ($pr['key'] === 'reportCard' || $pr['key'] === 'form_138') $inPendingA = true;
}
assertTrue(!$inPendingA, "Processed uploaded doc no longer appears in pendingRequirements");

// Cleanup for Scenario B
$pdo->prepare("DELETE FROM `pre_enrollments` WHERE `temp_student_id` = :id")->execute(['id' => $testRef]);

// -------------------------------------------------------------
// SCENARIO B: Hardcopy
// Student did not upload document -> classified as Hardcopy.
// Registrar receives physical document and processes application.
// Student Portal must NOT ask student to upload it, considers complete.
// -------------------------------------------------------------
echo "\nScenario B: Hardcopy (in-person physical submission)\n";
seedApplicant($pdo, $testRef, $testEmail, 'PRE_REGISTERED', [
    'docs' => [] // No uploads
]);

$reqB1 = $studentModel->getStudentRequirements($testRef);
$psaBefore = null;
foreach ($reqB1['requirements'] as $r) {
    if ($r['key'] === 'psa') {
        $psaBefore = $r;
        break;
    }
}
assertTrue($psaBefore['submissionMode'] === 'HARDCOPY', "Unuploaded document is classified as Hardcopy");

// Registrar receives physical hard copy and approves application
$pdo->prepare("UPDATE `pre_enrollments` SET `status` = 'VERIFIED' WHERE `temp_student_id` = :id")->execute(['id' => $testRef]);
$reqB2 = $studentModel->getStudentRequirements($testRef);
$psaAfter = null;
foreach ($reqB2['requirements'] as $r) {
    if ($r['key'] === 'psa') {
        $psaAfter = $r;
        break;
    }
}
assertTrue($psaAfter['status'] === 'VERIFIED', "Once Registrar processes, Hardcopy is marked VERIFIED");
assertTrue($psaAfter['isActionable'] === false, "Student is NOT asked to upload hardcopy document");
$inPendingB = false;
foreach ($reqB2['pendingRequirements'] as $pr) {
    if ($pr['key'] === 'psa') $inPendingB = true;
}
assertTrue(!$inPendingB, "Processed Hardcopy does not appear in pendingRequirements");

// Cleanup for Scenario C
$pdo->prepare("DELETE FROM `pre_enrollments` WHERE `temp_student_id` = :id")->execute(['id' => $testRef]);

// -------------------------------------------------------------
// SCENARIO C: Undertaking
// Requirement specifically marked as Undertaking.
// Appears actionable until fulfilled.
// -------------------------------------------------------------
echo "\nScenario C: Undertaking\n";
seedApplicant($pdo, $testRef, $testEmail, 'VERIFIED', [
    'docs' => [
        'goodMoral' => [
            'status' => 'UNDERTAKING',
            'isUndertaking' => true,
            'remarks' => 'School release delay',
            'deadline' => '2026-10-15'
        ]
    ]
]);

$reqC1 = $studentModel->getStudentRequirements($testRef);
$gmDoc = null;
foreach ($reqC1['requirements'] as $r) {
    if ($r['key'] === 'goodMoral') {
        $gmDoc = $r;
        break;
    }
}
assertTrue($gmDoc['status'] === 'UNDERTAKING', "Doc is identified as UNDERTAKING");
assertTrue($gmDoc['isActionable'] === true, "Undertaking requirement remains actionable for student");
assertTrue($gmDoc['undertakingReason'] === 'School release delay', "Undertaking waiver reason preserved");
assertTrue($gmDoc['undertakingDeadline'] === '2026-10-15', "Undertaking deadline preserved");

$inPendingC = false;
foreach ($reqC1['pendingRequirements'] as $pr) {
    if ($pr['key'] === 'goodMoral') $inPendingC = true;
}
assertTrue($inPendingC, "Undertaking appears in pendingRequirements");
assertTrue($reqC1['stats']['actionableCount'] === 1, "Stats reflect exactly 1 actionable undertaking");

// Cleanup for Scenario D
$pdo->prepare("DELETE FROM `pre_enrollments` WHERE `temp_student_id` = :id")->execute(['id' => $testRef]);

// -------------------------------------------------------------
// SCENARIO D: No remaining requirements
// All requirements complete / approved by Registrar.
// Shows complete requirements state (actionableCount = 0, isComplete = true).
// -------------------------------------------------------------
echo "\nScenario D: No remaining requirements (Complete)\n";
seedApplicant($pdo, $testRef, $testEmail, 'VERIFIED', [
    'docs' => [
        'reportCard' => ['status' => 'ORIGINAL'],
        'psa'        => ['status' => 'PHOTOCOPY'],
        'goodMoral'  => ['status' => 'ORIGINAL'],
        '2x2_picture'=> ['status' => 'ORIGINAL']
    ]
]);

$reqD = $studentModel->getStudentRequirements($testRef);
assertTrue($reqD['stats']['isComplete'] === true, "isComplete is true when all requirements handled");
assertTrue($reqD['stats']['isFullyCompliant'] === true, "isFullyCompliant is true");
assertTrue($reqD['stats']['actionableCount'] === 0, "Actionable count is 0");
assertTrue(count($reqD['pendingRequirements']) === 0, "pendingRequirements array is completely empty");
assertTrue(count($reqD['completedRequirements']) === count($reqD['requirements']), "All catalog requirements reside in completedRequirements");

// Cleanup for Scenario E
$pdo->prepare("DELETE FROM `pre_enrollments` WHERE `temp_student_id` = :id")->execute(['id' => $testRef]);

// -------------------------------------------------------------
// SCENARIO E: Mixed status
// Some completed, some hardcopy processed, some undertaking/pending.
// Shows ONLY genuinely pending requirements.
// -------------------------------------------------------------
echo "\nScenario E: Mixed status\n";
seedApplicant($pdo, $testRef, $testEmail, 'VERIFIED', [
    'docs' => [
        'reportCard' => [
            'status' => 'ORIGINAL' // Hardcopy verified by registrar
        ],
        'psa' => [
            'status' => 'VERIFIED', // Soft copy uploaded & verified
            'softCopyUrl' => 'uploads/docs/psa_cert.pdf',
            'fileName' => 'psa_cert.pdf'
        ],
        'goodMoral' => [
            'status' => 'UNDERTAKING', // Undertaking
            'isUndertaking' => true,
            'remarks' => 'Principal signature pending',
            'deadline' => '2026-11-01'
        ]
        // 2x2_picture not in docs, but applicant is VERIFIED -> auto hardcopy processed!
    ]
]);

$reqE = $studentModel->getStudentRequirements($testRef);
assertTrue(count($reqE['pendingRequirements']) === 1, "Only genuinely pending requirement (goodMoral) is actionable");
assertTrue($reqE['pendingRequirements'][0]['key'] === 'goodMoral', "Pending requirement is goodMoral undertaking");
assertTrue($reqE['stats']['actionableCount'] === 1, "Actionable count is exactly 1");

$completedKeys = array_map(fn($d) => $d['key'], $reqE['completedRequirements']);
assertTrue(in_array('reportCard', $completedKeys), "reportCard is in completedRequirements");
assertTrue(in_array('psa', $completedKeys), "psa is in completedRequirements");
assertTrue(in_array('2x2_picture', $completedKeys), "2x2_picture (unuploaded hardcopy on verified student) is in completedRequirements");

// Final cleanup
$pdo->prepare("DELETE FROM `pre_enrollments` WHERE `temp_student_id` = :id")->execute(['id' => $testRef]);

// -------------------------------------------------------------
// BONUS SCENARIO: Official Enrolled Student Record in `students` table
// Student has been promoted to official student directory (`status = 'ACTIVE'`).
// All original physical documents verified at admission.
// Student Portal must show complete requirements state.
// -------------------------------------------------------------
echo "\nOfficial Enrolled Student in `students` table\n";
$officialId = 'GNCP-2026-9999';
$pdo->prepare("DELETE FROM `students` WHERE `id` = :id OR `email` = :email")->execute(['id' => $officialId, 'email' => $testEmail]);

$pdo->prepare("INSERT INTO `students` (
    `id`, `temp_reference_no`, `name`, `email`, `program`, `year_level`, `status`, `requirements_data`, `created_at`
) VALUES (
    :id, :ref, 'Official Test Student', :email, 'BSIT', '1st Year', 'ACTIVE', '{}', NOW()
)")->execute([
    'id' => $officialId,
    'ref' => $testRef,
    'email' => $testEmail
]);

$reqOfficial = $studentModel->getStudentRequirements($officialId);
assertTrue($reqOfficial['isOfficial'] === true, "Identified as official student record");
assertTrue($reqOfficial['isRegistrarProcessed'] === true, "Official student is treated as Registrar-processed");
assertTrue($reqOfficial['stats']['isComplete'] === true, "Official student shows complete requirements");
assertTrue($reqOfficial['stats']['actionableCount'] === 0, "Official student has 0 actionable documents");
assertTrue(count($reqOfficial['pendingRequirements']) === 0, "Official student has empty pendingRequirements");

// Cleanup official student
$pdo->prepare("DELETE FROM `students` WHERE `id` = :id")->execute(['id' => $officialId]);

echo "\n========================================================\n";
echo "📊 Test Results: {$passedCount} / {$totalCount} assertions passed.\n";
if ($passedCount === $totalCount) {
    echo "🎉 ALL SCENARIOS PASSED PERFECTLY!\n";
} else {
    echo "❌ SOME SCENARIOS FAILED!\n";
}
echo "========================================================\n";
