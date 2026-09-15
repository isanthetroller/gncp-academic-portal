-- Create database if it doesn't exist
CREATE DATABASE IF NOT EXISTS `gncp_portal` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `gncp_portal`;

-- Pre-enrollments table for storing online wizard submissions
CREATE TABLE IF NOT EXISTS `pre_enrollments` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `temp_student_id` VARCHAR(50) UNIQUE NOT NULL,
    `temp_pin` VARCHAR(6) NOT NULL,
    
    -- Step 1: Program
    `student_type` VARCHAR(20) NOT NULL,
    `course_code` VARCHAR(20) NOT NULL,
    `nstp` VARCHAR(20) NOT NULL,
    
    -- Step 2: Personal details
    `first_name` VARCHAR(100) NOT NULL,
    `middle_name` VARCHAR(100) DEFAULT NULL,
    `last_name` VARCHAR(100) NOT NULL,
    `email` VARCHAR(150) NOT NULL,
    `phone` VARCHAR(15) NOT NULL,
    `birth_date` DATE NOT NULL,
    `gender` VARCHAR(20) NOT NULL,
    `address` TEXT NOT NULL,
    
    -- Step 3: Academic background
    `elementary_school` VARCHAR(255) NOT NULL,
    `junior_high_school` VARCHAR(255) NOT NULL,
    `senior_high_school` VARCHAR(255) NOT NULL,
    `shs_track` VARCHAR(50) DEFAULT NULL,
    `honors` VARCHAR(150) DEFAULT NULL,
    
    -- Step 4: Medical details
    `health_status` VARCHAR(20) NOT NULL,
    `medical_conditions` TEXT DEFAULT NULL, -- Stored as comma-separated or JSON array
    `allergies` VARCHAR(255) DEFAULT NULL,
    `current_medication` TINYINT(1) DEFAULT 0,
    `medication_details` VARCHAR(255) DEFAULT NULL,
    `fitness_participation` TINYINT(1) DEFAULT 1,
    `emergency_contact_name` VARCHAR(150) NOT NULL,
    `emergency_contact_phone` VARCHAR(15) NOT NULL,
    
    -- Step 5: Payment details
    `payment_mode` VARCHAR(20) NOT NULL,
    `scholarship` VARCHAR(30) NOT NULL,
    
    -- System status
    `status` VARCHAR(20) DEFAULT 'PRE_REGISTERED', -- PRE_REGISTERED, EVALUATED, ENROLLED
    `roadmap` TEXT DEFAULT NULL, -- JSON block mapping physical campus stations

    -- Station data blobs (JSON) — managed by admin/staff dashboards
    `requirements_data` TEXT DEFAULT NULL,  -- Registrar: document checklist & verification
    `medical_data`       TEXT DEFAULT NULL,  -- Clinic: physical exam & clearance flags
    `scholarship_data`   TEXT DEFAULT NULL,  -- Scholarship desk: grant verification
    `payment_data`       TEXT DEFAULT NULL,  -- Cashier: fee breakdown & payment status
    `helpdesk_data`      TEXT DEFAULT NULL,  -- Helpdesk/TLC: NSTP choice & walk-in flag

    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
