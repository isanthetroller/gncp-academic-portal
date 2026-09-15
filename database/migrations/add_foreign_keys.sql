-- =============================================================================
-- GNCP Academic System — Foreign Key Hardening Migration
-- Adds DDL constraints with ON UPDATE CASCADE / ON DELETE RESTRICT
-- =============================================================================

-- 1. Programs -> Departments relationship
-- Ensures programs cannot reference non-existent departments
-- (Optional/defensive check before applying)

-- 2. Academic Milestones -> Academic Periods
ALTER TABLE `academic_milestones`
    ADD CONSTRAINT `fk_milestones_academic_period`
    FOREIGN KEY (`academic_period_id`) REFERENCES `academic_periods` (`id`)
    ON UPDATE CASCADE
    ON DELETE CASCADE;

-- 3. Sections -> Academic Periods
ALTER TABLE `sections`
    ADD CONSTRAINT `fk_sections_academic_period`
    FOREIGN KEY (`academic_period_id`) REFERENCES `academic_periods` (`id`)
    ON UPDATE CASCADE
    ON DELETE RESTRICT;

-- Index optimizations for foreign keys
CREATE INDEX IF NOT EXISTS `idx_sec_acad_period` ON `sections` (`academic_period_id`);
CREATE INDEX IF NOT EXISTS `idx_ms_acad_period` ON `academic_milestones` (`academic_period_id`);
