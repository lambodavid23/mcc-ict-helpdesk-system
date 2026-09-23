<?php
/**
 * Automatic Technician Assignment System
 * Smart ICT Helpdesk System - Mutare City Council
 */

require_once '../config/database.php';

// Ensure the technician_attendance table exists (on-duty availability for assignment)
require_once '../config/AttendanceService.php';
new AttendanceService();

/**
 * Automatically assign a technician to a new ticket
 * @param int $ticket_id The ID of the ticket to assign
 * @param string $category The category of the ticket
 * @return bool True if assignment was successful, false otherwise
 */
function autoAssignTechnician($ticket_id, $category) {
    $database = new Database();
    $conn = $database->getConnection();
    
    try {
        // Sanitize inputs
        $ticket_id = (int)$ticket_id;
        $category = $conn->real_escape_string($category);
        
        // Find the best technician based on:
        // 1. Specialization match (exact category match preferred over general)
        // 2. Current workload (lowest first)
        // 3. Availability status (must be checked in / on duty for today)
        
        $assignment_query = "
            SELECT t.id, t.name, t.current_workload, t.status,
                   CASE 
                       WHEN t.specialization = '$category' THEN 1
                       WHEN t.specialization = 'general' THEN 2
                       ELSE 3
                   END as specialization_priority
            FROM technicians t
            INNER JOIN technician_attendance ta 
                ON ta.technician_id = t.id 
                AND ta.work_date = CURDATE() 
                AND ta.clock_out IS NULL
            WHERE t.status = 'available'
            ORDER BY 
                specialization_priority ASC,
                t.current_workload ASC,
                t.id ASC
            LIMIT 1
        ";
        
        $result = $conn->query($assignment_query);
        
        if ($result && $result->num_rows > 0) {
            $technician = $result->fetch_assoc();
            $tech_id = $technician['id'];
            
            // Start transaction
            $conn->begin_transaction();
            
            try {
                // Remember who currently holds this ticket so their workload can be
                // released if this is a reassignment.
                $old_tech_id = 0;
                $old_tech_result = $conn->query("SELECT assigned_to FROM tickets WHERE id = $ticket_id");
                if ($old_tech_result && $old_tech_result->num_rows > 0) {
                    $old_tech_id = (int)$old_tech_result->fetch_assoc()['assigned_to'];
                }
                
                // Update ticket with assigned technician
                $update_ticket_query = "
                    UPDATE tickets 
                    SET assigned_to = $tech_id, status = 'in_progress'
                    WHERE id = $ticket_id
                ";
                
                if (!$conn->query($update_ticket_query)) {
                    throw new Exception("Failed to update ticket");
                }
                
                // Update technician workload
                $update_tech_query = "
                    UPDATE technicians 
                    SET current_workload = current_workload + 1,
                        status = CASE 
                                    WHEN current_workload >= 4 THEN 'busy'
                                    WHEN status = 'offline' THEN 'offline'
                                    ELSE 'available'
                                 END
                    WHERE id = $tech_id
                ";
                
                if (!$conn->query($update_tech_query)) {
                    throw new Exception("Failed to update technician workload");
                }
                
                // Release the previous technician (reassignment case) so workload
                // counters stay accurate and no technician is unfairly skipped.
                if ($old_tech_id > 0 && $old_tech_id != $tech_id) {
                    $release_query = "
                        UPDATE technicians 
                        SET current_workload = GREATEST(current_workload - 1, 0),
                            status = CASE 
                                        WHEN current_workload >= 4 THEN 'busy'
                                        WHEN status = 'offline' THEN 'offline'
                                        ELSE 'available'
                                     END
                        WHERE id = $old_tech_id
                    ";
                    
                    if (!$conn->query($release_query)) {
                        throw new Exception("Failed to release previous technician workload");
                    }
                }
                
                // Create assignment record
                $assignment_notes = "Auto-assigned based on $category specialization and current workload";
                $insert_assignment_query = "
                    INSERT INTO ticket_assignments (ticket_id, technician_id, status, notes)
                    VALUES ($ticket_id, $tech_id, 'active', '" . $conn->real_escape_string($assignment_notes) . "')
                ";
                
                if (!$conn->query($insert_assignment_query)) {
                    throw new Exception("Failed to create assignment record");
                }
                
                // Log the assignment
                $log_query = "
                    INSERT INTO system_logs (action, description, ip_address)
                    VALUES ('AUTO_ASSIGN', 'Ticket #$ticket_id auto-assigned to technician {$technician['name']}', '127.0.0.1')
                ";
                
                $conn->query($log_query);
                
                // Commit transaction
                $conn->commit();
                
                // Log success for debugging
                error_log("Auto-assignment successful: Ticket #$ticket_id assigned to {$technician['name']} (ID: $tech_id)");
                
                return true;
                
            } catch (Exception $e) {
                // Rollback transaction on error
                $conn->rollback();
                error_log("Auto-assignment failed: " . $e->getMessage());
                return false;
            }
            
        } else {
            // No available technicians found
            error_log("Auto-assignment failed: No available technicians for ticket #$ticket_id in category $category");
            
            // Log the issue
            $log_query = "
                INSERT INTO system_logs (action, description, ip_address)
                VALUES ('AUTO_ASSIGN_FAILED', 'No available technicians for ticket #$ticket_id in category $category', '127.0.0.1')
            ";
            
            $conn->query($log_query);
            
            return false;
        }
        
    } catch (Exception $e) {
        error_log("Auto-assignment error: " . $e->getMessage());
        return false;
    }
}

