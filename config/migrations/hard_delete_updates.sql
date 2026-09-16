-- Hard-Delete Updates Migration
-- Smart ICT Helpdesk System - Mutare City Council
--
-- Old behaviour: deleting a status update/resolution only soft-deleted the
-- record (deleted_at set, row kept).
-- New behaviour: the record is PERMANENTLY deleted, but a snapshot of the
-- deleted content is stored in ticket_update_deletions.details for auditing.

USE mcc_helpdesk;

ALTER TABLE ticket_update_deletions
    ADD COLUMN details TEXT NULL COMMENT 'Snapshot of deleted content for the audit trail' AFTER record_id;