<?php
/**
 * Migration: Create Financial Ledger & Student Clearance Relational Tables
 *
 * Creates first-class MariaDB relational tables for:
 * 1. `payments` - Individual immutable payment transactions
 * 2. `official_receipts` - Official receipt ledger
 * 3. `student_clearances` - Relational station workflow clearances
 */

require_once __DIR__ . '/../../shared/backend/config/database.php';

try {
    $pdo = Database::getInstance();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    echo "Executing Database Migration: create_financial_and_clearance_tables...\n";

    // 1. Create `payments` table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `payments` (
            `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
            `student_reference` VARCHAR(50) NOT NULL,
            `student_id` VARCHAR(50) NULL,
            `amount` DECIMAL(10, 2) NOT NULL,
            `payment_method` VARCHAR(50) NOT NULL,
            `payment_type` VARCHAR(50) NOT NULL DEFAULT 'DOWNPAYMENT',
            `channel` VARCHAR(50) NOT NULL DEFAULT 'CASH',
            `transaction_reference` VARCHAR(100) NOT NULL,
            `official_receipt_number` VARCHAR(50) NULL,
            `status` VARCHAR(30) NOT NULL DEFAULT 'COMPLETED',
            `cashier_username` VARCHAR(50) NOT NULL,
            `notes` TEXT NULL,
            `paid_at` DATETIME NOT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_student_reference` (`student_reference`),
            INDEX `idx_student_id` (`student_id`),
            INDEX `idx_transaction_reference` (`transaction_reference`),
            INDEX `idx_or_number` (`official_receipt_number`),
            INDEX `idx_paid_at` (`paid_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
    echo "  ✓ Table `payments` created or verified.\n";

    // 2. Create `official_receipts` table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `official_receipts` (
            `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
            `or_number` VARCHAR(50) NOT NULL UNIQUE,
            `student_reference` VARCHAR(50) NOT NULL,
            `student_name` VARCHAR(150) NOT NULL,
            `amount` DECIMAL(10, 2) NOT NULL,
            `payment_id` BIGINT NULL,
            `cashier_username` VARCHAR(50) NOT NULL,
            `issued_at` DATETIME NOT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_or_student_ref` (`student_reference`),
            INDEX `idx_issued_at` (`issued_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
    echo "  ✓ Table `official_receipts` created or verified.\n";

    // 3. Create `student_clearances` table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `student_clearances` (
            `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
            `student_reference` VARCHAR(50) NOT NULL,
            `station_code` VARCHAR(50) NOT NULL,
            `status` VARCHAR(30) NOT NULL DEFAULT 'PENDING',
            `verified_by` VARCHAR(50) NULL,
            `notes` TEXT NULL,
            `clearance_data` LONGTEXT NULL,
            `cleared_at` DATETIME NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `uk_student_station` (`student_reference`, `station_code`),
            INDEX `idx_station_status` (`station_code`, `status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
    echo "  ✓ Table `student_clearances` created or verified.\n";

    echo "Migration completed successfully!\n";
} catch (Exception $e) {
    echo "❌ Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
