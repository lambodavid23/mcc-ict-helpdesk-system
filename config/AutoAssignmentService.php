<?php
/**
 * Auto-Assignment Service
 * Automatically assigns tickets to technicians based on rules
 */

require_once 'database.php';

class AutoAssignmentService {
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
        
        if ($rule['technician_id']) {
            $technician_id = $rule['technician_id'];
        } else {
            $technician_id = $this->findBestTechnician($rule['specialization']);
        }
        
        if (!$technician_id) return false;
        
        return $this->assignTicket($ticket_id, $technician_id);
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
    
    private function findBestTechnician($specialization) {
        $stmt = $this->conn->prepare(
            "SELECT id FROM technicians 
             WHERE status = 'available' 
             AND (specialization = ? OR specialization = 'general')
             AND current_workload < 10
             ORDER BY current_workload ASC, 
                      specialization = ? DESC
             LIMIT 1"
        );
        $stmt->bind_param("ss", $specialization, $specialization);
        $stmt->execute();
        $result = $stmt->get_result();
        $tech = $result->fetch_assoc();
        $stmt->close();
        
        if (!$tech) {
            $stmt = $this->conn->prepare(
                "SELECT id FROM technicians 
                 WHERE status IN ('available', 'busy')
                 AND current_workload < 15
                 ORDER BY current_workload ASC
                 LIMIT 1"
            );
            $stmt->execute();
            $result = $stmt->get_result();
            $tech = $result->fetch_assoc();
            $stmt->close();
        }
        
        return $tech ? $tech['id'] : null;
    }
    
    private function assignTicket($ticket_id, $technician_id) {
        $this->conn->begin_transaction();
        
        try {
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
                     status = CASE WHEN current_workload >= 4 THEN 'busy' ELSE status END
                 WHERE id = ?"
            );
            $stmt->bind_param("i", $technician_id);
            $stmt->execute();
            $stmt->close();
            
            $stmt = $this->conn->prepare(
                "INSERT INTO ticket_assignments (ticket_id, technician_id, status)
                 VALUES (?, ?, 'active')"
            );
            $stmt->bind_param("ii", $ticket_id, $technician_id);
            $stmt->execute();
            $stmt->close();
            
            $this->conn->commit();
            
            $this->notifyTechnician($ticket_id, $technician_id);
            
            return true;
        } catch (Exception $e) {
            $this->conn->rollback();
            error_log("Auto-assignment failed: " . $e->getMessage());
            return false;
        }
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
