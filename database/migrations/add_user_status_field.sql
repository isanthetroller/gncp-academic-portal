-- Migration to ensure user status column exists in station_users
-- status is already part of the default database schema.sql

ALTER TABLE `station_users` ADD COLUMN IF NOT EXISTS `status` VARCHAR(20) DEFAULT 'ACTIVE' AFTER `role`;

-- Ensure all existing staff accounts without an explicit status are activated
UPDATE `station_users` SET `status` = 'ACTIVE' WHERE `status` IS NULL OR `status` = '' OR `status` = 'PENDING' AND `username` IN ('admin', 'it_officer', 'kriz', 'tristan', 'ethan', 'cashier');

