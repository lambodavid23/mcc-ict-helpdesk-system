<?php
/**
 * Ticket Action Service
 * Smart ICT Helpdesk System - Mutare City Council
 *
 * Central rules for technician ticket updates:
 *  1. A status change ALWAYS requires a resolution/action note.
 *  2. A wrong status update or resolution can be permanently deleted,
 *     but only up to 2 times per day per technician.
 *  3. Deleting the newest status update reverts the ticket to its
 *     previous status; deleting an older one only removes that entry.
 *  4. Deleting a resolution reverts the ticket if it was the last one.
 *  5. Deleted content is snapshotted into ticket_update_deletions so the
 *     audit trail survives permanent deletion.
 */

require_once 'database.php';
require_once 'auth_helper.php';
require_once 'NotificationService.php';

class TicketActionService {
    const MAX_DELETIONS_PER_DAY = 2;
    const ALLOWED_STATUSES = ['open', 'in_progress', 'resolved'];

    private $conn;
    private $notifications;

    public function __construct() {
        $database = new Database();
        $this->conn = $database->getConnection();
        $this->notifications = new NotificationService();
    }

    /**
     * Change the status of a ticket. Resolution text is mandatory.
     *
     * A resolution only exists on a resolved ticket: moving a ticket to
     * 'resolved' stores the text in fault_history and releases the
     * technician's workload. Any other status change just records an
     * action-taken note on ticket_assignments, so the inconsistency
     * "in progress ticket with a resolution on it" can never occur.
     */
    public function updateStatus($ticket_id, $technician_id, $new_status, $resolution) {
        $resolution = trim($resolution);
        $ticket_id = (int)$ticket_id;
        $technician_id = (int)$technician_id;

        if (!in_array($new_status, self::ALLOWED_STATUSES)) {
            return ['success' => false, 'message' => 'Invalid status selected.'];
        }

        if ($resolution === '') {
            return ['success' => false, 'message' => 'You must enter a resolution / action taken before changing the status.'];
        }

        $result = $this->conn->query(
            "SELECT id, status, title, created_at FROM tickets WHERE id = $ticket_id AND assigned_to = $technician_id"
        );
        if (!$result || $result->num_rows == 0) {
            return ['success' => false, 'message' => 'Ticket not found or not assigned to you.'];
        }

        $ticket = $result->fetch_assoc();
        $old_status = $ticket['status'];

        $stmt = $this->conn->prepare("UPDATE tickets SET status = ?, updated_at = NOW() WHERE id = ?");
        $stmt->bind_param('si', $new_status, $ticket_id);
        $stmt->execute();
        $stmt->close();

        if ($new_status === 'resolved') {
            $resolution_time = $this->conn->query(
                "SELECT TIMESTAMPDIFF(MINUTE, created_at, NOW()) as mins FROM tickets WHERE id = $ticket_id"
            )->fetch_assoc()['mins'];

            $stmt = $this->conn->prepare(
                "INSERT INTO fault_history (ticket_id, problem, solution, resolved_by, resolved_at, time_to_resolve)
                 VALUES (?, ?, ?, ?, NOW(), ?)"
            );
            $stmt->bind_param('issii', $ticket_id, $ticket['title'], $resolution, $technician_id, $resolution_time);
            $stmt->execute();
            $stmt->close();

            $stmt = $this->conn->prepare(
                "UPDATE technicians SET current_workload = GREATEST(current_workload - 1, 0),
                 status = CASE WHEN current_workload <= 1 THEN 'available' ELSE status END
                 WHERE id = ?"
            );
            $stmt->bind_param('i', $technician_id);
            $stmt->execute();
            $stmt->close();
        }

        $notes = $this->conn->real_escape_string($resolution);
        $stmt = $this->conn->prepare(
            "INSERT INTO ticket_assignments (ticket_id, technician_id, status, notes, previous_status, new_status)
             VALUES (?, ?, 'active', ?, ?, ?)"
        );
        $stmt->bind_param('iisss', $ticket_id, $technician_id, $notes, $old_status, $new_status);
        $stmt->execute();
        $stmt->close();

        $this->notifications->notifyTicketUpdated($ticket_id, $technician_id, $old_status, $new_status);

        logActivity('UPDATE_TICKET_STATUS', "Technician updated ticket #$ticket_id status from $old_status to $new_status");
        return ['success' => true, 'message' => $new_status === 'resolved'
            ? 'Ticket resolved. Resolution has been recorded and the ticket is now marked as resolved.'
            : 'Ticket status updated successfully.'];
    }

