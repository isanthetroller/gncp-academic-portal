<?php
/**
 * Migration: Add Security Session & Rate-Limit Tracking Columns
 * Ensures station_users and students tables have active_session_token, last_login_at, and last_login_ip.
 */
require_once __DIR__ . '/../../shared/backend/config/database.php';

try {
    $pdo = Database::getInstance();
    echo "Connecting to MariaDB database...\n";

    // 1. Update station_users
    $colsStation = $pdo->query("SHOW COLUMNS FROM `station_users`")->fetchAll(PDO::FETCH_COLUMN);
    
    if (!in_array('active_session_token', $colsStation, true)) {
        $pdo->exec("ALTER TABLE `station_users` ADD COLUMN `active_session_token` VARCHAR(64) DEFAULT NULL AFTER `must_change_password`");
        echo "  ✓ Added `active_session_token` column to `station_users`.\n";
    } else {
        echo "  - `station_users.active_session_token` already exists.\n";
    }

    if (!in_array('last_login_at', $colsStation, true)) {
        $pdo->exec("ALTER TABLE `station_users` ADD COLUMN `last_login_at` DATETIME DEFAULT NULL AFTER `active_session_token`");
        echo "  ✓ Added `last_login_at` column to `station_users`.\n";
    } else {
        echo "  - `station_users.last_login_at` already exists.\n";
    }

    if (!in_array('last_login_ip', $colsStation, true)) {
        $pdo->exec("ALTER TABLE `station_users` ADD COLUMN `last_login_ip` VARCHAR(45) DEFAULT NULL AFTER `last_login_at`");
        echo "  ✓ Added `last_login_ip` column to `station_users`.\n";
    } else {
        echo "  - `station_users.last_login_ip` already exists.\n";
    }

    // 2. Update students
    $colsStudents = $pdo->query("SHOW COLUMNS FROM `students`")->fetchAll(PDO::FETCH_COLUMN);

    if (!in_array('active_session_token', $colsStudents, true)) {
        $pdo->exec("ALTER TABLE `students` ADD COLUMN `active_session_token` VARCHAR(64) DEFAULT NULL AFTER `must_change_password`");
        echo "  ✓ Added `active_session_token` column to `students`.\n";
    } else {
        echo "  - `students.active_session_token` already exists.\n";
    }

    if (!in_array('last_login_at', $colsStudents, true)) {
        $pdo->exec("ALTER TABLE `students` ADD COLUMN `last_login_at` DATETIME DEFAULT NULL AFTER `active_session_token`");
        echo "  ✓ Added `last_login_at` column to `students`.\n";
    } else {
        echo "  - `students.last_login_at` already exists.\n";
    }

    if (!in_array('last_login_ip', $colsStudents, true)) {
        $pdo->exec("ALTER TABLE `students` ADD COLUMN `last_login_ip` VARCHAR(45) DEFAULT NULL AFTER `last_login_at`");
        echo "  ✓ Added `last_login_ip` column to `students`.\n";
    } else {
        echo "  - `students.last_login_ip` already exists.\n";
    }

    echo "Migration completed successfully!\n";
} catch (Exception $e) {
    echo "Migration Failed: " . $e->getMessage() . "\n";
    exit(1);
}
