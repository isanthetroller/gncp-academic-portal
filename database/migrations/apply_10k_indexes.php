<?php
/**
 * Migration Script: Apply 10k+ Student Performance Indexes to live MariaDB
 * Safe, idempotent migration that inspects information_schema before adding indexes.
 */

require_once __DIR__ . '/../../shared/backend/config/database.php';

$pdo = Database::getInstance();

echo "=======================================================\n";
echo "GNCP Database Migration: High-Concurrency 10k+ Indexes\n";
echo "=======================================================\n\n";

$indexes = [
    // Table => [ IndexName => [Columns] ]
    'pre_enrollments' => [
        'idx_pe_status_created' => '(`status`, `created_at`)',
        'idx_pe_course_year'    => '(`course_code`, `year_level_applied`)',
        'idx_pe_email'          => '(`email`)',
        'idx_pe_phone'          => '(`phone`)',
        'idx_existing_student_id' => '(`existing_student_id`)',
    ],
    'station_users' => [
        'idx_user_role_status'  => '(`role`, `status`)',
    ],
    'students' => [
        'idx_stud_temp_ref'       => '(`temp_reference_no`)',
        'idx_stud_email'          => '(`email`)',
        'idx_stud_prog_yr_status' => '(`program`, `year_level`, `status`)',
        'idx_stud_created'        => '(`created_at`)',
    ],
    'programs' => [
        'idx_prog_dept' => '(`department`, `status`)',
    ],
    'subjects' => [
        'idx_subj_dept'  => '(`department`)',
        'idx_subj_title' => '(`title`)',
    ],
    'curriculum' => [
        'idx_curr_lookup' => '(`program`, `year_level`, `semester`, `curriculum_version`)',
    ],
    'academic_periods' => [
        'idx_period_status' => '(`status`)',
        'idx_period_ay_sem' => '(`academic_year`, `semester`)',
    ],
    'subject_sections' => [
        'idx_ss_lookup'     => '(`program`, `year_level`, `semester`, `subject`)',
        'idx_ss_section_id' => '(`section_id`)',
        'idx_ss_capacity'   => '(`capacity`)',
    ],
    'fee_schedule' => [
        'idx_fee_type' => '(`type`)',
    ],
    'departments' => [
        'idx_dept_status' => '(`status`)',
    ],
    'sections' => [
        'idx_sect_period'  => '(`academic_period_id`)',
        'idx_sect_prog_yr' => '(`program`, `year_level`)',
    ],
    'audit_logs' => [
        'idx_ref_num'       => '(`reference_number`)',
        'idx_operator'      => '(`operator_username`)',
        'idx_audit_created' => '(`created_at`)',
        'idx_audit_action'  => '(`action_performed`)',
    ],
    'password_resets' => [
        'idx_email'   => '(`email`)',
        'idx_code'    => '(`code`)',
        'idx_token'   => '(`token`)',
        'idx_expires' => '(`expires_at`)',
    ],
    'announcements' => [
        'idx_status_pinned_created' => '(`status`, `is_pinned`, `created_at`)',
        'idx_category'              => '(`category`)',
        'idx_author_id'             => '(`author_id`)',
    ]
];

$applied = 0;
$skipped = 0;

foreach ($indexes as $table => $tableIndexes) {
    // Check existing indexes for table
    $stmt = $pdo->prepare("SELECT DISTINCT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table");
    $stmt->execute(['table' => $table]);
    $existing = $stmt->fetchAll(PDO::FETCH_COLUMN);

    foreach ($tableIndexes as $indexName => $columnsDef) {
        if (in_array($indexName, $existing)) {
            echo "[-] Table `{$table}`: Index `{$indexName}` already exists. Skipped.\n";
            $skipped++;
            continue;
        }

        try {
            $sql = "ALTER TABLE `{$table}` ADD INDEX `{$indexName}` {$columnsDef}";
            $pdo->exec($sql);
            echo "[+] Table `{$table}`: Added Index `{$indexName}` {$columnsDef}\n";
            $applied++;
        } catch (Exception $e) {
            echo "[!] Table `{$table}`: Failed to add Index `{$indexName}`: " . $e->getMessage() . "\n";
        }
    }
}

echo "\n=======================================================\n";
echo "Migration Complete: {$applied} applied, {$skipped} already present.\n";
echo "=======================================================\n";
?>
