-- ============================================================
--  GNCP Academic Portal — Schema Migration
--  Version: 2026-08-01 — Phase 3 Reliability Fixes
--
--  Run this in phpMyAdmin → SQL tab, or via:
--    mysql -u root gncp_portal < database/migration_2026_08_01.sql
-- ============================================================

USE `gncp_portal`;

-- Fix 3.4: Add student_id column to enrollments table for proper FK-based
-- deduplication. Replaces student name string as the unique key.
ALTER TABLE `enrollments`
    ADD COLUMN IF NOT EXISTS `student_id` VARCHAR(50) NULL AFTER `id`;

-- Optional: Backfill existing rows by matching student name to students.id
-- UPDATE `enrollments` e
--     JOIN `students` s ON s.name = e.student
--     SET e.student_id = s.id
-- WHERE e.student_id IS NULL;

-- Verification: confirm column exists
SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = 'gncp_portal'
  AND TABLE_NAME   = 'enrollments'
ORDER BY ORDINAL_POSITION;
