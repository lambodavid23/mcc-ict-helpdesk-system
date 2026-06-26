<?php
/**
 * Notification Service
 * Handles email and in-app notifications
 */

require_once 'database.php';

class NotificationService {
    private $conn;
    private $database;
    
    public function __construct() {
        $this->database = new Database();
        $this->conn = $this->database->getConnection();
    }
    
    public function createNotification($user_id, $user_type, $ticket_id, $type, $title, $message = null) {
        $stmt = $this->conn->prepare(
            "INSERT INTO notifications (user_id, user_type, ticket_id, type, title, message) 
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param("isisss", $user_id, $user_type, $ticket_id, $type, $title, $message);
        $stmt->execute();
        $notification_id = $this->conn->insert_id;
        $stmt->close();
        
        $this->sendEmailNotification($user_id, $user_type, $title, $message, $ticket_id);
        
        return $notification_id;
    }
    
    private function sendEmailNotification($user_id, $user_type, $title, $message, $ticket_id) {
        $stmt = $this->conn->prepare("SELECT email, name FROM users WHERE id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        $stmt->close();
        
        if (!$user) return false;
        
        $email = $user['email'];
        $name = $user['name'];
        
        $ticket_url = $this->getTicketUrl($ticket_id, $user_type);
        $email_subject = "[MCC ICT] " . $title;
        $email_body = $this->getEmailTemplate($title, $message, $ticket_url, $name);
        
        return $this->sendEmail($email, $email_subject, $email_body);
    }
    
    private function sendEmail($to, $subject, $body) {
        $headers = [
            'From: MCC ICT Helpdesk <noreply@mcc.co.zw>',
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'X-Mailer: PHP/' . phpversion()
        ];
        
        $marked = mail($to, $subject, $body, implode("\r\n", $headers));
        
        return $marked;
    }
    
    private function getEmailTemplate($title, $message, $ticket_url, $name) {
        return '
        <!DOCTYPE html>
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; background: #050507; color: #e0e0e0; padding: 20px; }
                .container { max-width: 600px; margin: 0 auto; background: #0a0a0f; border: 1px solid #1a1a2e; border-radius: 12px; padding: 30px; }
                .header { border-bottom: 2px solid #00ff88; padding-bottom: 20px; margin-bottom: 20px; }
                .logo { color: #00ff88; font-size: 24px; font-weight: bold; }
                .title { color: #ffffff; font-size: 18px; margin: 20px 0; }
                .message { color: #cccccc; line-height: 1.6; }
                .button { display: inline-block; background: linear-gradient(135deg, #00ff88, #00cc6a); color: #050507; padding: 12px 24px; border-radius: 6px; text-decoration: none; font-weight: bold; margin-top: 20px; }
                .footer { margin-top: 30px; padding-top: 20px; border-top: 1px solid #1a1a2e; color: #666; font-size: 12px; }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="header">
                    <span class="logo">MCC ICT HELPDESK</span>
                </div>
                <div class="title">' . htmlspecialchars($title) . '</div>
                <div class="message">
                    <p>Hello ' . htmlspecialchars($name) . ',</p>
                    <p>' . htmlspecialchars($message ?? 'You have a new notification from the ICT Helpdesk system.') . '</p>
                </div>
                <a href="' . $ticket_url . '" class="button">View Ticket</a>
                <div class="footer">
                    <p>This is an automated message from MCC ICT Helpdesk System.</p>
                    <p>Please do not reply directly to this email. Use the system to respond.</p>
                </div>
            </div>
        </body>
        </html>';
    }
    
    private function getTicketUrl($ticket_id, $user_type) {
        $base_url = 'http://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']);
        
        switch ($user_type) {
            case 'admin':
                return $base_url . '/admin/ticket_detail.php?id=' . $ticket_id;
            case 'technician':
                return $base_url . '/technician/ticket_detail.php?id=' . $ticket_id;
            default:
                return $base_url . '/user/ticket_detail.php?id=' . $ticket_id;
        }
    }
    
    public function getUnreadCount($user_id) {
        $stmt = $this->conn->prepare(
            "SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = 0"
        );
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();
        
        return $row['count'] ?? 0;
    }
    
    public function markAsRead($notification_id, $user_id) {
        $stmt = $this->conn->prepare(
            "UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?"
        );
        $stmt->bind_param("ii", $notification_id, $user_id);
        $stmt->execute();
        $stmt->close();
    }
    
    public function markAllAsRead($user_id) {
        $stmt = $this->conn->prepare(
            "UPDATE notifications SET is_read = 1 WHERE user_id = ?"
        );
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $stmt->close();
    }
    
    public function getNotifications($user_id, $limit = 20) {
        $stmt = $this->conn->prepare(
            "SELECT n.*, t.title as ticket_title 
             FROM notifications n 
             LEFT JOIN tickets t ON n.ticket_id = t.id 
             WHERE n.user_id = ? 
             ORDER BY n.created_at DESC 
             LIMIT ?"
        );
        $stmt->bind_param("ii", $user_id, $limit);
        $stmt->execute();
        $result = $stmt->get_result();
        $notifications = [];
        while ($row = $result->fetch_assoc()) {
            $notifications[] = $row;
        }
        $stmt->close();
        
        return $notifications;
    }
    
    public function notifyTicketCreated($ticket_id, $created_by) {
        $this->createNotification(
            $created_by,
            'user',
            $ticket_id,
            'ticket_created',
            'Ticket #' . $ticket_id . ' Created',
            'Your support ticket has been submitted successfully. Our team will respond shortly.'
        );
        
        $admins = $this->getAdmins();
        foreach ($admins as $admin) {
            $this->createNotification(
                $admin['id'],
                'admin',
                $ticket_id,
                'ticket_created',
                'New Ticket #' . $ticket_id,
                'A new support ticket has been submitted and requires assignment.'
            );
        }
    }
    
    public function notifyTicketAssigned($ticket_id, $technician_id) {
        $this->createNotification(
            $technician_id,
            'technician',
            $ticket_id,
            'ticket_assigned',
            'Ticket #' . $ticket_id . ' Assigned',
            'A new ticket has been assigned to you. Please review and begin work.'
        );
    }
    
    public function notifyTicketUpdated($ticket_id, $updated_by, $old_status, $new_status) {
        $stmt = $this->conn->prepare(
            "SELECT created_by, assigned_to FROM tickets WHERE id = ?"
        );
        $stmt->bind_param("i", $ticket_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $ticket = $result->fetch_assoc();
        $stmt->close();
        
        $notify_users = [];
        
        if ($ticket['created_by'] != $updated_by) {
            $notify_users[] = ['id' => $ticket['created_by'], 'type' => 'user'];
        }
        
        if ($ticket['assigned_to'] && $ticket['assigned_to'] != $updated_by) {
            $notify_users[] = ['id' => $ticket['assigned_to'], 'type' => 'technician'];
        }
        
        foreach ($notify_users as $user) {
            $this->createNotification(
                $user['id'],
                $user['type'],
                $ticket_id,
                'ticket_updated',
                'Ticket #' . $ticket_id . ' Updated',
                "Status changed from " . ucfirst(str_replace('_', ' ', $old_status)) . " to " . ucfirst(str_replace('_', ' ', $new_status))
            );
        }
    }
    
    public function notifyCommentAdded($ticket_id, $comment_id, $is_internal) {
        if ($is_internal) return;
        
        $stmt = $this->conn->prepare(
            "SELECT tc.user_id, t.created_by, t.assigned_to 
             FROM ticket_comments tc 
             JOIN tickets t ON tc.ticket_id = t.id 
             WHERE tc.id = ?"
        );
        $stmt->bind_param("i", $comment_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $data = $result->fetch_assoc();
        $stmt->close();
        
        $comment = $this->getComment($comment_id);
        
        if ($data['created_by'] != $comment['user_id']) {
            $this->createNotification(
                $data['created_by'],
                'user',
                $ticket_id,
                'comment_added',
                'New Reply on Ticket #' . $ticket_id,
                'You have a new reply on your support ticket.'
            );
        }
    }
    
    private function getComment($comment_id) {
        $stmt = $this->conn->prepare("SELECT * FROM ticket_comments WHERE id = ?");
        $stmt->bind_param("i", $comment_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $comment = $result->fetch_assoc();
        $stmt->close();
        return $comment;
    }
    
    private function getAdmins() {
        $stmt = $this->conn->prepare(
            "SELECT id FROM users WHERE role = 'admin'"
        );
        $stmt->execute();
        $result = $stmt->get_result();
        $admins = [];
        while ($row = $result->fetch_assoc()) {
            $admins[] = $row;
        }
        $stmt->close();
        return $admins;
    }
}