/**
 * Reassign tickets when a technician becomes unavailable
 * @param int $technician_id The ID of the technician who became unavailable
 * @return int Number of tickets reassigned
 */
function reassignTechnicianTickets($technician_id) {
    $database = new Database();
    $conn = $database->getConnection();
    
    $reassigned_count = 0;
    
    try {
        $technician_id = (int)$technician_id;
        
        // Get active tickets for this technician
        $tickets_query = "
            SELECT id, category 
            FROM tickets 
            WHERE assigned_to = $technician_id 
            AND status IN ('open', 'in_progress')
        ";
        
        $result = $conn->query($tickets_query);
        
        if ($result && $result->num_rows > 0) {
            while ($ticket = $result->fetch_assoc()) {
                if (autoAssignTechnician($ticket['id'], $ticket['category'])) {
                    $reassigned_count++;
                }
            }
        }
        
        return $reassigned_count;
        
    } catch (Exception $e) {
        error_log("Ticket reassignment error: " . $e->getMessage());
        return 0;
    }
}

/**
 * Get technician workload statistics
 * @return array Workload statistics for all technicians
 */
function getTechnicianWorkloadStats() {
    $database = new Database();
    $conn = $database->getConnection();
    
    try {
        $stats_query = "
            SELECT 
                t.id,
                t.name,
                t.specialization,
                t.status,
                t.current_workload,
                COUNT(CASE WHEN tk.status IN ('open', 'in_progress') THEN 1 END) as active_tickets,
                COUNT(CASE WHEN tk.status = 'resolved' THEN 1 END) as resolved_tickets,
                AVG(CASE WHEN fh.time_to_resolve IS NOT NULL THEN fh.time_to_resolve END) as avg_resolution_time
            FROM technicians t
            LEFT JOIN tickets tk ON t.id = tk.assigned_to
            LEFT JOIN fault_history fh ON tk.id = fh.ticket_id
            GROUP BY t.id, t.name, t.specialization, t.status, t.current_workload
            ORDER BY t.name
        ";
        
        $result = $conn->query($stats_query);
        
        $stats = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $stats[] = $row;
            }
        }
        
        return $stats;
        
    } catch (Exception $e) {
        error_log("Workload stats error: " . $e->getMessage());
        return [];
    }
}

/**
 * Balance workload among technicians
 * @return bool True if rebalancing was successful
 */
