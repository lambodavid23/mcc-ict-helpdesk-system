<?php
/**
 * Auto-Assignment Service
 * Automatically assigns tickets to technicians based on rules
 */

require_once 'database.php';

class AutoAssignmentService {
    // Workload caps: technicians above the override cap are never candidates,
    // and a rule-pinned technician must stay under the primary cap.
    const MAX_WORKLOAD_CAPACITY = 10;
    const MAX_WORKLOAD_OVERRIDE = 15;

    private $conn;
    private $database;
    
    public function __construct() {
        $this->database = new Database();
        $this->conn = $this->database->getConnection();
    }
    
    public function autoAssignTicket($ticket_id) {
        $ticket = $this->getTicket($ticket_id);
        if (!$ticket) return false;

        $rule = $this->findBestRule($ticket['category'], $ticket['priority']);
        if (!$rule) return false;

        // The whole pick-and-assign runs inside one transaction with locking
        // reads so two tickets submitted at the same time can never both grab
        // the same lowest-workload technician.
        $this->conn->begin_transaction();

        try {
            $technician_id = $this->selectTechnician($ticket, $rule);

            if (!$technician_id) {
                $this->conn->rollback();
                return false;
            }

            if (!$this->assignTicket($ticket, $technician_id)) {
                $this->conn->rollback();
                return false;
            }

            $this->conn->commit();
        } catch (Exception $e) {
            $this->conn->rollback();
            error_log("Auto-assignment failed: " . $e->getMessage());
            return false;
        }

        $this->notifyTechnician($ticket_id, $technician_id);
        return true;
    }

