<?php
/**
 * Migration: Create Section Cohorts and Link Unassigned Class Offerings
 */

require_once __DIR__ . '/../shared/backend/config/database.php';

try {
    $pdo = Database::getInstance();
    $pdo->beginTransaction();

    echo "Starting database mapping migration...\n";

    // 1. Resolve the active academic period
    $activePeriodId = (int)$pdo->query("SELECT `id` FROM `academic_periods` WHERE `status` = 'Active' LIMIT 1")->fetchColumn();
    if (!$activePeriodId) {
        throw new Exception("No active academic period found to target.");
    }
    echo "Resolved active academic period ID: $activePeriodId\n";

    // 2. Fetch all class offerings
    $offerings = $pdo->query("SELECT * FROM `subject_sections`")->fetchAll(PDO::FETCH_ASSOC);

    $linkedCount = 0;
    $createdSectionsCount = 0;

    foreach ($offerings as $off) {
        $id = $off['id'];
        $code = $off['code'];
        $program = $off['program'];
        $yearLevel = $off['year_level'];

        // Extract section suffix: e.g. IT-IT101-A -> "A"
        $parts = explode('-', $code);
        $suffix = strtoupper(end($parts));

        if (empty($suffix) || strlen($suffix) > 5) {
            echo "Skipping invalid class code format: $code\n";
            continue;
        }

        // Check if section cohort already exists
        $secStmt = $pdo->prepare("
            SELECT `id` FROM `sections` 
            WHERE `code` = :code 
            AND `program` = :program 
            AND `year_level` = :year_level 
            AND `academic_period_id` = :ap_id
        ");
        $secStmt->execute([
            'code' => $suffix,
            'program' => $program,
            'year_level' => $yearLevel,
            'ap_id' => $activePeriodId
        ]);
        $sectionId = $secStmt->fetchColumn();

        // If it doesn't exist, create it
        if (!$sectionId) {
            $cv = '2022 Curriculum';

            $insStmt = $pdo->prepare("
                INSERT INTO `sections` (`code`, `program`, `year_level`, `academic_period_id`, `curriculum_version`, `capacity`) 
                VALUES (:code, :program, :year_level, :ap_id, :cv, 40)
            ");
            $insStmt->execute([
                'code' => $suffix,
                'program' => $program,
                'year_level' => $yearLevel,
                'ap_id' => $activePeriodId,
                'cv' => $cv
            ]);
            $sectionId = (int)$pdo->lastInsertId();
            $createdSectionsCount++;
            echo "Created section cohort: $program - $yearLevel - Section $suffix (ID: $sectionId)\n";
        }

        // Link the class offering to the section cohort
        $updStmt = $pdo->prepare("UPDATE `subject_sections` SET `section_id` = :sec_id WHERE `id` = :id");
        $updStmt->execute(['sec_id' => $sectionId, 'id' => $id]);
        $linkedCount++;
    }

    $pdo->commit();
    echo "\nMigration complete! Created $createdSectionsCount sections and linked $linkedCount class offerings.\n";

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "ERROR: " . $e->getMessage() . "\n";
}
