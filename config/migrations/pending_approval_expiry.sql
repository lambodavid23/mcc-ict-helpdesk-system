-- Pending Approval Expiry Migration
-- Smart ICT Helpdesk System

USE mcc_helpdesk;

-- Add pending_expires_at column to users table (if not already present)
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = 'mcc_helpdesk' AND TABLE_NAME = 'users' AND COLUMN_NAME = 'pending_expires_at');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE users ADD COLUMN pending_expires_at DATETIME NULL AFTER status', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Update notifications type column if it's an ENUM that doesn't allow 'account_pending'
SET @type_check = (SELECT DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = 'mcc_helpdesk' AND TABLE_NAME = 'notifications' AND COLUMN_NAME = 'type');
SET @type_col = IF(@type_check = 'enum', 'ALTER TABLE notifications MODIFY COLUMN type VARCHAR(50) NOT NULL', 'SELECT 1');
PREPARE stmt2 FROM @type_col;
EXECUTE stmt2;
DEALLOCATE PREPARE stmt2;