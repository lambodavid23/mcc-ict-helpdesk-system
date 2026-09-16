<?php
/**
 * Update Ticket Status
 * Smart ICT Helpdesk System - Mutare City Council
 */

require_once '../config/auth_helper.php';
require_once '../config/database.php';
require_once '../config/TicketActionService.php';

requireRole('technician');

$database = new Database();
$conn = $database->getConnection();

$ticket_actions = new TicketActionService();

// Get current technician info
$technician_query = "SELECT * FROM technicians WHERE id = " . (int)$_SESSION['user_id'];
$technician = $conn->query($technician_query)->fetch_assoc();

if (!$technician) {
    setError('Technician profile not found. Please contact administrator.');
    header('Location: ../index.php');
    exit();
}

$success = '';
$error = '';
$ticket = null;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $ticket_id = $_POST['ticket_id'];
    $action = $_POST['action'];
    
    // Verify ticket is assigned to this technician
    $verify_query = "SELECT id FROM tickets WHERE id = " . (int)$ticket_id . " AND assigned_to = " . $technician['id'];
    $verify_result = $conn->query($verify_query);
    
    if (!$verify_result || $verify_result->num_rows == 0) {
        $error = 'Ticket not found or not assigned to you';
    } else {
        switch ($action) {
            case 'update_status':
                $new_status = $_POST['new_status'];
                $notes = trim($_POST['notes']);
                
                $result = $ticket_actions->updateStatus($ticket_id, $technician['id'], $new_status, $notes);
                if ($result['success']) {
                    $success = $result['message'];
                } else {
                    $error = $result['message'];
                }
                break;
                
            case 'add_solution':
                $solution = trim($_POST['solution']);
                
                if (empty($solution)) {
                    $error = 'Solution description is required';
                } else {
                    // Get ticket details
                    $ticket_query = "SELECT title FROM tickets WHERE id = " . (int)$ticket_id;
                    $ticket_data = $conn->query($ticket_query)->fetch_assoc();
                    
                    if ($ticket_data) {
                        $solution = $conn->real_escape_string($solution);
                        $title = $conn->real_escape_string($ticket_data['title']);
                        
                        // Add to fault history
                        $history_query = "INSERT INTO fault_history (ticket_id, problem, solution, resolved_by, resolved_at) 
                                         VALUES ($ticket_id, '$title', '$solution', " . $technician['id'] . ", NOW())";
                        
                        if ($conn->query($history_query)) {
                            // Update ticket status
                            $conn->query("UPDATE tickets SET status = 'resolved' WHERE id = $ticket_id");
                            
                            // Update technician workload
                            $conn->query("UPDATE technicians SET current_workload = GREATEST(current_workload - 1, 0) WHERE id = " . $technician['id']);
                            
                            $success = 'Solution added and ticket marked as resolved';
                            logActivity('ADD_SOLUTION', "Added solution for ticket $ticket_id");
                        } else {
                            $error = 'Failed to add solution';
                        }
                    }
                }
                break;
                
            case 'delete_status_update':
                $result = $ticket_actions->deleteStatusUpdate($ticket_id, $technician['id'], (int)$_POST['assignment_id']);
                if ($result['success']) {
                    $success = $result['message'];
                } else {
                    $error = $result['message'];
                }
                break;
                
            case 'delete_solution':
                $result = $ticket_actions->deleteSolution($ticket_id, $technician['id'], (int)$_POST['history_id']);
                if ($result['success']) {
                    $success = $result['message'];
                } else {
                    $error = $result['message'];
                }
                break;
        }
        
        // Reload ticket data after update
        $ticket_id = (int)$ticket_id;
    }
}

// Get ticket ID from URL parameter
$ticket_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($ticket_id > 0) {
    $ticket_query = "SELECT t.*, u.name as created_by_name, u.email as created_by_email 
                    FROM tickets t 
                    LEFT JOIN users u ON t.created_by = u.id 
                    WHERE t.id = $ticket_id AND t.assigned_to = " . $technician['id'];
    $ticket = $conn->query($ticket_query)->fetch_assoc();
    
    if (!$ticket) {
        setError('Ticket not found or not assigned to you');
        header('Location: my_tickets.php');
        exit();
    }
}

// Get ticket history (live records only; legacy soft-deleted rows stay hidden)
$history_query = "SELECT fh.*, tech.name as technician_name 
                  FROM fault_history fh 
                  LEFT JOIN technicians tech ON fh.resolved_by = tech.id 
                  WHERE fh.ticket_id = $ticket_id AND fh.deleted_at IS NULL
                  ORDER BY fh.resolved_at DESC";
$history = $conn->query($history_query);

