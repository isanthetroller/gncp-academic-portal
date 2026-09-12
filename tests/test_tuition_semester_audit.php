<?php
/**
 * Cashier Tuition Calculation & Enrollment Subject Logic Audit Test Suite
 *
 * Verifies:
 * 1. Single-semester scoping (1st Sem vs 2nd Sem subjects never bleed into each other).
 * 2. Deduplication across curriculum, advising, assessment, and queue layers.
 * 3. Exact mathematical fee totals (BSIT 1st Year 1st Sem = ₱18,300.00, NOT ~₱100,000).
 * 4. Combined lecture + lab component accounting (lec + lab units summed once, lab fee applied once).
 * 5. Database constraint integrity (unique keys on curriculum and fee_schedule).
 * 6. Cashier API data contracts (prospectusSubjects, activeSemester, academicYear, assessmentSnapshot).
 */

require_once __DIR__ . '/../shared/backend/config/database.php';
require_once __DIR__ . '/../shared/backend/services/AssessmentService.php';
require_once __DIR__ . '/../stations/backend/services/QueueService.php';
require_once __DIR__ . '/../stations/backend/services/EnrollmentService.php';
require_once __DIR__ . '/../shared/backend/utils/student.php';

$pdo = Database::getInstance();

$passed = 0;
$failed = 0;
$total = 0;

function assertTest($description, $condition, $details = '') {
    global $passed, $failed, $total;
    $total++;
    if ($condition) {
        $passed++;
        echo "✅ [PASS] Test $total: $description\n";
        if ($details) echo "   └─ $details\n";
    } else {
        $failed++;
        echo "❌ [FAIL] Test $total: $description\n";
        if ($details) echo "   └─ $details\n";
    }
}

echo "=================================================================================\n";
echo " 🏫 CASHIER TUITION CALCULATION & SEMESTER SUBJECT AUDIT TEST SUITE\n";
echo "=================================================================================\n\n";

// -----------------------------------------------------------------------------
// SECTION 1: Database Integrity & Constraint Verification
// -----------------------------------------------------------------------------
echo "--- SECTION 1: Database Table Integrity & Constraints ---\n";

