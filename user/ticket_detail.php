<?php
require_once '../config/auth_helper.php';
require_once '../config/database.php';
require_once '../config/NotificationService.php';
require_once '../config/AutoAssignmentService.php';

$database = new Database();
$conn = $database->getConnection();
$notifications = new NotificationService();
$auto_assign = new AutoAssignmentService();

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['user_role'];
$ticket_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$ticket_id) {
    header('Location: dashboard.php');
    exit();
}

$ticket = $conn->query("SELECT t.*, u.name as created_by_name, u.email as created_by_email, tech.name as assigned_to_name 
                         FROM tickets t 
                         LEFT JOIN users u ON t.created_by = u.id 
                         LEFT JOIN technicians tech ON t.assigned_to = tech.id 
                         WHERE t.id = $ticket_id")->fetch_assoc();

if (!$ticket) {
    $_SESSION['error'] = 'Ticket not found';
    header('Location: dashboard.php');
    exit();
}

if ($user_role == 'user' && $ticket['created_by'] != $user_id) {
    $_SESSION['error'] = 'Access denied';
    header('Location: dashboard.php');
    exit();
}

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add_comment':
                $comment = trim($_POST['comment']);
                $is_internal = isset($_POST['is_internal']) ? 1 : 0;
                
                if (empty($comment)) {
                    $error = 'Comment cannot be empty';
                } elseif ($is_internal && !in_array($user_role, ['admin', 'technician'])) {
                    $error = 'Only technicians and admins can add internal notes';
                } else {
                    $comment_escaped = $conn->real_escape_string($comment);
                    $user_type = $user_role == 'user' ? 'user' : ($user_role == 'technician' ? 'technician' : 'admin');
                    
                    $stmt = $conn->prepare("INSERT INTO ticket_comments (ticket_id, user_id, user_type, comment, is_internal) VALUES (?, ?, ?, ?, ?)");
                    $stmt->bind_param("iissi", $ticket_id, $user_id, $user_type, $comment_escaped, $is_internal);
                    $stmt->execute();
                    $comment_id = $conn->insert_id;
                    $stmt->close();
                    
                    $notifications->notifyCommentAdded($ticket_id, $comment_id, $is_internal);
                    
                    logActivity('ADD_COMMENT', "Comment added to ticket #$ticket_id");
                    $success = 'Comment added successfully';
                }
                break;
                
            case 'update_status':
                $new_status = $_POST['new_status'];
                $solution = isset($_POST['solution']) ? trim($_POST['solution']) : '';
                $old_status = $ticket['status'];
                
                if (!in_array($new_status, ['open', 'in_progress', 'resolved', 'closed'])) {
                    $error = 'Invalid status';
                } elseif ($new_status == 'resolved' && empty($solution)) {
                    $error = 'You must enter a resolution before marking the ticket as resolved.';
                } else {
                    $stmt = $conn->prepare("UPDATE tickets SET status = ?, updated_at = NOW() WHERE id = ?");
                    $stmt->bind_param("si", $new_status, $ticket_id);
                    $stmt->execute();
                    $stmt->close();
                    
                    if ($new_status == 'resolved' && !empty($solution)) {
                        $resolution_time = $conn->query("SELECT TIMESTAMPDIFF(MINUTE, created_at, NOW()) as mins FROM tickets WHERE id = $ticket_id")->fetch_assoc()['mins'];
                        
                        $solution_escaped = $conn->real_escape_string($solution);
                        $stmt = $conn->prepare("INSERT INTO fault_history (ticket_id, problem, solution, resolved_by, resolved_at, time_to_resolve) VALUES (?, ?, ?, ?, NOW(), ?)");
                        $assigned_to = $ticket['assigned_to'] ?? $user_id;
                        $stmt->bind_param("isssi", $ticket_id, $ticket['title'], $solution_escaped, $assigned_to, $resolution_time);
                        $stmt->execute();
                        $stmt->close();
                        
                        if ($ticket['assigned_to']) {
                            $stmt = $conn->prepare("UPDATE technicians SET current_workload = GREATEST(current_workload - 1, 0) WHERE id = ?");
                            $stmt->bind_param("i", $ticket['assigned_to']);
                            $stmt->execute();
                            $stmt->close();
                        }
                    }
                    
                    $notifications->notifyTicketUpdated($ticket_id, $user_id, $old_status, $new_status);
                    logActivity('UPDATE_STATUS', "Ticket #$ticket_id status changed from $old_status to $new_status");
                    
                    $ticket = $conn->query("SELECT t.*, u.name as created_by_name, u.email as created_by_email, tech.name as assigned_to_name 
                                           FROM tickets t 
                                           LEFT JOIN users u ON t.created_by = u.id 
                                           LEFT JOIN technicians tech ON t.assigned_to = tech.id 
                                           WHERE t.id = $ticket_id")->fetch_assoc();
                    
                    $success = 'Status updated successfully';
                }
                break;
                
            case 'assign_technician':
                $technician_id = (int)$_POST['technician_id'];
                
                if ($technician_id > 0) {
                    $stmt = $conn->prepare("UPDATE tickets SET assigned_to = ?, status = 'in_progress', updated_at = NOW() WHERE id = ?");
                    $stmt->bind_param("ii", $technician_id, $ticket_id);
                    $stmt->execute();
                    $stmt->close();
                    
                    $stmt = $conn->prepare("UPDATE technicians SET current_workload = current_workload + 1 WHERE id = ?");
                    $stmt->bind_param("i", $technician_id);
                    $stmt->execute();
                    $stmt->close();
                    
                    $notifications->notifyTicketAssigned($ticket_id, $technician_id);
                    logActivity('ASSIGN_TECHNICIAN', "Ticket #$ticket_id assigned to technician #$technician_id");
                    
                    $ticket = $conn->query("SELECT t.*, u.name as created_by_name, u.email as created_by_email, tech.name as assigned_to_name 
                                           FROM tickets t 
                                           LEFT JOIN users u ON t.created_by = u.id 
                                           LEFT JOIN technicians tech ON t.assigned_to = tech.id 
                                           WHERE t.id = $ticket_id")->fetch_assoc();
                    
                    $success = 'Technician assigned successfully';
                }
                break;
        }
    }
    
    if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] == 0) {
        $file = $_FILES['attachment'];
        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx', 'txt', 'zip'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        
        if (!in_array($ext, $allowed)) {
            $error = 'File type not allowed';
        } elseif ($file['size'] > 5 * 1024 * 1024) {
            $error = 'File size must be less than 5MB';
        } else {
            $upload_dir = '../uploads/tickets/' . $ticket_id . '/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            
            $filename = time() . '_' . basename($file['name']);
            $filepath = $upload_dir . $filename;
            
            if (move_uploaded_file($file['tmp_name'], $filepath)) {
                $filename_esc = $conn->real_escape_string($file['name']);
                $filepath_esc = $conn->real_escape_string($filepath);
                $filesize = $file['size'];
                $mime = $conn->real_escape_string($file['type']);
                
                $stmt = $conn->prepare("INSERT INTO ticket_attachments (ticket_id, uploaded_by, filename, filepath, filesize, mime_type) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("iissis", $ticket_id, $user_id, $filename_esc, $filepath_esc, $filesize, $mime);
                $stmt->execute();
                $stmt->close();
                
                logActivity('UPLOAD_ATTACHMENT', "File uploaded to ticket #$ticket_id");
                $success = 'File uploaded successfully';
            } else {
                $error = 'Failed to upload file';
            }
        }
    }
}

