<?php
/**
 * Database Migration: Deduplicate Curriculum, Fee Schedule, and Academic Periods
 * Adds UNIQUE constraints to prevent duplicate row re-insertion.
 * Also repairs pre_enrollments records that have bloated duplicate advised subjects.
 */
require_once __DIR__ . '/../../shared/backend/config/database.php';
require_once __DIR__ . '/../../shared/backend/services/AssessmentService.php';
require_once __DIR__ . '/../../shared/backend/utils/student.php';

$pdo = Database::getInstance();
echo "Starting Database Migration: Deduplication & Constraints...\n";

// 1. DEDUPLICATE curriculum TABLE
echo "\n--- 1. Deduplicating `curriculum` Table ---\n";
$beforeCurCount = $pdo->query("SELECT COUNT(*) FROM `curriculum`")->fetchColumn();
echo "Curriculum rows before: $beforeCurCount\n";

// Delete duplicate rows keeping the one with MIN(id)
$pdo->exec("
    DELETE c1 FROM `curriculum` c1
    INNER JOIN `curriculum` c2 
    ON c1.program = c2.program 
       AND c1.subject = c2.subject 
       AND c1.year_level = c2.year_level 
       AND c1.semester = c2.semester 
       AND c1.curriculum_version = c2.curriculum_version
       AND c1.id > c2.id
");
$afterCurCount = $pdo->query("SELECT COUNT(*) FROM `curriculum`")->fetchColumn();
echo "Curriculum rows after deduplication: $afterCurCount (removed " . ($beforeCurCount - $afterCurCount) . " duplicate rows)\n";

// Add UNIQUE Index on curriculum if not exists
try {
    $existingIndexes = $pdo->query("SHOW INDEX FROM `curriculum` WHERE Key_name = 'idx_curr_unique'")->fetchAll();
    if (empty($existingIndexes)) {
        $pdo->exec("ALTER TABLE `curriculum` ADD UNIQUE KEY `idx_curr_unique` (`program`, `subject`, `year_level`, `semester`, `curriculum_version`)");
        echo "Successfully added UNIQUE index `idx_curr_unique` to `curriculum`.\n";
    } else {
        echo "UNIQUE index `idx_curr_unique` already exists.\n";
    }
} catch (Exception $e) {
    echo "Notice on curriculum index: " . $e->getMessage() . "\n";
}

// 2. DEDUPLICATE fee_schedule TABLE
echo "\n--- 2. Deduplicating `fee_schedule` Table ---\n";
$beforeFeeCount = $pdo->query("SELECT COUNT(*) FROM `fee_schedule`")->fetchColumn();
echo "Fee schedule rows before: $beforeFeeCount\n";

$pdo->exec("
    DELETE f1 FROM `fee_schedule` f1
    INNER JOIN `fee_schedule` f2 
    ON f1.type = f2.type 
       AND f1.label = f2.label 
       AND f1.id > f2.id
");
$afterFeeCount = $pdo->query("SELECT COUNT(*) FROM `fee_schedule`")->fetchColumn();
echo "Fee schedule rows after deduplication: $afterFeeCount (removed " . ($beforeFeeCount - $afterFeeCount) . " duplicate rows)\n";

// Add UNIQUE Index on fee_schedule if not exists
try {
    $existingIndexes = $pdo->query("SHOW INDEX FROM `fee_schedule` WHERE Key_name = 'idx_fee_type_label'")->fetchAll();
    if (empty($existingIndexes)) {
        $pdo->exec("ALTER TABLE `fee_schedule` ADD UNIQUE KEY `idx_fee_type_label` (`type`, `label`)");
        echo "Successfully added UNIQUE index `idx_fee_type_label` to `fee_schedule`.\n";
    } else {
        echo "UNIQUE index `idx_fee_type_label` already exists.\n";
    }
} catch (Exception $e) {
    echo "Notice on fee_schedule index: " . $e->getMessage() . "\n";
}

// Ensure clean standard rates exist
$pdo->exec("
    INSERT INTO `fee_schedule` (`type`, `label`, `amount`, `per_unit`) VALUES
    ('Tuition', 'Tuition Fee per Unit', 650.00, 1),
    ('Miscellaneous', 'Registration Fee', 1500.00, 0),
    ('Miscellaneous', 'Library Fee', 800.00, 0),
    ('Laboratory', 'Computer Lab Fee', 2000.00, 0),
    ('Laboratory', 'Science Lab Fee', 2500.00, 0)
    ON DUPLICATE KEY UPDATE `amount`=VALUES(`amount`), `per_unit`=VALUES(`per_unit`)
");

// 3. DEDUPLICATE academic_periods TABLE
echo "\n--- 3. Deduplicating `academic_periods` Table ---\n";
$beforePeriods = $pdo->query("SELECT COUNT(*) FROM `academic_periods`")->fetchColumn();
$pdo->exec("
    DELETE p1 FROM `academic_periods` p1
    INNER JOIN `academic_periods` p2 
    ON p1.academic_year = p2.academic_year 
       AND p1.semester = p2.semester 
       AND p1.id > p2.id
");
$afterPeriods = $pdo->query("SELECT COUNT(*) FROM `academic_periods`")->fetchColumn();
echo "Academic periods rows after deduplication: $afterPeriods (removed " . ($beforePeriods - $afterPeriods) . " duplicate rows)\n";

// Ensure active status on 1st Semester
$pdo->exec("UPDATE `academic_periods` SET `status` = 'Active' WHERE `semester` = '1st Semester' LIMIT 1");
$pdo->exec("UPDATE `academic_periods` SET `status` = 'Inactive' WHERE `semester` = '2nd Semester'");

// 4. RECALCULATE BLOATED PRE_ENROLLMENTS RECORDS
echo "\n--- 4. Repairing Bloated Pre-Enrollment Advised Subjects & Assessment Snapshots ---\n";
$activePeriod = $pdo->query("SELECT * FROM `academic_periods` WHERE `status` = 'Active' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$activeSem = $activePeriod ? $activePeriod['semester'] : '1st Semester';

$stmt = $pdo->query("
    SELECT id, temp_student_id, course_code, year_level_applied, curriculum_version, nstp, 
           helpdesk_data, payment_data, scholarship_data, status 
    FROM `pre_enrollments` 
    WHERE `status` IN ('ADVISED', 'MEDICAL_CLEARED', 'PAID', 'PROMOTED', 'ENROLLED')
");
$records = $stmt->fetchAll(PDO::FETCH_ASSOC);
$repairedCount = 0;

foreach ($records as $r) {
    $helpdesk = json_decode($r['helpdesk_data'] ?? '{}', true) ?: [];
    $payment = json_decode($r['payment_data'] ?? '{}', true) ?: [];
    $advisedSubjects = $helpdesk['advisedSubjects'] ?? [];

    // Check if duplicate subjects exist
    $seenCodes = [];
    $hasDuplicates = false;
    foreach ($advisedSubjects as $sub) {
        $c = $sub['code'] ?? '';
        if (isset($seenCodes[$c])) {
            $hasDuplicates = true;
            break;
        }
        $seenCodes[$c] = true;
    }

    if ($hasDuplicates || count($advisedSubjects) > 10) {
        // Deduplicate subjects by code
        $cleanSubjects = [];
        $uniqueCodes = [];
        foreach ($advisedSubjects as $sub) {
            $c = $sub['code'] ?? '';
            if (!empty($c) && !isset($uniqueCodes[$c])) {
                $uniqueCodes[$c] = true;
                $cleanSubjects[] = $sub;
            }
        }

        // If cleanSubjects is empty or still suspicious, re-fetch from curriculum
        if (empty($cleanSubjects)) {
            $yl = $r['year_level_applied'] ?: '1st Year';
            $cv = $r['curriculum_version'] ?: '2022 Curriculum';
            $cleanSubjects = getCurriculumSubjects($pdo, $r['course_code'], $yl, $activeSem, $cv);
        }

        // Recalculate assessment
        $nstp = strtoupper($r['nstp'] ?? 'NONE');
        $scholarshipData = json_decode($r['scholarship_data'] ?? '{}', true) ?: [];
        $discount = (float)($scholarshipData['discount'] ?? 0.00);
        $newAssessment = AssessmentService::calculateAssessment($pdo, $cleanSubjects, $nstp, $discount);

        $helpdesk['advisedSubjects'] = $cleanSubjects;
        $payment['assessmentSnapshot'] = $newAssessment;
        $payment['totalFee'] = $newAssessment['cashTotal'];
        $amtPaid = (float)($payment['amountPaid'] ?? 0);
        $payment['balance'] = max(0.00, round($newAssessment['cashTotal'] - $amtPaid, 2));

        $updateStmt = $pdo->prepare("
            UPDATE `pre_enrollments` 
            SET `helpdesk_data` = :hd, `payment_data` = :pd 
            WHERE `id` = :id
        ");
        $updateStmt->execute([
            ':hd' => json_encode($helpdesk),
            ':pd' => json_encode($payment),
            ':id' => $r['id']
        ]);
        $repairedCount++;
        echo "Repaired student ID {$r['id']} ({$r['temp_student_id']}): Sub count " . count($advisedSubjects) . " -> " . count($cleanSubjects) . " | TotalFee: ₱" . number_format($newAssessment['cashTotal'], 2) . "\n";
    }
}

echo "Repaired $repairedCount bloated pre_enrollment records.\n";
echo "\nDatabase migration completed successfully!\n";
