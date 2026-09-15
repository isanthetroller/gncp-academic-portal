-- Migration to add email and must_change_password fields to station_users table

ALTER TABLE `station_users` 
ADD COLUMN IF NOT EXISTS `email` VARCHAR(150) NULL AFTER `name`,
ADD COLUMN IF NOT EXISTS `must_change_password` TINYINT(1) NOT NULL DEFAULT 1 AFTER `status`;