$comments = $conn->query("SELECT tc.*, COALESCE(u.name, tech.name, a.name, 'Unknown') as user_name 
                          FROM ticket_comments tc 
                          LEFT JOIN users u ON tc.user_type = 'user' AND tc.user_id = u.id
                          LEFT JOIN technicians tech ON tc.user_type = 'technician' AND tc.user_id = tech.id
                          LEFT JOIN admins a ON tc.user_type = 'admin' AND tc.user_id = a.id
                          WHERE tc.ticket_id = $ticket_id 
                          ORDER BY tc.created_at ASC");

$attachments = $conn->query("SELECT ta.*, COALESCE(u.name, tech.name, a.name, 'Unknown') as uploaded_by_name 
                             FROM ticket_attachments ta 
                             LEFT JOIN users u ON ta.uploaded_by = u.id
                             LEFT JOIN technicians tech ON ta.uploaded_by = tech.id
                             LEFT JOIN admins a ON ta.uploaded_by = a.id
                             WHERE ta.ticket_id = $ticket_id 
                             ORDER BY ta.created_at DESC");

$technicians = $conn->query("SELECT id, name, specialization, status, current_workload FROM technicians ORDER BY name");

logActivity('VIEW_TICKET', "Viewed ticket #$ticket_id");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ticket #<?php echo $ticket_id; ?> - MCC ICT Helpdesk</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { font-family: 'Space Grotesk', sans-serif; }
        body { background: #050507; }
        .grid-bg {
            background-image: linear-gradient(rgba(26, 26, 46, 0.3) 1px, transparent 1px), linear-gradient(90deg, rgba(26, 26, 46, 0.3) 1px, transparent 1px);
            background-size: 40px 40px;
        }
        .glow-text { text-shadow: 0 0 20px rgba(0, 255, 136, 0.3); }
        .cyber-card {
            background: rgba(10, 10, 15, 0.9);
            border: 1px solid #1a1a2e;
            border-radius: 12px;
            position: relative;
            overflow: hidden;
        }
        .cyber-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 2px;
            background: linear-gradient(90deg, transparent, #00ff88, transparent);
            opacity: 0.5;
        }
        .cyber-input, .cyber-select, .cyber-textarea {
            width: 100%;
            padding: 0.5rem 0.75rem;
            background: rgba(15, 15, 21, 0.8);
            border: 1px solid #1a1a2e;
            border-radius: 6px;
            color: #e0e0e0;
            font-size: 0.8rem;
            transition: all 0.3s;
        }
        .cyber-input:focus, .cyber-select:focus, .cyber-textarea:focus {
            outline: none;
            border-color: #00ff88;
            box-shadow: 0 0 0 2px rgba(0, 255, 136, 0.1);
        }
        .cyber-textarea { min-height: 100px; resize: vertical; }
        .cyber-btn {
            background: linear-gradient(135deg, #00ff88, #00cc6a);
            color: #050507;
            padding: 0.5rem 1rem;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 600;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            border: none;
            cursor: pointer;
        }
        .cyber-btn:hover { box-shadow: 0 0 20px rgba(0, 255, 136, 0.3); }
        .cyber-btn-secondary { background: transparent; border: 1px solid #1a1a2e; color: #666; }
        .cyber-btn-secondary:hover { border-color: #00ff88; color: #00ff88; }
        .cyber-btn-danger { background: linear-gradient(135deg, #ef4444, #dc2626); color: white; }
        .badge {
            display: inline-flex;
            align-items: center;
            padding: 0.25rem 0.625rem;
            border-radius: 9999px;
            font-size: 0.65rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .badge-open { background: rgba(239, 68, 68, 0.2); color: #ef4444; }
        .badge-in_progress { background: rgba(245, 158, 11, 0.2); color: #f59e0b; }
        .badge-resolved { background: rgba(0, 255, 136, 0.2); color: #00ff88; }
        .badge-closed { background: rgba(100, 116, 139, 0.2); color: #94a3b8; }
        .badge-high { background: rgba(239, 68, 68, 0.2); color: #ef4444; }
        .badge-medium { background: rgba(245, 158, 11, 0.2); color: #f59e0b; }
        .badge-low { background: rgba(59, 130, 246, 0.2); color: #60a5fa; }
        .sidebar-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem 1rem;
            color: #666;
            font-size: 0.8rem;
            font-weight: 500;
            border-radius: 8px;
            transition: all 0.3s;
        }
        .sidebar-item:hover, .sidebar-item.active {
            background: rgba(0, 255, 136, 0.1);
            color: #00ff88;
        }
        .comment { padding: 1rem; border-radius: 8px; margin-bottom: 1rem; }
        .comment-public { background: rgba(15, 15, 21, 0.8); border: 1px solid #1a1a2e; }
        .comment-internal { background: rgba(245, 158, 11, 0.1); border: 1px solid rgba(245, 158, 11, 0.3); }
        .comment-header { display: flex; justify-content: space-between; margin-bottom: 0.5rem; }
        .comment-user { font-weight: 600; color: #fff; }
        .comment-time { font-size: 0.7rem; color: #666; }
        .comment-body { color: #ccc; line-height: 1.6; }
        .alert { padding: 0.75rem 1rem; border-radius: 8px; font-size: 0.8rem; margin-bottom: 1rem; }
        .alert-success { background: rgba(0, 255, 136, 0.1); border: 1px solid rgba(0, 255, 136, 0.3); color: #00ff88; }
        .alert-error { background: rgba(239, 68, 68, 0.1); border: 1px solid rgba(239, 68, 68, 0.3); color: #ef4444; }
    </style>
</head>
<body class="min-h-screen grid-bg">
    <div class="flex">
        <aside class="fixed top-0 left-0 h-screen w-64 bg-[#0a0a0f]/95 border-r border-[#1a1a2e] p-4 flex flex-col overflow-hidden">
            <div class="flex items-center gap-3 mb-6 pb-4 border-b border-[#1a1a2e]">
                <img src="../assets/images/mutarelogo.png" alt="MCC" class="w-10 h-10">
                <div>
                    <span class="text-sm font-bold text-white">MCC ICT</span>
                    <p class="text-[10px] text-[#00ff88] uppercase tracking-wider">User Panel</p>
                </div>
            </div>
            
            <nav class="flex-1 space-y-1">
                <a href="dashboard.php" class="sidebar-item">
                    <i data-lucide="layout-dashboard" class="w-4 h-4"></i>
                    Dashboard
                </a>
                <a href="submit_ticket.php" class="sidebar-item">
                    <i data-lucide="plus-circle" class="w-4 h-4"></i>
                    New Ticket
                </a>
                <a href="my_requests.php" class="sidebar-item">
                    <i data-lucide="list" class="w-4 h-4"></i>
                    My Requests
                </a>
            </nav>
            
            <div class="pt-4 border-t border-[#1a1a2e]">
                <div class="flex items-center gap-3 mb-3">
                    <div class="w-9 h-9 rounded-lg bg-[#00ff88]/20 border border-[#00ff88]/30 flex items-center justify-center text-[#00ff88] font-bold text-sm">
                        <?php echo strtoupper(substr($_SESSION['user_name'], 0, 1)); ?>
                    </div>
                    <div>
                        <p class="text-xs font-medium text-white"><?php echo htmlspecialchars($_SESSION['user_name']); ?></p>
                        <p class="text-[10px] text-[#666] capitalize"><?php echo $user_role; ?></p>
                    </div>
                </div>
                <a href="../auth/logout.php" class="flex items-center gap-2 text-[#666] hover:text-[#ef4444] text-xs transition-colors">
                    <i data-lucide="log-out" class="w-4 h-4"></i>
                    Logout
                </a>
            </div>
        </aside>
        
        <main class="ml-64 flex-1 p-6 h-screen overflow-y-auto">
            <?php if ($success): ?>
                <div class="alert alert-success flex items-center gap-2">
                    <i data-lucide="check-circle" class="w-4 h-4"></i>
                    <?php echo $success; ?>
                </div>
            <?php endif; ?>
            
            <?php if ($error): ?>
                <div class="alert alert-error flex items-center gap-2">
                    <i data-lucide="x-circle" class="w-4 h-4"></i>
                    <?php echo $error; ?>
                </div>
            <?php endif; ?>
            
            <div class="flex items-center justify-between mb-6">
                <div>
                    <a href="<?php echo $user_role == 'user' ? 'my_requests.php' : ($user_role == 'technician' ? 'technician_queue.php' : 'all_tickets.php'); ?>" class="text-[#666] hover:text-[#00ff88] text-xs flex items-center gap-1 mb-2">
                        <i data-lucide="arrow-left" class="w-3 h-3"></i>
                        Back
                    </a>
                    <h1 class="text-xl font-bold text-white glow-text flex items-center gap-3">
                        Ticket #<?php echo $ticket_id; ?>
                        <span class="badge badge-<?php echo $ticket['status']; ?>"><?php echo ucfirst(str_replace('_', ' ', $ticket['status'])); ?></span>
                        <span class="badge badge-<?php echo $ticket['priority']; ?>"><?php echo ucfirst($ticket['priority']); ?></span>
                    </h1>
                </div>
            </div>
            
            <div class="grid grid-cols-3 gap-6">
                <!-- Main Content -->
                <div class="col-span-2 space-y-6">
                    <!-- Ticket Info -->
                    <div class="cyber-card p-6">
                        <h2 class="text-lg font-bold text-white mb-4"><?php echo htmlspecialchars($ticket['title']); ?></h2>
                        <div class="text-[#ccc] mb-4 whitespace-pre-wrap"><?php echo htmlspecialchars($ticket['description']); ?></div>
                        <div class="flex flex-wrap gap-4 text-xs text-[#666]">
                            <span>Department: <span class="text-[#ccc]"><?php echo $ticket['department']; ?></span></span>
                            <span>Category: <span class="text-[#ccc] capitalize"><?php echo $ticket['category']; ?></span></span>
                            <span>Created: <span class="text-[#ccc]"><?php echo date('M d, Y H:i', strtotime($ticket['created_at'])); ?></span></span>
                            <span>Updated: <span class="text-[#ccc]"><?php echo date('M d, Y H:i', strtotime($ticket['updated_at'])); ?></span></span>
                        </div>
                    </div>
                    
                    <!-- Attachments -->
                    <?php if ($attachments && $attachments->num_rows > 0): ?>
                    <div class="cyber-card p-4">
                        <h3 class="text-sm font-semibold text-white mb-4 flex items-center gap-2">
                            <i data-lucide="paperclip" class="w-4 h-4 text-[#00ff88]"></i>
                            Attachments (<?php echo $attachments->num_rows; ?>)
                        </h3>
                        <div class="grid grid-cols-2 gap-3">
                            <?php while ($att = $attachments->fetch_assoc()): ?>
                                <a href="<?php echo $att['filepath']; ?>" target="_blank" class="flex items-center gap-3 p-3 bg-[#0f0f15] rounded-lg hover:bg-[#1a1a2e] transition-colors">
                                    <i data-lucide="file" class="w-5 h-5 text-[#00ff88]"></i>
                                    <div class="flex-1 min-w-0">
                                        <p class="text-sm text-[#ccc] truncate"><?php echo htmlspecialchars($att['filename']); ?></p>
                                        <p class="text-xs text-[#666]"><?php echo round($att['filesize']/1024, 1); ?> KB</p>
                                    </div>
                                </a>
                            <?php endwhile; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Comments -->
                    <div class="cyber-card p-4">
                        <h3 class="text-sm font-semibold text-white mb-4 flex items-center gap-2">
                            <i data-lucide="message-square" class="w-4 h-4 text-[#00ff88]"></i>
                            Comments & Updates (<?php echo $comments ? $comments->num_rows : 0; ?>)
                        </h3>
                        
                        <?php if ($comments && $comments->num_rows > 0): ?>
                            <?php while ($comment = $comments->fetch_assoc()): ?>
                                <div class="comment <?php echo $comment['is_internal'] ? 'comment-internal' : 'comment-public'; ?>">
                                    <div class="comment-header">
                                        <span class="comment-user">
                                            <?php echo htmlspecialchars($comment['user_name']); ?>
                                            <span class="text-[10px] text-[#666] uppercase ml-2"><?php echo $comment['user_type']; ?></span>
                                            <?php if ($comment['is_internal']): ?>
                                                <span class="text-[10px] text-[#f59e0b] ml-2">INTERNAL</span>
                                            <?php endif; ?>
                                        </span>
                                        <span class="comment-time"><?php echo date('M d, Y H:i', strtotime($comment['created_at'])); ?></span>
                                    </div>
                                    <div class="comment-body"><?php echo nl2br(htmlspecialchars($comment['comment'])); ?></div>
                                </div>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <p class="text-center text-[#666] py-8">No comments yet</p>
                        <?php endif; ?>
                        
                        <!-- Add Comment Form -->
                        <form method="POST" class="mt-4 pt-4 border-t border-[#1a1a2e]">
                            <input type="hidden" name="action" value="add_comment">
                            <textarea name="comment" class="cyber-textarea" placeholder="Add a comment..." required></textarea>
                            <?php if (in_array($user_role, ['admin', 'technician'])): ?>
                                <label class="flex items-center gap-2 mt-2 text-xs text-[#666] cursor-pointer">
                                    <input type="checkbox" name="is_internal" class="w-4 h-4 accent-[#00ff88]">
                                    Internal note (only visible to staff)
                                </label>
                            <?php endif; ?>
                            <button type="submit" class="cyber-btn mt-3">
                                <i data-lucide="send" class="w-4 h-4"></i>
                                Post Comment
                            </button>
                        </form>
                    </div>
                </div>
                
                <!-- Sidebar -->
                <div class="space-y-6">
                    <!-- Status & Actions -->
                    <div class="cyber-card p-4">
                        <h3 class="text-sm font-semibold text-white mb-4">Status & Actions</h3>
                        
                        <?php if ($user_role != 'user'): ?>
                        <form method="POST" class="mb-4">
                            <input type="hidden" name="action" value="update_status">
                            <label class="block text-[10px] text-[#666] uppercase tracking-wider mb-2">Update Status</label>
                            <select name="new_status" class="cyber-select mb-2">
                                <option value="open" <?php echo $ticket['status'] == 'open' ? 'selected' : ''; ?>>Open</option>
                                <option value="in_progress" <?php echo $ticket['status'] == 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                                <option value="resolved" <?php echo $ticket['status'] == 'resolved' ? 'selected' : ''; ?>>Resolved</option>
                                <option value="closed" <?php echo $ticket['status'] == 'closed' ? 'selected' : ''; ?>>Closed</option>
                            </select>
                            <?php if ($ticket['status'] != 'resolved'): ?>
                            <textarea name="solution" class="cyber-textarea mb-2" placeholder="Resolution notes (if resolved)"></textarea>
                            <?php endif; ?>
                            <button type="submit" class="cyber-btn w-full justify-center">Update Status</button>
                        </form>
                        <?php endif; ?>
                        
                        <?php if ($user_role == 'user' && $ticket['status'] == 'resolved'): ?>
                        <form method="POST">
                            <input type="hidden" name="action" value="update_status">
                            <input type="hidden" name="new_status" value="closed">
                            <button type="submit" class="cyber-btn w-full justify-center">
                                <i data-lucide="check" class="w-4 h-4"></i>
                                Close Ticket
                            </button>
                        </form>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Assignment -->
                    <?php if ($user_role == 'admin'): ?>
                    <div class="cyber-card p-4">
                        <h3 class="text-sm font-semibold text-white mb-4">Assign Technician</h3>
                        <form method="POST">
                            <input type="hidden" name="action" value="assign_technician">
                            <select name="technician_id" class="cyber-select mb-3">
                                <option value="">-- Select Technician --</option>
                                <?php 
                                $technicians->data_seek(0);
                                while ($tech = $technicians->fetch_assoc()): 
                                ?>
                                    <option value="<?php echo $tech['id']; ?>" <?php echo $ticket['assigned_to'] == $tech['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($tech['name']); ?> (<?php echo $tech['specialization']; ?>)
                                    </option>
                                <?php endwhile; ?>
                            </select>
                            <button type="submit" class="cyber-btn w-full justify-center">Assign</button>
                        </form>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Ticket Details -->
                    <div class="cyber-card p-4">
                        <h3 class="text-sm font-semibold text-white mb-4">Details</h3>
                        <div class="space-y-3 text-xs">
                            <div class="flex justify-between">
                                <span class="text-[#666]">Created By</span>
                                <span class="text-[#ccc]"><?php echo htmlspecialchars($ticket['created_by_name']); ?></span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-[#666]">Assigned To</span>
                                <span class="text-[#ccc]"><?php echo $ticket['assigned_to_name'] ? htmlspecialchars($ticket['assigned_to_name']) : '<span class="text-[#444]">Unassigned</span>'; ?></span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-[#666]">Department</span>
                                <span class="text-[#ccc]"><?php echo $ticket['department']; ?></span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-[#666]">Category</span>
                                <span class="text-[#ccc] capitalize"><?php echo $ticket['category']; ?></span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-[#666]">Priority</span>
                                <span class="text-[#ccc] capitalize"><?php echo $ticket['priority']; ?></span>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Upload -->
                    <div class="cyber-card p-4">
                        <h3 class="text-sm font-semibold text-white mb-4">Upload File</h3>
                        <form method="POST" enctype="multipart/form-data">
                            <input type="file" name="attachment" class="text-xs text-[#ccc] file:mr-2 file:py-1 file:px-3 file:rounded file:border-0 file:bg-[#00ff88] file:text-[#050507] file:cursor-pointer file:font-semibold">
                            <p class="text-[10px] text-[#444] mt-1">Max 5MB. Images, PDF, Docs allowed.</p>
                            <button type="submit" class="cyber-btn-secondary w-full justify-center mt-3">
                                <i data-lucide="upload" class="w-4 h-4"></i>
                                Upload
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </main>
    </div>
    
    <script>
        lucide.createIcons();
    </script>
</body>
</html>