function balanceWorkload() {
    $database = new Database();
    $conn = $database->getConnection();
    
    try {
        // Get technicians with their current workload
        $technicians_query = "
            SELECT id, name, specialization, current_workload, status
            FROM technicians
            WHERE status != 'offline'
            ORDER BY current_workload DESC
        ";
        
        $result = $conn->query($technicians_query);
        
        if (!$result || $result->num_rows < 2) {
            return false; // Not enough technicians to balance
        }
        
        $technicians = [];
        while ($row = $result->fetch_assoc()) {
            $technicians[] = $row;
        }
        
        // Calculate average workload
        $total_workload = array_sum(array_column($technicians, 'current_workload'));
        $avg_workload = $total_workload / count($technicians);
        
        // Find technicians with workload significantly above average
        $rebalanced = false;
        foreach ($technicians as $tech) {
            if ($tech['current_workload'] > $avg_workload + 2) {
                // Try to reassign some tickets to less busy technicians
                $tickets_to_reassign = min(2, $tech['current_workload'] - (int)$avg_workload);
                
                for ($i = 0; $i < $tickets_to_reassign; $i++) {
                    // Get the oldest ticket for this technician
                    $ticket_query = "
                        SELECT id, category 
                        FROM tickets 
                        WHERE assigned_to = {$tech['id']} 
                        AND status = 'in_progress'
                        ORDER BY created_at ASC
                        LIMIT 1
                    ";
                    
                    $ticket_result = $conn->query($ticket_query);
                    
                    if ($ticket_result && $ticket_result->num_rows > 0) {
                        $ticket = $ticket_result->fetch_assoc();
                        
                        // Try to reassign to a less busy technician
                        if (autoAssignTechnician($ticket['id'], $ticket['category'])) {
                            $rebalanced = true;
                        }
                    }
                }
            }
        }
        
        return $rebalanced;
        
    } catch (Exception $e) {
        error_log("Workload balancing error: " . $e->getMessage());
        return false;
    }
}

/**
 * Check for overdue tickets and escalate if necessary
 * @return array Array of overdue tickets
 */
function checkOverdueTickets() {
    $database = new Database();
    $conn = $database->getConnection();
    
    try {
        // Define overdue thresholds (in hours)
        $thresholds = [
            'high' => 2,    // 2 hours for high priority
            'medium' => 8,  // 8 hours for medium priority
            'low' => 24     // 24 hours for low priority
        ];
        
        $overdue_tickets = [];
        
        foreach ($thresholds as $priority => $hours) {
            $overdue_query = "
                SELECT id, title, priority, assigned_to, created_at
                FROM tickets
                WHERE priority = '$priority'
                AND status IN ('open', 'in_progress')
                AND created_at < DATE_SUB(NOW(), INTERVAL $hours HOUR)
            ";
            
            $result = $conn->query($overdue_query);
            
            if ($result && $result->num_rows > 0) {
                while ($row = $result->fetch_assoc()) {
                    $row['overdue_hours'] = $hours;
                    $overdue_tickets[] = $row;
                }
            }
        }
        
        // Log overdue tickets
        if (!empty($overdue_tickets)) {
            $log_message = count($overdue_tickets) . " overdue tickets detected";
            $log_query = "
                INSERT INTO system_logs (action, description, ip_address)
                VALUES ('OVERDUE_CHECK', '$log_message', '127.0.0.1')
            ";
            $conn->query($log_query);
        }
        
        return $overdue_tickets;
        
    } catch (Exception $e) {
        error_log("Overdue check error: " . $e->getMessage());
        return [];
    }
}

// If this script is called directly (for testing or cron jobs)
if (basename($_SERVER['PHP_SELF']) == 'auto_assign.php') {
    // Check for command line arguments
    if (isset($argv[1])) {
        switch ($argv[1]) {
            case 'balance':
                echo "Balancing workload...\n";
                $balanced = balanceWorkload();
                echo $balanced ? "Workload balanced successfully.\n" : "No balancing needed or failed.\n";
                break;
                
            case 'overdue':
                echo "Checking overdue tickets...\n";
                $overdue = checkOverdueTickets();
                if (empty($overdue)) {
                    echo "No overdue tickets found.\n";
                } else {
                    echo count($overdue) . " overdue tickets found:\n";
                    foreach ($overdue as $ticket) {
                        echo "- Ticket #{$ticket['id']}: {$ticket['title']} ({$ticket['priority']} priority, {$ticket['overdue_hours']}h overdue)\n";
                    }
                }
                break;
                
            case 'stats':
                echo "Technician workload statistics:\n";
                $stats = getTechnicianWorkloadStats();
                foreach ($stats as $tech) {
                    echo "- {$tech['name']}: {$tech['current_workload']} tickets ({$tech['status']})\n";
                }
                break;
                
            default:
                echo "Usage: php auto_assign.php [balance|overdue|stats]\n";
        }
    }
}
?>
