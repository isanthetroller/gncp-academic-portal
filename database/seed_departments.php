<?php
/**
 * Seed missing departments into the active database
 */

require_once __DIR__ . '/../shared/backend/config/database.php';

try {
    $pdo = Database::getInstance();
    
    echo "Seeding departments table...\n";
    
    $departments = [
        ['code' => 'CCS', 'name' => 'College of Computer Studies', 'status' => 'Active'],
        ['code' => 'CHS', 'name' => 'College of Health Sciences', 'status' => 'Active'],
        ['code' => 'COB', 'name' => 'College of Business', 'status' => 'Active'],
        ['code' => 'GED', 'name' => 'General Education', 'status' => 'Active'],
        ['code' => 'NSTP', 'name' => 'National Service Training Program', 'status' => 'Active'],
        ['code' => 'PE', 'name' => 'Physical Education', 'status' => 'Active']
    ];
    
    $stmt = $pdo->prepare("
        INSERT INTO `departments` (`code`, `name`, `status`) 
        VALUES (:code, :name, :status)
        ON DUPLICATE KEY UPDATE `status` = VALUES(`status`)
    ");
    
    $insertedCount = 0;
    foreach ($departments as $dept) {
        $stmt->execute($dept);
        $insertedCount++;
        echo "Seeded department: {$dept['code']} - {$dept['name']}\n";
    }
    
    echo "Successfully seeded $insertedCount departments.\n";
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