// Get ticket assignments (live records only; legacy soft-deleted rows stay hidden)
$assignments_query = "SELECT ta.*, tech.name as technician_name 
                     FROM ticket_assignments ta 
                     LEFT JOIN technicians tech ON ta.technician_id = tech.id 
                     WHERE ta.ticket_id = $ticket_id AND ta.deleted_at IS NULL
                     ORDER BY ta.assigned_at DESC";
$assignments = $conn->query($assignments_query);

// Latest status update id, used to decide whether deleting it reverts the ticket
$latest_status_update = $conn->query(
    "SELECT MAX(id) as max_id FROM ticket_assignments
     WHERE ticket_id = $ticket_id AND deleted_at IS NULL AND previous_status IS NOT NULL"
)->fetch_assoc();
$latest_status_update_id = (int)($latest_status_update['max_id'] ?? 0);

$deletions_remaining = $ticket_actions->deletionsRemaining($technician['id']);

logActivity('VIEW_UPDATE_TICKET', "Technician viewed update page for ticket $ticket_id");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Update Ticket - MCC ICT Helpdesk</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
    <!-- Mobile Menu Toggle -->
    <button class="mobile-menu-toggle">☰</button>

    <!-- Sidebar -->
    <div class="sidebar">
        <div class="sidebar-header">
            <div class="logo">
                <img src="../assets/images/mutarelogo.png" alt="MCC Logo">
                <div class="logo-text">MCC Helpdesk</div>
            </div>
        </div>
        <nav class="nav-menu">
            <?php $menu = getNavigationMenu('technician'); ?>
            <?php foreach ($menu as $item): ?>
                <a href="<?php echo $item['url']; ?>" class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == basename($item['url']) ? 'active' : ''; ?>">
                    <?php echo $item['icon']; ?> <?php echo $item['title']; ?>
                </a>
            <?php endforeach; ?>
            <a href="../auth/logout.php" class="nav-item" style="margin-top: auto; border-top: 1px solid #334155;">
                🚪 Logout
            </a>
        </nav>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Header -->
        <div class="header">
            <div class="header-title">Update Ticket #<?php echo $ticket ? $ticket['id'] : ''; ?></div>
            <div class="user-info">
                <span><?php echo htmlspecialchars($_SESSION['user_name']); ?></span>
                <div class="user-avatar"><?php echo strtoupper(substr($_SESSION['user_name'], 0, 1)); ?></div>
            </div>
        </div>

        <?php echo displaySuccess(); ?>
        <?php echo displayError(); ?>
        
        <?php if ($success): ?>
            <div class="alert alert-success fade-in"><?php echo $success; ?></div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="alert alert-error fade-in"><?php echo $error; ?></div>
        <?php endif; ?>

        <?php if ($ticket): ?>
            <!-- Ticket Details -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Ticket Details</h3>
                    <div>
                        <?php echo getStatusBadge($ticket['status']); ?>
                        <?php echo getPriorityBadge($ticket['priority']); ?>
                    </div>
                </div>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1rem;">
                    <div>
                        <strong>Title:</strong><br>
                        <?php echo htmlspecialchars($ticket['title']); ?>
                    </div>
                    <div>
                        <strong>Category:</strong><br>
                        <?php echo ucfirst($ticket['category']); ?>
                    </div>
                    <div>
                        <strong>Department:</strong><br>
                        <?php echo htmlspecialchars($ticket['department']); ?>
                    </div>
                    <div>
                        <strong>Created By:</strong><br>
                        <?php echo htmlspecialchars($ticket['created_by_name']); ?> (<?php echo htmlspecialchars($ticket['created_by_email']); ?>)
                    </div>
                    <div>
                        <strong>Created:</strong><br>
                        <?php echo formatDate($ticket['created_at']); ?>
                    </div>
                    <div>
                        <strong>Last Updated:</strong><br>
                        <?php echo formatDate($ticket['updated_at']); ?>
                    </div>
                </div>
                <div style="margin-top: 1rem;">
                    <strong>Description:</strong><br>
                    <div style="background: #0f172a; padding: 1rem; border-radius: 8px; margin-top: 0.5rem;">
                        <?php echo nl2br(htmlspecialchars($ticket['description'])); ?>
                    </div>
                </div>
            </div>

            <!-- Update Status Form -->
            <?php if ($ticket['status'] != 'resolved'): ?>
            <div class="card" style="margin-top: 1.5rem;">
                <div class="card-header">
                    <h3 class="card-title">Update Resolution / Status</h3>
                </div>
                <form method="POST" action="">
                    <input type="hidden" name="action" value="update_status">
                    <input type="hidden" name="ticket_id" value="<?php echo $ticket['id']; ?>">
                    <div style="display: grid; grid-template-columns: 1fr 2fr; gap: 1rem; align-items: end;">
                        <div class="form-group">
                            <label for="new_status" class="form-label">New Status</label>
                            <select id="new_status" name="new_status" class="form-select" required>
                                <option value="">Select Status</option>
                                <option value="open" <?php echo $ticket['status'] == 'open' ? 'selected' : ''; ?>>Open</option>
                                <option value="in_progress" <?php echo $ticket['status'] == 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="notes" class="form-label">Resolution / Action Taken <span style="color: #ef4444;">*</span></label>
                            <textarea id="notes" name="notes" class="form-textarea" rows="3" placeholder="You must describe the resolution or action taken to change the status..." required></textarea>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary">Save Update</button>
                </form>
            </div>
            <?php endif; ?>

            <!-- Add Solution Form -->
            <?php if ($ticket['status'] != 'resolved'): ?>
            <div class="card" style="margin-top: 1.5rem;">
                <div class="card-header">
                    <h3 class="card-title">Add Solution</h3>
                </div>
                <form method="POST" action="" id="solutionForm">
                    <input type="hidden" name="action" value="add_solution">
                    <input type="hidden" name="ticket_id" value="<?php echo $ticket['id']; ?>">
                    <div class="form-group">
                        <label for="solution" class="form-label">Solution Description</label>
                        <textarea id="solution" name="solution" class="form-textarea" rows="6" placeholder="Describe the solution implemented..." required></textarea>
                    </div>
                    <button type="submit" class="btn btn-success">Submit Solution & Resolve Ticket</button>
                </form>
            </div>
            <?php endif; ?>

            <!-- Delete Updated Status / Resolution -->
            <div class="card" style="margin-top: 1.5rem; border-color: rgba(127, 29, 29, 0.4);">
                <div class="card-header">
                    <h3 class="card-title">Delete Updated Status / Resolution</h3>
                    <span class="badge badge-in-progress">Deletions left today: <?php echo $deletions_remaining; ?>/2</span>
                </div>
                
                <?php if ($deletions_remaining > 0): ?>
                    <?php $has_own_records = false; ?>
                    
                    <?php if ($history && $history->num_rows > 0): ?>
                        <?php $history->data_seek(0); while ($item = $history->fetch_assoc()): ?>
                            <?php if ((int)$item['resolved_by'] === (int)$technician['id']): $has_own_records = true; ?>
                                <div style="display: flex; align-items: center; justify-content: space-between; gap: 1rem; padding: 0.75rem 1rem; border-bottom: 1px solid #1e293b;">
                                    <div style="flex: 1; min-width: 0;">
                                        <span class="badge badge-resolved">Solution</span>
                                        <span style="font-size: 0.75rem; color: #94a3b8; margin-left: 0.5rem;"><?php echo formatDate($item['resolved_at']); ?></span>
                                        <div style="font-size: 0.8rem; color: #cbd5e1; margin-top: 0.25rem; word-wrap: break-word;">
                                            <?php echo htmlspecialchars($item['solution']); ?>
                                        </div>
                                    </div>
                                    <form method="POST" action="" style="flex-shrink: 0;"
                                          onsubmit="return confirm('Permanently delete this resolution? If it was the last one, the ticket returns to In Progress. You have <?php echo $deletions_remaining; ?>/2 deletions left today.')">
                                        <input type="hidden" name="action" value="delete_solution">
                                        <input type="hidden" name="ticket_id" value="<?php echo $ticket['id']; ?>">
                                        <input type="hidden" name="history_id" value="<?php echo $item['id']; ?>">
                                        <button type="submit" class="btn btn-sm" style="background: #7f1d1d; color: #fff; border: none; border-radius: 6px; padding: 0.35rem 0.9rem; font-size: 0.75rem; cursor: pointer;">Delete</button>
                                    </form>
                                </div>
                            <?php endif; ?>
                        <?php endwhile; ?>
                    <?php endif; ?>
                    
                    <?php if ($assignments && $assignments->num_rows > 0): ?>
                        <?php $assignments->data_seek(0); while ($assignment = $assignments->fetch_assoc()): ?>
                            <?php if ($assignment['previous_status'] !== null && (int)$assignment['technician_id'] === (int)$technician['id']): $has_own_records = true; ?>
                                <div style="display: flex; align-items: center; justify-content: space-between; gap: 1rem; padding: 0.75rem 1rem; border-bottom: 1px solid #1e293b;">
                                    <div style="flex: 1; min-width: 0;">
                                        <span class="badge badge-in-progress">Status Update</span>
                                        <span style="font-size: 0.75rem; color: #94a3b8; margin-left: 0.5rem;">
                                            <?php echo ucfirst($assignment['previous_status']); ?> &rarr; <?php echo ucfirst($assignment['new_status']); ?>
                                            &middot; <?php echo formatDate($assignment['assigned_at']); ?>
                                        </span>
                                        <?php if ($assignment['notes']): ?>
                                            <div style="font-size: 0.8rem; color: #cbd5e1; margin-top: 0.25rem; word-wrap: break-word;">
                                                <?php echo htmlspecialchars($assignment['notes']); ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <form method="POST" action="" style="flex-shrink: 0;"
                                          onsubmit="return confirm('<?php echo (int)$assignment['id'] === $latest_status_update_id ? 'Permanently delete? The ticket status will be reverted to ' . ucfirst($assignment['previous_status']) . '.' : 'Permanently delete? This only removes the history entry; the ticket status stays unchanged.'; ?> You have <?php echo $deletions_remaining; ?>/2 deletions left today.')">
                                        <input type="hidden" name="action" value="delete_status_update">
                                        <input type="hidden" name="ticket_id" value="<?php echo $ticket['id']; ?>">
                                        <input type="hidden" name="assignment_id" value="<?php echo $assignment['id']; ?>">
                                        <button type="submit" class="btn btn-sm" style="background: #7f1d1d; color: #fff; border: none; border-radius: 6px; padding: 0.35rem 0.9rem; font-size: 0.75rem; cursor: pointer;">Delete</button>
                                    </form>
                                </div>
                            <?php endif; ?>
                        <?php endwhile; ?>
                    <?php endif; ?>
                    
                    <?php if (!$has_own_records): ?>
                        <p style="color: #94a3b8; font-size: 0.85rem; padding: 0.75rem 1rem;">No status updates or resolutions created by you on this ticket.</p>
                    <?php endif; ?>
                <?php else: ?>
                    <p style="color: #ef4444; font-size: 0.85rem; padding: 0.75rem 1rem;">You have used all your deletions for today (2/2). Try again tomorrow.</p>
                <?php endif; ?>
            </div>

            <!-- Ticket History -->
            <div class="card" style="margin-top: 1.5rem;">
                <div class="card-header">
                    <h3 class="card-title">Ticket History</h3>
                </div>
                <div class="table-container">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Action</th>
                                <th>Technician</th>
                                <th>Details</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($history && $history->num_rows > 0): ?>
                                <?php $history->data_seek(0); while ($item = $history->fetch_assoc()): ?>
                                    <tr>
                                        <td><?php echo formatDate($item['resolved_at']); ?></td>
                                        <td><span class="badge badge-resolved">Solution Added</span></td>
                                        <td><?php echo htmlspecialchars($item['technician_name']); ?></td>
                                        <td>
                                            <strong>Problem:</strong> <?php echo htmlspecialchars($item['problem']); ?><br>
                                            <strong>Solution:</strong> <?php echo htmlspecialchars($item['solution']); ?>
                                            <?php if ($item['time_to_resolve']): ?>
                                                <br><strong>Resolution Time:</strong> <?php echo $item['time_to_resolve']; ?> minutes
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php endif; ?>
                            
                            <?php if ($assignments && $assignments->num_rows > 0): ?>
                                <?php $assignments->data_seek(0); while ($assignment = $assignments->fetch_assoc()): ?>
                                    <tr>
                                        <td><?php echo formatDate($assignment['assigned_at']); ?></td>
                                        <td>
                                            <?php if ($assignment['previous_status'] !== null): ?>
                                                <span class="badge badge-in-progress">Status Update</span>
                                            <?php else: ?>
                                                <span class="badge badge-in-progress">Assignment</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($assignment['technician_name']); ?></td>
                                        <td>
                                            <?php if ($assignment['previous_status'] !== null): ?>
                                                <strong>Status:</strong> <?php echo ucfirst($assignment['previous_status']); ?> &rarr; <?php echo ucfirst($assignment['new_status']); ?><br>
                                            <?php endif; ?>
                                            <?php if ($assignment['notes']): ?>
                                                <?php echo htmlspecialchars($assignment['notes']); ?>
                                            <?php else: ?>
                                                Status updated
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php endif; ?>
                            
                            <?php if ((!$history || $history->num_rows == 0) && (!$assignments || $assignments->num_rows == 0)): ?>
                                <tr>
                                    <td colspan="4" style="text-align: center;">No history available</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Action Buttons -->
            <div style="margin-top: 1.5rem; display: flex; gap: 1rem;">
                <a href="my_tickets.php" class="btn btn-secondary">← Back to My Tickets</a>
                <button onclick="window.print()" class="btn btn-secondary">🖨️ Print Ticket</button>
            </div>
        <?php else: ?>
            <div class="card">
                <div class="alert alert-error">Ticket not found or not assigned to you.</div>
                <a href="my_tickets.php" class="btn btn-primary">← Back to My Tickets</a>
            </div>
        <?php endif; ?>
    </div>

    <script src="../assets/js/script.js"></script>
</body>
</html>