    /**
     * Permanently delete a status update. Deleting the newest one reverts the
     * ticket status; deleting an older one leaves the status unchanged.
     */
    public function deleteStatusUpdate($ticket_id, $technician_id, $assignment_id) {
        $ticket_id = (int)$ticket_id;
        $technician_id = (int)$technician_id;
        $assignment_id = (int)$assignment_id;

        $result = $this->conn->query(
            "SELECT id, technician_id, previous_status, new_status, notes
             FROM ticket_assignments
             WHERE id = $assignment_id AND ticket_id = $ticket_id"
        );
        if (!$result || $result->num_rows == 0) {
            return ['success' => false, 'message' => 'Status update record not found.'];
        }

        $row = $result->fetch_assoc();

        if ((int)$row['technician_id'] !== $technician_id) {
            return ['success' => false, 'message' => 'You can only delete your own status updates.'];
        }

        $quota = $this->checkDeletionQuota($technician_id);
        if (!$quota['success']) {
            return $quota;
        }

        $latest = $this->conn->query(
            "SELECT MAX(id) as max_id FROM ticket_assignments
             WHERE ticket_id = $ticket_id AND previous_status IS NOT NULL"
        )->fetch_assoc();

        $isLatest = ((int)($latest['max_id'] ?? 0) === $assignment_id);

        $details = $row['previous_status'] !== null
            ? 'Status: ' . $row['previous_status'] . ' -> ' . $row['new_status']
            : 'Ticket claim/assignment';
        $details .= ($row['notes'] !== null ? "\nResolution: " . $row['notes'] : '');

        $stmt = $this->conn->prepare("DELETE FROM ticket_assignments WHERE id = ?");
        $stmt->bind_param('i', $assignment_id);
        $stmt->execute();
        $stmt->close();

        $ticket = $this->conn->query(
            "SELECT status FROM tickets WHERE id = $ticket_id"
        )->fetch_assoc();

        // A resolved ticket is only reverted to in_progress when no resolution
        // (fault_history record) backs it up anymore. Otherwise the deleting
        // technician would leave a resolution behind on an in_progress ticket.
        $remaining_resolutions = $this->conn->query(
            "SELECT COUNT(*) as c FROM fault_history WHERE ticket_id = $ticket_id AND deleted_at IS NULL"
        )->fetch_assoc();
        $resolution_still_exists = ((int)($remaining_resolutions['c'] ?? 0) > 0);

        $canRevert = $isLatest
            && $ticket
            && $row['new_status'] === $ticket['status']
            && $row['previous_status'] !== null
            && !($row['new_status'] === 'resolved' && $resolution_still_exists);

        if ($canRevert) {
            $stmt = $this->conn->prepare("UPDATE tickets SET status = ?, updated_at = NOW() WHERE id = ?");
            $stmt->bind_param('si', $row['previous_status'], $ticket_id);
            $stmt->execute();
            $stmt->close();
        }

        $this->recordDeletion($ticket_id, $technician_id, 'status_update', $assignment_id, $details);

        logActivity('DELETE_STATUS_UPDATE', "Technician deleted status update record #$assignment_id on ticket #$ticket_id");
        return ['success' => true, 'message' => $isLatest ? 'Status update permanently deleted and ticket status reverted.' : 'Status update permanently deleted. The ticket status was left unchanged.'];
    }

