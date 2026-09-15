-- ============================================================
--  MIGRATION: Add returning student fields to pre_enrollments
--  Run this ONCE on any existing database that already has
--  the pre_enrollments table. Safe to apply on live data.
--
--  Usage:
--    phpMyAdmin -> Import tab -> select this file -> Go
--  OR:
--    mysql -u root gncp_portal < add_returning_student_fields.sql
-- ============================================================

USE `gncp_portal`;

-- Add existing_student_id: references students.id for RETURNING type
ALTER TABLE `pre_enrollments`
    ADD COLUMN IF NOT EXISTS `existing_student_id` VARCHAR(50) DEFAULT NULL
        COMMENT 'RETURNING students only - references students.id';

-- Add year_level_applied: declared re-entry year level for Registrar review
ALTER TABLE `pre_enrollments`
    ADD COLUMN IF NOT EXISTS `year_level_applied` VARCHAR(50) DEFAULT NULL
        COMMENT 'Year level the student is re-enrolling at';

-- Index for faster Registrar/admin lookups by student ID
ALTER TABLE `pre_enrollments`
    ADD INDEX IF NOT EXISTS `idx_existing_student_id` (`existing_student_id`);

SELECT 'Migration complete: existing_student_id and year_level_applied added.' AS result;