    /**
     * Choose the technician for a ticket. A rule-pinned technician is only
     * honored when they are actually eligible (on duty, available and under
     * the capacity cap); otherwise the normal best-fit search takes over.
     */
    private function selectTechnician($ticket, $rule) {
        $technician_id = (int)$rule['technician_id'];

        if ($technician_id > 0) {
            $stmt = $this->conn->prepare(
                "SELECT t.id FROM technicians t
                 INNER JOIN technician_attendance ta 
                     ON ta.technician_id = t.id 
                     AND ta.work_date = CURDATE() 
                     AND ta.clock_out IS NULL
                 WHERE t.id = ? 
                 AND t.status = 'available' 
                 AND t.current_workload < " . self::MAX_WORKLOAD_CAPACITY . "
                 FOR UPDATE"
            );
            $stmt->bind_param("i", $technician_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            $stmt->close();

            if ($row) {
                return (int)$row['id'];
            }
        }

        return $this->findBestTechnician($rule['specialization']);
    }
    
    private function getTicket($ticket_id) {
        $stmt = $this->conn->prepare(
            "SELECT * FROM tickets WHERE id = ?"
        );
        $stmt->bind_param("i", $ticket_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $ticket = $result->fetch_assoc();
        $stmt->close();
        return $ticket;
    }
    
    private function findBestRule($category, $priority) {
        $stmt = $this->conn->prepare(
            "SELECT * FROM assignment_rules 
             WHERE is_active = 1 
             AND auto_assign = 1 
             AND (category = ? OR category = 'all')
             AND (priority = ? OR priority = 'any')
             ORDER BY priority_order ASC
             LIMIT 1"
        );
        $stmt->bind_param("ss", $category, $priority);
        $stmt->execute();
        $result = $stmt->get_result();
        $rule = $result->fetch_assoc();
        $stmt->close();
        
        if (!$rule) {
            $stmt = $this->conn->prepare(
                "SELECT * FROM assignment_rules 
                 WHERE is_active = 1 
                 AND auto_assign = 1 
                 AND category = 'general'
                 ORDER BY priority_order ASC
                 LIMIT 1"
            );
            $stmt->execute();
            $result = $stmt->get_result();
            $rule = $result->fetch_assoc();
            $stmt->close();
        }
        
        return $rule;
    }

    /**
     * Best-fit technician search, executed inside the assignment transaction.
     * FOR UPDATE locks the chosen technician row so concurrent submissions
     * serialize on workload instead of double-picking the same technician.
     */
    private function findBestTechnician($specialization) {
        $stmt = $this->conn->prepare(
            "SELECT t.id FROM technicians t
             INNER JOIN technician_attendance ta 
                 ON ta.technician_id = t.id 
                 AND ta.work_date = CURDATE() 
                 AND ta.clock_out IS NULL
             WHERE t.status = 'available' 
             AND (t.specialization = ? OR t.specialization = 'general')
             AND t.current_workload < " . self::MAX_WORKLOAD_CAPACITY . "
             ORDER BY t.current_workload ASC, 
                      t.specialization = ? DESC
             LIMIT 1
             FOR UPDATE"
        );
        $stmt->bind_param("ss", $specialization, $specialization);
        $stmt->execute();
        $result = $stmt->get_result();
        $tech = $result->fetch_assoc();
        $stmt->close();
        
        if (!$tech) {
            // Relaxed fallback: also allow busy technicians, but still prefer a
            // specialization match before load so tickets go to the right skills.
            $stmt = $this->conn->prepare(
                "SELECT t.id FROM technicians t
                 INNER JOIN technician_attendance ta 
                     ON ta.technician_id = t.id 
                     AND ta.work_date = CURDATE() 
                     AND ta.clock_out IS NULL
                 WHERE t.status IN ('available', 'busy')
                 AND t.current_workload < " . self::MAX_WORKLOAD_OVERRIDE . "
                 ORDER BY 
                     CASE WHEN t.specialization = ? THEN 0 ELSE 1 END,
                     t.current_workload ASC
                 LIMIT 1
                 FOR UPDATE"
            );
            $stmt->bind_param("s", $specialization);
            $stmt->execute();
            $result = $stmt->get_result();
            $tech = $result->fetch_assoc();
            $stmt->close();
        }
        
        return $tech ? (int)$tech['id'] : null;
    }
    
    private function assignTicket($ticket, $technician_id) {
        $ticket_id = (int)$ticket['id'];
        $previous_technician = isset($ticket['assigned_to']) ? (int)$ticket['assigned_to'] : 0;

        $stmt = $this->conn->prepare(
            "UPDATE tickets 
             SET assigned_to = ?, status = 'in_progress', updated_at = NOW() 
             WHERE id = ?"
        );
        $stmt->bind_param("ii", $technician_id, $ticket_id);
        $stmt->execute();
        $stmt->close();
        
        $stmt = $this->conn->prepare(
            "UPDATE technicians 
             SET current_workload = current_workload + 1,
                 status = CASE 
                             WHEN current_workload >= 4 THEN 'busy'
                             WHEN status = 'offline' THEN 'offline'
                             ELSE 'available'
                          END
             WHERE id = ?"
        );
        $stmt->bind_param("i", $technician_id);
        $stmt->execute();
        $stmt->close();

        // Reassignment: release the previous technician's workload and mark
        // their old assignment as reassigned.
        if ($previous_technician > 0 && $previous_technician !== $technician_id) {
            $stmt = $this->conn->prepare(
                "UPDATE technicians 
                 SET current_workload = GREATEST(current_workload - 1, 0),
                     status = CASE 
                                 WHEN current_workload >= 4 THEN 'busy'
                                 WHEN status = 'offline' THEN 'offline'
                                 ELSE 'available'
                              END
                 WHERE id = ?"
            );
            $stmt->bind_param("i", $previous_technician);
            $stmt->execute();
            $stmt->close();

            $stmt = $this->conn->prepare(
                "UPDATE ticket_assignments 
                 SET status = 'cancelled' 
                 WHERE ticket_id = ? AND technician_id = ? AND status = 'active'"
            );
            $stmt->bind_param("ii", $ticket_id, $previous_technician);
            $stmt->execute();
            $stmt->close();
        }
        
        $stmt = $this->conn->prepare(
            "INSERT INTO ticket_assignments (ticket_id, technician_id, status)
             VALUES (?, ?, 'active')"
        );
        $stmt->bind_param("ii", $ticket_id, $technician_id);
        $stmt->execute();
        $stmt->close();
        
        return true;
    }
    
    private function notifyTechnician($ticket_id, $technician_id) {
        require_once 'NotificationService.php';
        $notifications = new NotificationService();
        $notifications->notifyTicketAssigned($ticket_id, $technician_id);
    }
    
    public function getAssignmentRules() {
        $result = $this->conn->query(
            "SELECT ar.*, t.name as technician_name 
             FROM assignment_rules ar 
             LEFT JOIN technicians t ON ar.technician_id = t.id 
             ORDER BY ar.priority_order"
        );
        
        $rules = [];
        while ($row = $result->fetch_assoc()) {
            $rules[] = $row;
        }
        return $rules;
    }
    
    public function updateRule($rule_id, $data) {
        $stmt = $this->conn->prepare(
            "UPDATE assignment_rules 
             SET category = ?, specialization = ?, priority = ?, 
                 technician_id = ?, auto_assign = ?, priority_order = ?, is_active = ?
             WHERE id = ?"
        );
        
        $stmt->bind_param("sssiiiii",
            $data['category'],
            $data['specialization'],
            $data['priority'],
            $data['technician_id'],
            $data['auto_assign'],
            $data['priority_order'],
            $data['is_active'],
            $rule_id
        );
        
        $result = $stmt->execute();
        $stmt->close();
        
        return $result;
    }
    
    public function getTechnicianStats() {
        $result = $this->conn->query(
            "SELECT 
                t.id,
                t.name,
                t.specialization,
                t.status,
                t.current_workload,
                COUNT(DISTINCT CASE WHEN tk.status IN ('open', 'in_progress') THEN tk.id END) as active_tickets,
                COUNT(DISTINCT CASE WHEN tk.status = 'resolved' THEN tk.id END) as resolved_tickets,
                AVG(fh.time_to_resolve) as avg_resolution_time
             FROM technicians t
             LEFT JOIN tickets tk ON t.id = tk.assigned_to
             LEFT JOIN fault_history fh ON tk.id = fh.ticket_id
             GROUP BY t.id"
        );
        
        $stats = [];
        while ($row = $result->fetch_assoc()) {
            $stats[] = $row;
        }
        return $stats;
    }
}
