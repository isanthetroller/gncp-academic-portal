USE `gncp_portal`;

-- Courses Directory Table
CREATE TABLE IF NOT EXISTS `courses` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `code` VARCHAR(20) UNIQUE NOT NULL,
    `name` VARCHAR(150) NOT NULL,
    `department` VARCHAR(150) NOT NULL,
    `credits` INT NOT NULL,
    `semester` VARCHAR(10) NOT NULL,
    `status` VARCHAR(20) DEFAULT 'Active'
) ENGINE=InnoDB;

-- Pre-seed Courses if empty
INSERT INTO `courses` (`id`, `code`, `name`, `department`, `credits`, `semester`, `status`)
SELECT 1, 'IT101', 'Introduction to Computing', 'Information Technology', 3, '1st', 'Active'
WHERE NOT EXISTS (SELECT 1 FROM `courses` WHERE `id` = 1);

INSERT INTO `courses` (`id`, `code`, `name`, `department`, `credits`, `semester`, `status`)
SELECT 2, 'ENG201', 'Technical Writing', 'English', 3, '2nd', 'Active'
WHERE NOT EXISTS (SELECT 1 FROM `courses` WHERE `id` = 2);

INSERT INTO `courses` (`id`, `code`, `name`, `department`, `credits`, `semester`, `status`)
SELECT 3, 'MTH301', 'Statistics for Research', 'Mathematics', 4, '3rd', 'Pending'
WHERE NOT EXISTS (SELECT 1 FROM `courses` WHERE `id` = 3);


-- Students Directory Table
CREATE TABLE IF NOT EXISTS `students` (
    `id` VARCHAR(50) PRIMARY KEY, -- Permanent Student ID
    `name` VARCHAR(150) NOT NULL,
    `program` VARCHAR(150) NOT NULL,
    `email` VARCHAR(150) DEFAULT NULL,
    `password` VARCHAR(255) DEFAULT NULL,
    `photo` VARCHAR(255) DEFAULT NULL,
    `year_level` VARCHAR(50) DEFAULT '1st Year',
    `status` VARCHAR(20) DEFAULT 'Active',
    `temp_reference_no` VARCHAR(50) DEFAULT NULL,
    `personal_info` TEXT DEFAULT NULL,
    `academic_info` TEXT DEFAULT NULL,
    `roadmap` TEXT DEFAULT NULL,
    `requirements_data` TEXT DEFAULT NULL,
    `medical_data` TEXT DEFAULT NULL,
    `scholarship_data` TEXT DEFAULT NULL,
    `payment_data` TEXT DEFAULT NULL,
    `helpdesk_data` TEXT DEFAULT NULL,
    `enrollment_data` TEXT DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Pre-seed Students if empty
INSERT INTO `students` (`id`, `name`, `program`, `year_level`, `status`)
SELECT '2026-1001', 'Maria Santos', 'BS Information Technology', '1st Year', 'Active'
WHERE NOT EXISTS (SELECT 1 FROM `students` WHERE `id` = '2026-1001');

INSERT INTO `students` (`id`, `name`, `program`, `year_level`, `status`)
SELECT '2026-1002', 'Alicia Reyes', 'BS Nursing', '2nd Year', 'Active'
WHERE NOT EXISTS (SELECT 1 FROM `students` WHERE `id` = '2026-1002');


-- Class Sections Table
CREATE TABLE IF NOT EXISTS `sections` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `code` VARCHAR(50) UNIQUE NOT NULL,
    `course` VARCHAR(150) NOT NULL,
    `instructor` VARCHAR(150) NOT NULL,
    `capacity` VARCHAR(20) NOT NULL,
    `status` VARCHAR(20) DEFAULT 'Open'
) ENGINE=InnoDB;

-- Pre-seed Sections if empty
INSERT INTO `sections` (`id`, `code`, `course`, `instructor`, `capacity`, `status`)
SELECT 1, 'IT-101', 'Introduction to Computing', 'Prof. Ramos', '40/40', 'Open'
WHERE NOT EXISTS (SELECT 1 FROM `sections` WHERE `id` = 1);

INSERT INTO `sections` (`id`, `code`, `course`, `instructor`, `capacity`, `status`)
SELECT 2, 'NU-204', 'Fundamentals of Nursing', 'Prof. Garcia', '35/40', 'Open'
WHERE NOT EXISTS (SELECT 1 FROM `sections` WHERE `id` = 2);


-- Registrar Enrollment Snapshot Table
CREATE TABLE IF NOT EXISTS `enrollments` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `student` VARCHAR(150) NOT NULL,
    `course` VARCHAR(150) NOT NULL,
    `status` VARCHAR(20) DEFAULT 'Enrolled',
    `updated` VARCHAR(50) NOT NULL
) ENGINE=InnoDB;

-- Pre-seed Enrollments if empty
INSERT INTO `enrollments` (`id`, `student`, `course`, `status`, `updated`)
SELECT 1, 'Ariana Bautista', 'BS Nursing', 'Enrolled', '10 mins ago'
WHERE NOT EXISTS (SELECT 1 FROM `enrollments` WHERE `id` = 1);

INSERT INTO `enrollments` (`id`, `student`, `course`, `status`, `updated`)
SELECT 2, 'John Rivera', 'BS Information Technology', 'Pending', '1 hr ago'
WHERE NOT EXISTS (SELECT 1 FROM `enrollments` WHERE `id` = 2);