// 1.1 Curriculum Duplicate Check
$stmt = $pdo->query("
    SELECT program, subject, year_level, semester, curriculum_version, COUNT(*) as cnt 
    FROM curriculum 
    GROUP BY program, subject, year_level, semester, curriculum_version 
    HAVING cnt > 1
");
$dupCurriculum = $stmt->fetchAll(PDO::FETCH_ASSOC);
assertTest(
    "Curriculum table contains ZERO duplicate rows",
    count($dupCurriculum) === 0,
    "Found " . count($dupCurriculum) . " duplicate groups in curriculum"
);

// 1.2 Fee Schedule Duplicate Check
$stmt = $pdo->query("
    SELECT type, label, COUNT(*) as cnt 
    FROM fee_schedule 
    GROUP BY type, label 
    HAVING cnt > 1
");
$dupFees = $stmt->fetchAll(PDO::FETCH_ASSOC);
assertTest(
    "Fee schedule table contains ZERO duplicate rows",
    count($dupFees) === 0,
    "Found " . count($dupFees) . " duplicate groups in fee_schedule"
);

// 1.3 Active Academic Period
$stmt = $pdo->query("SELECT * FROM academic_periods WHERE status = 'Active'");
$activePeriods = $stmt->fetchAll(PDO::FETCH_ASSOC);
assertTest(
    "Exactly ONE active academic period is configured",
    count($activePeriods) === 1,
    count($activePeriods) > 0 ? "Active: " . ($activePeriods[0]['academic_year'] ?? '') . " " . ($activePeriods[0]['semester'] ?? '') : "No active period"
);

// -----------------------------------------------------------------------------
// SECTION 2: Single-Semester Curriculum Isolation
// -----------------------------------------------------------------------------
echo "\n--- SECTION 2: Single-Semester Curriculum Isolation (BSIT 1st Year) ---\n";

$bsit1stSem = QueueService::getCurriculumSubjects($pdo, 'BSIT', '1st Year', '1st Semester', '2022 Curriculum');
$bsit2ndSem = QueueService::getCurriculumSubjects($pdo, 'BSIT', '1st Year', '2nd Semester', '2022 Curriculum');

// 2.1 BSIT 1st Year 1st Sem Subject Count
assertTest(
    "BSIT 1st Year 1st Sem has exactly 7 subjects",
    count($bsit1stSem) === 7,
    "Count: " . count($bsit1stSem) . " (Expected: 7). Codes: " . implode(', ', array_column($bsit1stSem, 'code'))
);

// 2.2 Expected 1st Sem codes
$expected1stCodes = ['GE101', 'GE102', 'GE103', 'IT101', 'IT102', 'NSTP101', 'PE101'];
$actual1stCodes = array_column($bsit1stSem, 'code');
sort($actual1stCodes);
sort($expected1stCodes);
assertTest(
    "BSIT 1st Sem subject codes match standard prospectus exactly",
    $actual1stCodes === $expected1stCodes,
    "Actual: " . implode(', ', $actual1stCodes)
);

// 2.3 BSIT 1st Year 2nd Sem Subject Count
assertTest(
    "BSIT 1st Year 2nd Sem has exactly 7 subjects",
    count($bsit2ndSem) === 7,
    "Count: " . count($bsit2ndSem) . " (Expected: 7). Codes: " . implode(', ', array_column($bsit2ndSem, 'code'))
);

// 2.4 Cross-semester contamination check (No 2nd sem in 1st sem)
$expected2ndCodes = ['GE104', 'GE105', 'GE106', 'IT103', 'IT104', 'NSTP102', 'PE102'];
$actual2ndCodes = array_column($bsit2ndSem, 'code');
sort($actual2ndCodes);
sort($expected2ndCodes);
$overlap1in2 = array_intersect($actual1stCodes, $actual2ndCodes);
assertTest(
    "Zero subject code overlap between 1st Semester and 2nd Semester",
    count($overlap1in2) === 0,
    "Overlap: " . implode(', ', $overlap1in2)
);

// -----------------------------------------------------------------------------
// SECTION 3: Lecture and Laboratory Component Integrity
// -----------------------------------------------------------------------------
echo "\n--- SECTION 3: Lecture and Laboratory Component Accounting ---\n";

// Find IT101 (Intro to Computing - 2 lec + 1 lab = 3 units)
$it101 = null;
foreach ($bsit1stSem as $sub) {
    if ($sub['code'] === 'IT101') {
        $it101 = $sub;
        break;
    }
}
assertTest(
    "IT101 correctly has 2 Lecture Units and 1 Laboratory Unit",
    $it101 && (int)$it101['lecture_units'] === 2 && (int)$it101['lab_units'] === 1 && (int)$it101['units'] === 3,
    $it101 ? "Lec: {$it101['lecture_units']}, Lab: {$it101['lab_units']}, Total: {$it101['units']}" : "IT101 not found"
);

// Total Units BSIT 1st Year 1st Sem
$totalUnits1st = array_sum(array_column($bsit1stSem, 'units'));
$totalLecUnits1st = array_sum(array_column($bsit1stSem, 'lecture_units'));
$totalLabUnits1st = array_sum(array_column($bsit1stSem, 'lab_units'));

assertTest(
    "BSIT 1st Year 1st Sem total units sum to exactly 20.00 (18 Lec + 2 Lab)",
    (float)$totalUnits1st === 20.0 && (float)$totalLecUnits1st === 18.0 && (float)$totalLabUnits1st === 2.0,
    "Total Units: $totalUnits1st (Lec: $totalLecUnits1st, Lab: $totalLabUnits1st)"
);

// -----------------------------------------------------------------------------
// SECTION 4: AssessmentService Mathematical Correctness
// -----------------------------------------------------------------------------
echo "\n--- SECTION 4: AssessmentService Mathematical Calculations ---\n";

// Compute assessment for BSIT 1st Year 1st Sem subjects
$assessment1st = AssessmentService::calculateAssessment($pdo, $bsit1stSem, 'REGULAR');

$tuitionFee1st = (float)($assessment1st['tuitionFee'] ?? $assessment1st['tuition_fee'] ?? 0);
$labFee1st     = (float)($assessment1st['totalLabFee'] ?? $assessment1st['lab_fee'] ?? 0);
$miscFee1st    = (float)($assessment1st['miscFee'] ?? $assessment1st['misc_fee'] ?? 0);
$cashTotal1st  = (float)($assessment1st['cashTotal'] ?? $assessment1st['cash_total'] ?? 0);

$expectedTuition = 13000.00;
$expectedLabFees = 3000.00;
$expectedMiscFees = 2300.00;
$expectedTotalCash = 18300.00;

assertTest(
    "BSIT 1st Sem Tuition Fee is exactly ₱13,000.00 (20 units * ₱650)",
    abs($tuitionFee1st - $expectedTuition) < 0.01,
    "Calculated: ₱" . number_format($tuitionFee1st, 2) . " (Expected: ₱" . number_format($expectedTuition, 2) . ")"
);

assertTest(
    "BSIT 1st Sem Laboratory Fees are exactly ₱3,000.00 (2 lab subjects * ₱1,500)",
    abs($labFee1st - $expectedLabFees) < 0.01,
    "Calculated: ₱" . number_format($labFee1st, 2) . " (Expected: ₱" . number_format($expectedLabFees, 2) . ")"
);

assertTest(
    "BSIT 1st Sem Miscellaneous Fees are exactly ₱2,300.00 (Registration ₱1,500 + Library ₱800)",
    abs($miscFee1st - $expectedMiscFees) < 0.01,
    "Calculated: ₱" . number_format($miscFee1st, 2) . " (Expected: ₱" . number_format($expectedMiscFees, 2) . ")"
);

assertTest(
    "BSIT 1st Sem Total Cash Amount is exactly ₱18,300.00 (NOT ~₱100,000)",
    abs($cashTotal1st - $expectedTotalCash) < 0.01,
    "Calculated: ₱" . number_format($cashTotal1st, 2) . " (Expected: ₱" . number_format($expectedTotalCash, 2) . ")"
);

// Check that the calculated amount is NOT the inflated ~₱100,000
assertTest(
    "Total Cash Amount is strictly under ₱25,000.00 (Defeating the ₱100k bug)",
    $cashTotal1st < 25000.00,
    "Calculated: ₱" . number_format($cashTotal1st, 2)
);

// -----------------------------------------------------------------------------
// SECTION 5: Second Semester Assessment Verification
// -----------------------------------------------------------------------------
echo "\n--- SECTION 5: Second Semester Assessment Verification ---\n";

$assessment2nd = AssessmentService::calculateAssessment($pdo, $bsit2ndSem, 'REGULAR');
$cashTotal2nd  = (float)($assessment2nd['cashTotal'] ?? $assessment2nd['cash_total'] ?? 0);
$expectedTotal2nd = 16800.00;

assertTest(
    "BSIT 2nd Sem Total Cash Amount is exactly ₱16,800.00",
    abs($cashTotal2nd - $expectedTotal2nd) < 0.01,
    "Calculated: ₱" . number_format($cashTotal2nd, 2) . " (Expected: ₱" . number_format($expectedTotal2nd, 2) . ")"
);

// -----------------------------------------------------------------------------
// SECTION 6: QueueService Real Queue Assessment Scoping
// -----------------------------------------------------------------------------
echo "\n--- SECTION 6: QueueService Real Student Queue & Prospectus Scoping ---\n";

$queue = QueueService::fetchQueue($pdo);
assertTest(
    "QueueService::fetchQueue() returns students successfully",
    is_array($queue) && count($queue) > 0,
    "Queue count: " . count($queue)
);

// Check that every student in the queue has correct single-semester subjects and assessment
$allStudentsUnder30k = true;
$allHaveSingleSemSubjects = true;
$checkedCount = 0;

foreach ($queue as $student) {
    $checkedCount++;
    $prospectus = $student['prospectusSubjects'] ?? [];
    $assessment = $student['assessmentSnapshot'] ?? [];
    $totalAmount = (float)($assessment['totalAmount'] ?? $assessment['cash_total'] ?? $student['cash_total'] ?? 0);
    
    if ($totalAmount > 30000.00) {
        $allStudentsUnder30k = false;
        echo "   ⚠️ Student {$student['reference_number']} has high total: ₱$totalAmount\n";
    }
    
    // Check that prospectus subjects don't exceed typical 1-semester load (normally 5-9 subjects, never 14-28)
    if (count($prospectus) > 10) {
        $allHaveSingleSemSubjects = false;
        echo "   ⚠️ Student {$student['reference_number']} has " . count($prospectus) . " prospectus subjects\n";
    }
}

assertTest(
    "All students in the queue have assessment totals under ₱30,000.00",
    $allStudentsUnder30k,
    "Evaluated $checkedCount students in active queue"
);

assertTest(
    "All students in the queue have single-semester prospectus subjects (<= 10 subjects)",
    $allHaveSingleSemSubjects,
    "Evaluated $checkedCount students in active queue"
);

// -----------------------------------------------------------------------------
// SECTION 7: Pre-Enrollment DB State Verification
// -----------------------------------------------------------------------------
echo "\n--- SECTION 7: Pre-Enrollment DB Records Integrity ---\n";

// Check pre_enrollments in DB to ensure no helpdesk_data contains duplicate advised subjects
$stmt = $pdo->query("
    SELECT id, temp_student_id, course_code, year_level_applied, helpdesk_data, enrollment_data 
    FROM pre_enrollments 
    WHERE status IN ('ADVISED', 'MEDICAL_CLEARED', 'PAID', 'ENROLLED')
");
$advisedStudents = $stmt->fetchAll(PDO::FETCH_ASSOC);

$allAdvisedClean = true;
foreach ($advisedStudents as $st) {
    if (!empty($st['helpdesk_data'])) {
        $hd = json_decode($st['helpdesk_data'], true);
        if (is_array($hd) && !empty($hd['advisedSubjects'])) {
            $adv = $hd['advisedSubjects'];
            $codes = [];
            foreach ($adv as $s) {
                $c = is_array($s) ? ($s['code'] ?? '') : (string)$s;
                if ($c) {
                    if (isset($codes[$c])) {
                        $allAdvisedClean = false;
                        echo "   ⚠️ Pre-enrollment ID {$st['id']} has duplicate advised code: $c\n";
                    }
                    $codes[$c] = true;
                }
            }
        }
    }
}

assertTest(
    "All pre-enrollments in database have deduplicated advised subjects",
    $allAdvisedClean,
    "Evaluated " . count($advisedStudents) . " advised pre-enrollment records"
);

echo "\n=================================================================================\n";
echo " TEST SUMMARY: Passed: $passed / $total | Failed: $failed / $total\n";
echo "=================================================================================\n";

if ($failed > 0) {
    exit(1);
}