    /**
     * Permanently delete a resolution record. If it was the last resolution
     * for a resolved ticket, the ticket is moved back to in_progress.
     */
    public function deleteSolution($ticket_id, $technician_id, $history_id) {
        $ticket_id = (int)$ticket_id;
        $technician_id = (int)$technician_id;
        $history_id = (int)$history_id;

        $result = $this->conn->query(
            "SELECT id, resolved_by, problem, solution FROM fault_history
             WHERE id = $history_id AND ticket_id = $ticket_id"
        );
        if (!$result || $result->num_rows == 0) {
            return ['success' => false, 'message' => 'Resolution record not found.'];
        }

        $row = $result->fetch_assoc();

        if ((int)$row['resolved_by'] !== $technician_id) {
            return ['success' => false, 'message' => 'You can only delete your own resolutions.'];
        }

        $quota = $this->checkDeletionQuota($technician_id);
        if (!$quota['success']) {
            return $quota;
        }

        $ticket = $this->conn->query(
            "SELECT status FROM tickets WHERE id = $ticket_id"
        )->fetch_assoc();

        $remaining = $this->conn->query(
            "SELECT COUNT(*) as c FROM fault_history WHERE ticket_id = $ticket_id"
        )->fetch_assoc();
        $isLast = ((int)$remaining['c'] === 1);

        $details = 'Problem: ' . $row['problem']
                 . ($row['solution'] !== null ? "\nSolution: " . $row['solution'] : '');

        $stmt = $this->conn->prepare("DELETE FROM fault_history WHERE id = ?");
        $stmt->bind_param('i', $history_id);
        $stmt->execute();
        $stmt->close();

        if ($isLast && $ticket && $ticket['status'] === 'resolved') {
            $stmt = $this->conn->prepare("UPDATE tickets SET status = 'in_progress', updated_at = NOW() WHERE id = ?");
            $stmt->bind_param('i', $ticket_id);
            $stmt->execute();
            $stmt->close();

            $this->conn->query(
                "UPDATE technicians SET current_workload = current_workload + 1 WHERE id = $technician_id"
            );
        }

        $this->recordDeletion($ticket_id, $technician_id, 'solution', $history_id, $details);

        logActivity('DELETE_SOLUTION', "Technician deleted resolution record #$history_id on ticket #$ticket_id");
        return ['success' => true, 'message' => 'Resolution permanently deleted.'];
    }

    /**
     * Edit the text of one of your own solutions. No deletion quota involved.
     */
    public function editSolution($history_id, $technician_id, $solution) {
        $history_id = (int)$history_id;
        $technician_id = (int)$technician_id;
        $solution = trim($solution);

        if ($solution === '') {
            return ['success' => false, 'message' => 'Solution cannot be empty.'];
        }

        $stmt = $this->conn->prepare(
            "UPDATE fault_history SET solution = ? WHERE id = ? AND resolved_by = ? AND deleted_at IS NULL"
        );
        $stmt->bind_param('sii', $solution, $history_id, $technician_id);
        $stmt->execute();

        if ($stmt->affected_rows === 0) {
            $stmt->close();
            return ['success' => false, 'message' => 'Solution not found or you cannot edit it.'];
        }
        $stmt->close();

        logActivity('EDIT_SOLUTION', "Technician #$technician_id edited solution #$history_id");
        return ['success' => true, 'message' => 'Solution updated successfully.'];
    }

    /**
     * Check the 2-per-day deletion quota without consuming a slot.
     */
    private function checkDeletionQuota($technician_id) {
        $count = $this->conn->query(
            "SELECT COUNT(*) as c FROM ticket_update_deletions
             WHERE technician_id = " . (int)$technician_id . " AND created_at >= CURDATE()"
        )->fetch_assoc();

        if ((int)$count['c'] >= self::MAX_DELETIONS_PER_DAY) {
            return ['success' => false, 'message' => 'Daily limit of ' . self::MAX_DELETIONS_PER_DAY . ' deletions reached. Please contact an administrator.'];
        }

        return ['success' => true];
    }

    /**
     * Log a deletion (the permanent-deletion audit breadcrumb).
     */
    private function recordDeletion($ticket_id, $technician_id, $record_type, $record_id, $details) {
        $technician_id = (int)$technician_id;
        $ticket_id = (int)$ticket_id;
        $record_id = (int)$record_id;

        $stmt = $this->conn->prepare(
            "INSERT INTO ticket_update_deletions (technician_id, ticket_id, record_type, record_id, details)
             VALUES (?, ?, ?, ?, ?)"
        );
        $stmt->bind_param('iisis', $technician_id, $ticket_id, $record_type, $record_id, $details);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Number of deletions the technician can still perform today.
     */
    public function deletionsRemaining($technician_id) {
        $count = $this->conn->query(
            "SELECT COUNT(*) as c FROM ticket_update_deletions
             WHERE technician_id = " . (int)$technician_id . " AND created_at >= CURDATE()"
        )->fetch_assoc();

        return max(self::MAX_DELETIONS_PER_DAY - (int)$count['c'], 0);
    }
}