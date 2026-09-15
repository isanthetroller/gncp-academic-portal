<?php
/**
 * Migration: Add program, year_level, and semester to subject_sections
 */

require_once __DIR__ . '/../shared/backend/config/database.php';

try {
    $pdo = Database::getInstance();

    echo "Running migration to update subject_sections table...\n";

    // 1. Add columns if they don't exist
    $pdo->exec("ALTER TABLE `subject_sections` 
        ADD COLUMN IF NOT EXISTS `program` VARCHAR(150) NOT NULL DEFAULT '',
        ADD COLUMN IF NOT EXISTS `year_level` VARCHAR(50) NOT NULL DEFAULT '',
        ADD COLUMN IF NOT EXISTS `semester` VARCHAR(50) NOT NULL DEFAULT '';
    ");
    echo "Columns added successfully or already existed.\n";

    // 2. Fetch all sections
    $sections = $pdo->query("SELECT * FROM `subject_sections`")->fetchAll();
    
    // We want to map each section's subject to its program, year_level, and semester based on the curriculum mapping
    // If not found in curriculum, we map based on subject code/prefix or default.
    $curriculumRaw = $pdo->query("SELECT * FROM `curriculum`")->fetchAll();
    $curriculumMap = [];
    foreach ($curriculumRaw as $curr) {
        $curriculumMap[$curr['subject']] = $curr;
    }

    $updateStmt = $pdo->prepare("UPDATE `subject_sections` SET `program` = :prog, `year_level` = :yl, `semester` = :sem WHERE `id` = :id");

    foreach ($sections as $sect) {
        $subject = $sect['subject'];
        $prog = 'BS Computer Science';
        $yl = '1st Year';
        $sem = '1st Semester';

        // Check if subject exists in curriculum
        if (isset($curriculumMap[$subject])) {
            $prog = $curriculumMap[$subject]['program'];
            $yl = $curriculumMap[$subject]['year_level'];
            $sem = $curriculumMap[$subject]['semester'];
        } else {
            // Fallback heuristics based on prefix
            $code = $sect['code'];
            if (strpos($code, 'IT') === 0) {
                $prog = 'BS Information Technology';
            } elseif (strpos($code, 'NUR') === 0) {
                $prog = 'BS Nursing';
            } elseif (strpos($code, 'BA') === 0) {
                $prog = 'BS Business Administration';
            }
            
            // Year level / Semester heuristic based on subject name
            if (strpos($subject, '2') !== false || strpos($code, '20') !== false) {
                $yl = '2nd Year';
            } elseif (strpos($subject, '3') !== false || strpos($code, '30') !== false) {
                $yl = '3rd Year';
            } elseif (strpos($subject, '4') !== false || strpos($code, '40') !== false) {
                $yl = '4th Year';
            }
        }

        echo "Updating section {$sect['code']} ({$subject}) -> Program: {$prog}, Year: {$yl}, Semester: {$sem}\n";
        $updateStmt->execute([
            'prog' => $prog,
            'yl' => $yl,
            'sem' => $sem,
            'id' => $sect['id']
        ]);
    }

    echo "Migration completed successfully!\n";

} catch (Exception $e) {
    echo "ERROR during migration: " . $e->getMessage() . "\n";
}
