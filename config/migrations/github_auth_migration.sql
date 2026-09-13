-- GitHub Authentication Migration
-- Run this SQL to add GitHub OAuth support to existing database

USE mcc_helpdesk;

-- Add github_id and avatar columns to users table
ALTER TABLE users ADD COLUMN github_id VARCHAR(50) NULL AFTER department;
ALTER TABLE users ADD COLUMN avatar VARCHAR(500) NULL AFTER github_id;

-- Add github_id and avatar columns to admins table
ALTER TABLE admins ADD COLUMN github_id VARCHAR(50) NULL AFTER department;
ALTER TABLE admins ADD COLUMN avatar VARCHAR(500) NULL AFTER github_id;

-- Add github_id and avatar columns to technicians table
ALTER TABLE technicians ADD COLUMN github_id VARCHAR(50) NULL AFTER department;
ALTER TABLE technicians ADD COLUMN avatar VARCHAR(500) NULL AFTER github_id;

-- Add indexes for faster lookups
ALTER TABLE users ADD INDEX idx_users_github_id (github_id);
ALTER TABLE admins ADD INDEX idx_admins_github_id (github_id);
ALTER TABLE technicians ADD INDEX idx_technicians_github_id (github_id);

-- Verify changes
SELECT 'GitHub authentication migration completed successfully!' as message;