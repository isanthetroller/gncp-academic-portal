<?php
/**
 * Database Migration: Create Professor and Scheduler Tables & Link Existing Instructors
 * Safe and idempotent migration script.
 */

require_once __DIR__ . '/../../shared/backend/config/database.php';

try {
    $pdo = Database::getInstance();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "Connected to database successfully.\n";

    // 1. Create professors table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `professors` (
            `id`             INT AUTO_INCREMENT PRIMARY KEY,
            `user_id`        INT UNIQUE NOT NULL COMMENT 'FK to station_users.id',
            `employee_id`    VARCHAR(50) UNIQUE NOT NULL COMMENT 'Faculty ID e.g. FAC-2026-001',
            `name`           VARCHAR(150) NOT NULL,
            `department_id`  INT NOT NULL COMMENT 'FK to departments.id',
            `email`          VARCHAR(150) DEFAULT NULL,
            `status`         VARCHAR(20) NOT NULL DEFAULT 'ACTIVE',
            `created_at`     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at`     TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX `idx_prof_dept` (`department_id`, `status`),
            INDEX `idx_prof_user` (`user_id`)
        ) ENGINE=InnoDB;
    ");
    echo "Table 'professors' ensured.\n";

    // 2. Create professor_availability table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `professor_availability` (
            `id`             INT AUTO_INCREMENT PRIMARY KEY,
            `professor_id`   INT NOT NULL,
            `day_of_week`    VARCHAR(20) NOT NULL COMMENT 'Monday, Tuesday, Wednesday, Thursday, Friday, Saturday',
            `start_time`     TIME NOT NULL,
            `end_time`       TIME NOT NULL,
            `is_available`   TINYINT(1) NOT NULL DEFAULT 1,
            `notes`          VARCHAR(255) DEFAULT NULL,
            `created_at`     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_avail_prof_day` (`professor_id`, `day_of_week`)
        ) ENGINE=InnoDB;
    ");
    echo "Table 'professor_availability' ensured.\n";

    // 3. Extend subject_sections with professor_id if not present
    $colCheck = $pdo->query("SHOW COLUMNS FROM `subject_sections` LIKE 'professor_id'")->fetch();
    if (!$colCheck) {
        $pdo->exec("ALTER TABLE `subject_sections` ADD COLUMN `professor_id` INT NULL DEFAULT NULL AFTER `instructor`");
        $pdo->exec("ALTER TABLE `subject_sections` ADD INDEX `idx_ss_professor` (`professor_id`)");
        echo "Column 'professor_id' added to 'subject_sections'.\n";
    } else {
        echo "Column 'professor_id' already exists in 'subject_sections'.\n";
    }

    // 4. Create professor_files table (Personal Drive)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `professor_files` (
            `id`              INT AUTO_INCREMENT PRIMARY KEY,
            `professor_id`    INT NOT NULL,
            `filename`        VARCHAR(255) NOT NULL,
            `stored_filename` VARCHAR(255) NOT NULL,
            `file_type`       VARCHAR(20) NOT NULL,
            `mime_type`       VARCHAR(100) NOT NULL,
            `file_size`       BIGINT NOT NULL,
            `storage_path`    VARCHAR(255) NOT NULL,
            `created_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX `idx_files_prof` (`professor_id`, `created_at`)
        ) ENGINE=InnoDB;
    ");
    echo "Table 'professor_files' ensured.\n";

    // 5. Create lms_materials table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `lms_materials` (
            `id`              INT AUTO_INCREMENT PRIMARY KEY,
            `professor_id`    INT NOT NULL,
            `file_id`         INT NOT NULL,
            `title`           VARCHAR(255) NOT NULL,
            `description`     TEXT DEFAULT NULL,
            `subject_code`    VARCHAR(50) DEFAULT NULL,
            `created_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_mat_prof` (`professor_id`),
            INDEX `idx_mat_file` (`file_id`)
        ) ENGINE=InnoDB;
    ");
    echo "Table 'lms_materials' ensured.\n";

    // 6. Create lms_material_sections table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `lms_material_sections` (
            `id`              INT AUTO_INCREMENT PRIMARY KEY,
            `material_id`     INT NOT NULL,
            `section_id`      INT NULL,
            `section_code`    VARCHAR(50) NOT NULL,
            `created_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_lms_sec_mat` (`material_id`),
            INDEX `idx_lms_sec_code` (`section_code`)
        ) ENGINE=InnoDB;
    ");
    echo "Table 'lms_material_sections' ensured.\n";

    // 7. Create announcement_sections table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `announcement_sections` (
            `id`               INT AUTO_INCREMENT PRIMARY KEY,
            `announcement_id`  INT NOT NULL,
            `section_id`       INT NULL,
            `section_code`     VARCHAR(50) NOT NULL,
            INDEX `idx_ann_sec_id` (`announcement_id`),
            INDEX `idx_ann_sec_code` (`section_code`)
        ) ENGINE=InnoDB;
    ");
    echo "Table 'announcement_sections' ensured.\n";

    // 8. Bootstrap SCHEDULER station user
    $schedCheck = $pdo->prepare("SELECT id FROM `station_users` WHERE username = 'scheduler' LIMIT 1");
    $schedCheck->execute();
    $schedId = $schedCheck->fetchColumn();
    if (!$schedId) {
        $schedPass = password_hash('Gncp#2026!', PASSWORD_DEFAULT);
        $insertSched = $pdo->prepare("
            INSERT INTO `station_users` (`username`, `password`, `name`, `email`, `role`, `status`, `must_change_password`)
            VALUES ('scheduler', :pass, 'Academic Scheduler Officer', 'scheduler@gncp.edu.ph', 'SCHEDULER', 'ACTIVE', 0)
        ");
        $insertSched->execute(['pass' => $schedPass]);
        echo "Default SCHEDULER account provisioned (username: scheduler).\n";
    } else {
        echo "SCHEDULER account already exists.\n";
    }

    // 9. Fetch department map
    $deptRows = $pdo->query("SELECT id, code, name FROM `departments`")->fetchAll(PDO::FETCH_ASSOC);
    $deptMap = [];
    foreach ($deptRows as $d) {
        $deptMap[strtoupper($d['code'])] = (int)$d['id'];
        $deptMap[strtoupper($d['name'])] = (int)$d['id'];
    }

    // Default department fallbacks
    $ccsId  = $deptMap['CCS'] ?? 1;
    $gedId  = $deptMap['GED'] ?? 4;
    $peId   = $deptMap['PE'] ?? 6;
    $nstpId = $deptMap['NSTP'] ?? 5;
    $chsId  = $deptMap['CHS'] ?? 2;
    $cobId  = $deptMap['COB'] ?? 3;

    // 10. Seed / migrate existing legacy instructors into station_users + professors
    $legacyInstructors = [
        ['name' => 'Prof. Steve Jobs',     'username' => 'steve.jobs',     'email' => 'steve.jobs@gncp.edu.ph',     'empId' => 'FAC-CCS-001',  'deptId' => $ccsId],
        ['name' => 'Prof. Dennis Ritchie', 'username' => 'dennis.ritchie', 'email' => 'dennis.ritchie@gncp.edu.ph', 'empId' => 'FAC-CCS-002',  'deptId' => $ccsId],
        ['name' => 'Dr. Pythagoras',       'username' => 'pythagoras',     'email' => 'pythagoras@gncp.edu.ph',     'empId' => 'FAC-GED-001',  'deptId' => $gedId],
        ['name' => 'Dr. Shakespeare',      'username' => 'shakespeare',    'email' => 'shakespeare@gncp.edu.ph',    'empId' => 'FAC-GED-002',  'deptId' => $gedId],
        ['name' => 'Dr. Freud',            'username' => 'freud',          'email' => 'freud@gncp.edu.ph',          'empId' => 'FAC-GED-003',  'deptId' => $gedId],
        ['name' => 'Coach Carter',         'username' => 'carter',         'email' => 'carter@gncp.edu.ph',         'empId' => 'FAC-PE-001',   'deptId' => $peId],
        ['name' => 'Major Payne',          'username' => 'payne',          'email' => 'payne@gncp.edu.ph',          'empId' => 'FAC-NSTP-001', 'deptId' => $nstpId],
        ['name' => 'Prof. Alan Turing',    'username' => 'alan.turing',    'email' => 'alan.turing@gncp.edu.ph',    'empId' => 'FAC-CCS-003',  'deptId' => $ccsId],
        ['name' => 'Prof. Grace Hopper',   'username' => 'grace.hopper',   'email' => 'grace.hopper@gncp.edu.ph',   'empId' => 'FAC-CCS-004',  'deptId' => $ccsId],
        ['name' => 'Prof. Steve Wozniak',  'username' => 'steve.wozniak',  'email' => 'steve.wozniak@gncp.edu.ph',  'empId' => 'FAC-CCS-005',  'deptId' => $ccsId],
        ['name' => 'Dr. Euler',            'username' => 'euler',          'email' => 'euler@gncp.edu.ph',          'empId' => 'FAC-CCS-006',  'deptId' => $ccsId],
        ['name' => 'Dr. Nobel',            'username' => 'nobel',          'email' => 'nobel@gncp.edu.ph',          'empId' => 'FAC-GED-004',  'deptId' => $gedId],
    ];

    $defaultFacultyPass = password_hash('Gncp#2026!', PASSWORD_DEFAULT);

    foreach ($legacyInstructors as $inst) {
        // 10a. Ensure station_users account
        $uStmt = $pdo->prepare("SELECT id FROM `station_users` WHERE username = :u LIMIT 1");
        $uStmt->execute(['u' => $inst['username']]);
        $uId = $uStmt->fetchColumn();

        if (!$uId) {
            $insU = $pdo->prepare("
                INSERT INTO `station_users` (`username`, `password`, `name`, `email`, `role`, `status`, `must_change_password`)
                VALUES (:u, :p, :name, :email, 'PROFESSOR', 'ACTIVE', 0)
            ");
            $insU->execute([
                'u'     => $inst['username'],
                'p'     => $defaultFacultyPass,
                'name'  => $inst['name'],
                'email' => $inst['email']
            ]);
            $uId = (int)$pdo->lastInsertId();
            echo "Created station_user: {$inst['username']} (ID: $uId)\n";
        }

        // 10b. Ensure professors record
        $pStmt = $pdo->prepare("SELECT id FROM `professors` WHERE user_id = :uid LIMIT 1");
        $pStmt->execute(['uid' => $uId]);
        $profId = $pStmt->fetchColumn();

        if (!$profId) {
            $insP = $pdo->prepare("
                INSERT INTO `professors` (`user_id`, `employee_id`, `name`, `department_id`, `email`, `status`)
                VALUES (:uid, :empid, :name, :deptid, :email, 'ACTIVE')
            ");
            $insP->execute([
                'uid'    => $uId,
                'empid'  => $inst['empId'],
                'name'   => $inst['name'],
                'deptid' => $inst['deptId'],
                'email'  => $inst['email']
            ]);
            $profId = (int)$pdo->lastInsertId();
            echo "Created professor record: {$inst['name']} (Prof ID: $profId)\n";
        }

        // 10c. Link subject_sections where instructor = name
        $linkStmt = $pdo->prepare("UPDATE `subject_sections` SET `professor_id` = :pid WHERE `instructor` = :name AND (`professor_id` IS NULL OR `professor_id` = 0)");
        $linkStmt->execute(['pid' => $profId, 'name' => $inst['name']]);
        $linkedCount = $linkStmt->rowCount();
        if ($linkedCount > 0) {
            echo "Linked $linkedCount subject_sections to {$inst['name']}\n";
        }
    }

    echo "\n=== Migration successfully completed! ===\n";

} catch (Exception $e) {
    echo "Migration failed: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
