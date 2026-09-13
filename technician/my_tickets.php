<?php
/**
 * My Tickets - Technician View
 * Smart ICT Helpdesk System - Mutare City Council
 */

require_once '../config/auth_helper.php';
require_once '../config/database.php';

requireRole('technician');

$database = new Database();
$conn = $database->getConnection();

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

// Handle ticket updates
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    switch ($_POST['action']) {
        case 'update_status':
            $ticket_id = $_POST['ticket_id'];
            $new_status = $_POST['new_status'];
            
            $update_query = "UPDATE tickets SET status = '" . $conn->real_escape_string($new_status) . "' 
                           WHERE id = " . (int)$ticket_id . " AND assigned_to = " . $technician['id'];
            
            if ($conn->query($update_query)) {
                $success = 'Ticket status updated successfully';
                logActivity('UPDATE_TICKET_STATUS', "Updated ticket $ticket_id status to $new_status");
            } else {
                $error = 'Failed to update ticket status';
            }
            break;
            
        case 'add_solution':
            $ticket_id = $_POST['ticket_id'];
            $solution = trim($_POST['solution']);
            
            if (empty($solution)) {
                $error = 'Solution description is required';
            } else {
                // Get ticket details for fault history
                $ticket_query = "SELECT title FROM tickets WHERE id = " . (int)$ticket_id;
                $ticket = $conn->query($ticket_query)->fetch_assoc();
                
                if ($ticket) {
                    $solution = $conn->real_escape_string($solution);
                    $title = $conn->real_escape_string($ticket['title']);
                    
                    // Add to fault history
                    $history_query = "INSERT INTO fault_history (ticket_id, problem, solution, resolved_by, resolved_at) 
                                     VALUES ($ticket_id, '$title', '$solution', " . $technician['id'] . ", NOW())";
                    
                    if ($conn->query($history_query)) {
                        // Update ticket status to resolved
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
    }
}

// Get filter parameters
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$priority_filter = isset($_GET['priority']) ? $_GET['priority'] : '';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Build query
$where_conditions = ["t.assigned_to = " . $technician['id']];

if ($status_filter) {
    $where_conditions[] = "t.status = '" . $conn->real_escape_string($status_filter) . "'";
}

if ($priority_filter) {
    $where_conditions[] = "t.priority = '" . $conn->real_escape_string($priority_filter) . "'";
}

if ($search) {
    $search = $conn->real_escape_string($search);
    $where_conditions[] = "(t.title LIKE '%$search%' OR t.description LIKE '%$search%')";
}

$where_clause = implode(' AND ', $where_conditions);

// Get tickets
$tickets_query = "SELECT t.*, u.name as created_by_name 
                  FROM tickets t 
                  LEFT JOIN users u ON t.created_by = u.id 
                  WHERE $where_clause 
                  ORDER BY 
                    CASE WHEN t.priority = 'high' THEN 1 
                         WHEN t.priority = 'medium' THEN 2 
                         ELSE 3 END,
                    t.created_at DESC";
$tickets = $conn->query($tickets_query);

logActivity('VIEW_MY_TICKETS', 'Technician viewed their tickets');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Tickets - MCC ICT Helpdesk</title>
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
            <div class="header-title">My Tickets</div>
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

        <!-- Filters -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Filters</h3>
            </div>
            <form method="GET" action="">
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; align-items: end;">
                    <div class="form-group">
                        <label for="search" class="form-label">Search</label>
                        <input type="text" id="search" name="search" class="form-input" placeholder="Search tickets..." value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    <div class="form-group">
                        <label for="status" class="form-label">Status</label>
                        <select id="status" name="status" class="form-select">
                            <option value="">All Status</option>
                            <option value="open" <?php echo $status_filter == 'open' ? 'selected' : ''; ?>>Open</option>
                            <option value="in_progress" <?php echo $status_filter == 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                            <option value="resolved" <?php echo $status_filter == 'resolved' ? 'selected' : ''; ?>>Resolved</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="priority" class="form-label">Priority</label>
                        <select id="priority" name="priority" class="form-select">
                            <option value="">All Priority</option>
                            <option value="high" <?php echo $priority_filter == 'high' ? 'selected' : ''; ?>>High</option>
                            <option value="medium" <?php echo $priority_filter == 'medium' ? 'selected' : ''; ?>>Medium</option>
                            <option value="low" <?php echo $priority_filter == 'low' ? 'selected' : ''; ?>>Low</option>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary">Apply Filters</button>
                    <a href="my_tickets.php" class="btn btn-secondary">Clear</a>
                </div>
            </form>
        </div>

        <!-- Tickets Table -->
        <div class="card" style="margin-top: 1.5rem;">
            <div class="card-header">
                <h3 class="card-title">Assigned Tickets</h3>
                <div>
                    <span style="margin-right: 1rem; color: #94a3b8;">
                        <?php echo $tickets ? $tickets->num_rows : 0; ?> tickets found
                    </span>
                    <button onclick="exportToCSV('ticketsTable', 'my_tickets.csv')" class="btn btn-sm btn-secondary">Export CSV</button>
                </div>
            </div>
            <div class="table-container">
                <table class="table" id="ticketsTable">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Title</th>
                            <th>Category</th>
                            <th>Status</th>
                            <th>Priority</th>
                            <th>Created By</th>
                            <th>Created</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($tickets && $tickets->num_rows > 0): ?>
                            <?php while ($ticket = $tickets->fetch_assoc()): ?>
                                <tr>
                                    <td>#<?php echo $ticket['id']; ?></td>
                                    <td>
                                        <div style="max-width: 200px;">
                                            <strong><?php echo htmlspecialchars($ticket['title']); ?></strong>
                                            <?php if (strlen($ticket['description']) > 100): ?>
                                                <div style="font-size: 0.875rem; color: #94a3b8; margin-top: 0.25rem;">
                                                    <?php echo htmlspecialchars(substr($ticket['description'], 0, 100)) . '...'; ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td><?php echo ucfirst($ticket['category']); ?></td>
                                    <td><?php echo getStatusBadge($ticket['status']); ?></td>
                                    <td><?php echo getPriorityBadge($ticket['priority']); ?></td>
                                    <td><?php echo htmlspecialchars($ticket['created_by_name']); ?></td>
                                    <td><?php echo timeAgo($ticket['created_at']); ?></td>
                                    <td>
                                        <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                                            <?php if ($ticket['status'] != 'resolved'): ?>
                                                <form method="POST" action="" style="display: inline;">
                                                    <input type="hidden" name="action" value="update_status">
                                                    <input type="hidden" name="ticket_id" value="<?php echo $ticket['id']; ?>">
                                                    <select name="new_status" class="form-select" style="width: auto; font-size: 0.75rem; padding: 0.25rem 0.5rem;">
                                                        <option value="">Update</option>
                                                        <option value="open" <?php echo $ticket['status'] == 'open' ? 'selected' : ''; ?>>Open</option>
                                                        <option value="in_progress" <?php echo $ticket['status'] == 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                                                    </select>
                                                    <button type="submit" class="btn btn-sm btn-secondary">Go</button>
                                                </form>
                                                <button onclick="showSolutionForm(<?php echo $ticket['id']; ?>)" class="btn btn-sm btn-success">Resolve</button>
                                            <?php else: ?>
                                                <span style="color: #10b981; font-size: 0.875rem;">✓ Resolved</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="8" style="text-align: center;">No tickets found</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Solution Modal -->
    <div id="solutionModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
        <div class="card" style="width: 90%; max-width: 600px; max-height: 90vh; overflow-y: auto;">
            <div class="card-header">
                <h3 class="card-title">Add Solution</h3>
                <button onclick="closeSolutionModal()" style="background: none; border: none; color: #94a3b8; font-size: 1.5rem; cursor: pointer;">&times;</button>
            </div>
            <form method="POST" action="" id="solutionForm">
                <input type="hidden" name="action" value="add_solution">
                <input type="hidden" id="solution_ticket_id" name="ticket_id">
                <div class="form-group">
                    <label for="solution" class="form-label">Solution Description</label>
                    <textarea id="solution" name="solution" class="form-textarea" rows="6" placeholder="Describe the solution implemented..." required></textarea>
                </div>
                <div style="display: flex; gap: 1rem;">
                    <button type="submit" class="btn btn-success">Submit Solution</button>
                    <button type="button" onclick="closeSolutionModal()" class="btn btn-secondary">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <script src="../assets/js/script.js"></script>
    <script>
        // Solution modal functions
        function showSolutionForm(ticketId) {
            document.getElementById('solution_ticket_id').value = ticketId;
            document.getElementById('solutionModal').style.display = 'flex';
        }

        function closeSolutionModal() {
            document.getElementById('solutionModal').style.display = 'none';
            document.getElementById('solutionForm').reset();
        }

        // Close modal when clicking outside
        document.getElementById('solutionModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeSolutionModal();
            }
        });
    </script>
</body>
</html>
