<?php
require_once '../config/auth_helper.php';
require_once '../config/database.php';
require_once '../config/NotificationService.php';

requireRole('technician');

$database = new Database();
$conn = $database->getConnection();
$notifications = new NotificationService();

$user_id = $_SESSION['user_id'];

$technician = $conn->query("SELECT * FROM technicians WHERE id = $user_id")->fetch_assoc();

if (!$technician) {
    $user_name = $conn->real_escape_string($_SESSION['user_name']);
    $technician = $conn->query("SELECT * FROM technicians WHERE name = '$user_name' LIMIT 1")->fetch_assoc();
}

if (!$technician) {
    $_SESSION['error'] = 'Technician profile not found. Please contact administrator.';
    header('Location: ../index.php');
    exit();
}

$tech_id = $technician['id'];

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    $ticket_id = (int)$_POST['ticket_id'];
    $ticket = $conn->query("SELECT * FROM tickets WHERE id = $ticket_id")->fetch_assoc();
    
    if (!$ticket) {
        $_SESSION['error'] = 'Ticket not found';
        header('Location: technician_queue.php');
        exit();
    }
    
    switch ($_POST['action']) {
        case 'claim':
            if ($ticket['assigned_to']) {
                $_SESSION['error'] = 'Ticket already assigned';
                header('Location: technician_queue.php');
                exit();
            }
            
            $stmt = $conn->prepare("UPDATE tickets SET assigned_to = ?, status = 'in_progress', updated_at = NOW() WHERE id = ?");
            $stmt->bind_param("ii", $tech_id, $ticket_id);
            $stmt->execute();
            $stmt->close();
            
            $stmt = $conn->prepare("UPDATE technicians SET current_workload = current_workload + 1 WHERE id = ?");
            $stmt->bind_param("i", $tech_id);
            $stmt->execute();
            $stmt->close();
            
            $stmt = $conn->prepare("INSERT INTO ticket_assignments (ticket_id, technician_id, status) VALUES (?, ?, 'active')");
            $stmt->bind_param("ii", $ticket_id, $tech_id);
            $stmt->execute();
            $stmt->close();
            
            logActivity('CLAIM_TICKET', "Technician claimed ticket #$ticket_id");
            header('Location: technician_queue.php?claimed=1');
            exit();
            
        case 'update_status':
            $new_status = $_POST['new_status'];
            $solution = isset($_POST['solution']) ? trim($_POST['solution']) : '';
            
            if (!in_array($new_status, ['in_progress', 'resolved'])) {
                $_SESSION['error'] = 'Invalid status';
                header('Location: technician_queue.php');
                exit();
            }
            
            if ($ticket['assigned_to'] != $tech_id) {
                $_SESSION['error'] = 'Ticket not assigned to you';
                header('Location: technician_queue.php');
                exit();
            }
            
            $stmt = $conn->prepare("UPDATE tickets SET status = ?, updated_at = NOW() WHERE id = ?");
            $stmt->bind_param("si", $new_status, $ticket_id);
            $stmt->execute();
            $stmt->close();
            
            if ($new_status == 'resolved') {
                $resolution_time = $conn->query("SELECT TIMESTAMPDIFF(MINUTE, created_at, NOW()) as mins FROM tickets WHERE id = $ticket_id")->fetch_assoc()['mins'];
                
                $solution_escaped = $conn->real_escape_string($solution);
                $stmt = $conn->prepare("INSERT INTO fault_history (ticket_id, problem, solution, resolved_by, resolved_at, time_to_resolve) VALUES (?, ?, ?, ?, NOW(), ?)");
                $stmt->bind_param("isssi", $ticket_id, $ticket['title'], $solution_escaped, $tech_id, $resolution_time);
                $stmt->execute();
                $stmt->close();
                
                $stmt = $conn->prepare("UPDATE technicians SET current_workload = GREATEST(current_workload - 1, 0), status = CASE WHEN current_workload >= 4 THEN 'busy' WHEN status = 'offline' THEN 'offline' ELSE 'available' END WHERE id = ?");
                $stmt->bind_param("i", $tech_id);
                $stmt->execute();
                $stmt->close();
            }
            
            $notifications->notifyTicketUpdated($ticket_id, $user_id, $ticket['status'], $new_status);
            logActivity('UPDATE_STATUS', "Technician updated ticket #$ticket_id to $new_status");
            
            $_SESSION['success'] = 'Ticket status updated';
            header('Location: technician_queue.php');
            exit();
    }
}

header('Location: technician_queue.php');
exit();
