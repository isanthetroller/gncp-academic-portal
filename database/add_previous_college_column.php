<?php
require_once __DIR__ . '/../shared/backend/config/database.php';

try {
    $pdo = Database::getInstance();
    $stmt = $pdo->query("SHOW COLUMNS FROM `pre_enrollments` LIKE 'previous_college'");
    if ($stmt->rowCount() == 0) {
        $pdo->exec("ALTER TABLE `pre_enrollments` ADD COLUMN `previous_college` VARCHAR(255) DEFAULT NULL AFTER `senior_high_school`");
        echo "Successfully added previous_college column to pre_enrollments.\n";
    } else {
        echo "previous_college column already exists in pre_enrollments.\n";
    }
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
