-- Technician Status Update Controls Migration
-- Smart ICT Helpdesk System - Mutare City Council
--
-- Adds:
--  1. Status update tracking (previous_status, new_status) on ticket_assignments
--     so a status change can be reverted when a wrong resolution is deleted.
--  2. Soft-delete columns on ticket_assignments and fault_history so deleted
--     updates are kept for the audit trail.
--  3. ticket_update_deletions table to enforce the 2-per-day delete quota.

USE mcc_helpdesk;

-- 1. Extend ticket_assignments to track status-change records
ALTER TABLE ticket_assignments
    ADD COLUMN previous_status VARCHAR(50) DEFAULT NULL AFTER notes,
    ADD COLUMN new_status VARCHAR(50) DEFAULT NULL AFTER previous_status,
    ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL AFTER new_status,
    ADD COLUMN deleted_by INT NULL DEFAULT NULL AFTER deleted_at;

-- 2. Soft-delete support on fault_history (resolutions)
ALTER TABLE fault_history
    ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL AFTER time_to_resolve,
    ADD COLUMN deleted_by INT NULL DEFAULT NULL AFTER deleted_at;

-- 3. Deletion quota/audit log
CREATE TABLE IF NOT EXISTS ticket_update_deletions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    technician_id INT NOT NULL,
    ticket_id INT NOT NULL,
    record_type ENUM('status_update', 'solution') NOT NULL,
    record_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (technician_id) REFERENCES technicians(id) ON DELETE CASCADE
);

CREATE INDEX idx_tud_technician_day ON ticket_update_deletions(technician_id, created_at);
CREATE INDEX idx_ta_status_updates ON ticket_assignments(ticket_id, previous_status);