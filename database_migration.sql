-- MySQL Database Migration Script
-- Updates the gncp_portal database schema to support live JSON columns for validation stations.

USE `gncp_portal`;

DELIMITER //

CREATE PROCEDURE AddColumnsIfNotExist()
BEGIN
    -- Add requirements_data
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS 
        WHERE TABLE_SCHEMA = 'gncp_portal' 
          AND TABLE_NAME = 'pre_enrollments' 
          AND COLUMN_NAME = 'requirements_data'
    ) THEN
        ALTER TABLE `pre_enrollments` ADD COLUMN `requirements_data` JSON DEFAULT NULL;
    END IF;

    -- Add medical_data
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS 
        WHERE TABLE_SCHEMA = 'gncp_portal' 
          AND TABLE_NAME = 'pre_enrollments' 
          AND COLUMN_NAME = 'medical_data'
    ) THEN
        ALTER TABLE `pre_enrollments` ADD COLUMN `medical_data` JSON DEFAULT NULL;
    END IF;

    -- Add scholarship_data
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS 
        WHERE TABLE_SCHEMA = 'gncp_portal' 
          AND TABLE_NAME = 'pre_enrollments' 
          AND COLUMN_NAME = 'scholarship_data'
    ) THEN
        ALTER TABLE `pre_enrollments` ADD COLUMN `scholarship_data` JSON DEFAULT NULL;
    END IF;

    -- Add payment_data
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS 
        WHERE TABLE_SCHEMA = 'gncp_portal' 
          AND TABLE_NAME = 'pre_enrollments' 
          AND COLUMN_NAME = 'payment_data'
    ) THEN
        ALTER TABLE `pre_enrollments` ADD COLUMN `payment_data` JSON DEFAULT NULL;
    END IF;

    -- Add helpdesk_data
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS 
        WHERE TABLE_SCHEMA = 'gncp_portal' 
          AND TABLE_NAME = 'pre_enrollments' 
          AND COLUMN_NAME = 'helpdesk_data'
    ) THEN
        ALTER TABLE `pre_enrollments` ADD COLUMN `helpdesk_data` JSON DEFAULT NULL;
    END IF;

    -- Add section_code
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS 
        WHERE TABLE_SCHEMA = 'gncp_portal' 
          AND TABLE_NAME = 'pre_enrollments' 
          AND COLUMN_NAME = 'section_code'
    ) THEN
        ALTER TABLE `pre_enrollments` ADD COLUMN `section_code` VARCHAR(50) DEFAULT NULL COMMENT 'Subject section code assigned by Registrar';
    END IF;

    -- Add or_number
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS 
        WHERE TABLE_SCHEMA = 'gncp_portal' 
          AND TABLE_NAME = 'pre_enrollments' 
          AND COLUMN_NAME = 'or_number'
    ) THEN
        ALTER TABLE `pre_enrollments` ADD COLUMN `or_number` VARCHAR(30) DEFAULT NULL COMMENT 'Official Receipt number issued by Cashier';
    END IF;

    -- Add enrolled_at
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS 
        WHERE TABLE_SCHEMA = 'gncp_portal' 
          AND TABLE_NAME = 'pre_enrollments' 
          AND COLUMN_NAME = 'enrolled_at'
    ) THEN
        ALTER TABLE `pre_enrollments` ADD COLUMN `enrolled_at` DATETIME DEFAULT NULL COMMENT 'Timestamp when Cashier stamped as enrolled';
    END IF;

    -- Add cashier_name
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS 
        WHERE TABLE_SCHEMA = 'gncp_portal' 
          AND TABLE_NAME = 'pre_enrollments' 
          AND COLUMN_NAME = 'cashier_name'
    ) THEN
        ALTER TABLE `pre_enrollments` ADD COLUMN `cashier_name` VARCHAR(100) DEFAULT NULL COMMENT 'Cashier who processed payment';
    END IF;

    -- Add curriculum_version to curriculum table
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS 
        WHERE TABLE_SCHEMA = 'gncp_portal' 
          AND TABLE_NAME = 'curriculum' 
          AND COLUMN_NAME = 'curriculum_version'
    ) THEN
        ALTER TABLE `curriculum` ADD COLUMN `curriculum_version` VARCHAR(100) DEFAULT '2022 Curriculum';
    END IF;
END //

DELIMITER ;

-- Execute and drop the procedure
CALL AddColumnsIfNotExist();
DROP PROCEDURE AddColumnsIfNotExist;

