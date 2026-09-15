<?php
require_once __DIR__ . '/../shared/backend/config/database.php';

try {
    $pdo = Database::getInstance();
    $stmt = $pdo->query("SHOW COLUMNS FROM `subject_sections` LIKE 'section_id'");
    if ($stmt->rowCount() == 0) {
        $pdo->exec("ALTER TABLE `subject_sections` ADD COLUMN `section_id` INT DEFAULT NULL AFTER `capacity`");
        echo "Successfully added section_id column to subject_sections.\n";
    } else {
        echo "section_id column already exists in subject_sections.\n";
    }
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
