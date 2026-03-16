<?php
/**
 * All Tickets - Admin View
 * Smart ICT Helpdesk System - Mutare City Council
 */

require_once '../config/auth_helper.php';
require_once '../config/database.php';

requireRole('admin');

$database = new Database();
$conn = $database->getConnection();

// Get filter parameters
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$priority_filter = isset($_GET['priority']) ? $_GET['priority'] : '';
$category_filter = isset($_GET['category']) ? $_GET['category'] : '';
$technician_filter = isset($_GET['technician']) ? $_GET['technician'] : '';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Build query
$where_conditions = [];

if ($status_filter) {
    $where_conditions[] = "t.status = '" . $conn->real_escape_string($status_filter) . "'";
}

if ($priority_filter) {
    $where_conditions[] = "t.priority = '" . $conn->real_escape_string($priority_filter) . "'";
}

if ($category_filter) {
    $where_conditions[] = "t.category = '" . $conn->real_escape_string($category_filter) . "'";
}

if ($technician_filter) {
    $where_conditions[] = "t.assigned_to = " . (int)$technician_filter;
}

if ($search) {
    $search = $conn->real_escape_string($search);
    $where_conditions[] = "(t.title LIKE '%$search%' OR t.description LIKE '%$search%')";
}

$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Get tickets
$tickets_query = "SELECT t.*, u.name as created_by_name, tech.name as assigned_to_name 
                  FROM tickets t 
                  LEFT JOIN users u ON t.created_by = u.id 
                  LEFT JOIN technicians tech ON t.assigned_to = tech.id 
                  $where_clause 
                  ORDER BY 
                    CASE WHEN t.priority = 'high' THEN 1 
                         WHEN t.priority = 'medium' THEN 2 
                         ELSE 3 END,
                    CASE WHEN t.status = 'open' THEN 1 
                         WHEN t.status = 'in_progress' THEN 2 
                         ELSE 3 END,
                    t.created_at DESC";
$tickets = $conn->query($tickets_query);

// Get filter options
$technicians_query = "SELECT id, name FROM technicians ORDER BY name";
$technicians = $conn->query($technicians_query);

// Get statistics
$stats_query = "SELECT 
                   COUNT(*) as total,
                   COUNT(CASE WHEN status = 'open' THEN 1 END) as open,
                   COUNT(CASE WHEN status = 'in_progress' THEN 1 END) as in_progress,
                   COUNT(CASE WHEN status = 'resolved' THEN 1 END) as resolved
                FROM tickets";
$stats = $conn->query($stats_query)->fetch_assoc();

logActivity('VIEW_ALL_TICKETS', 'Admin viewed all tickets');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>All Tickets - MCC ICT Helpdesk</title>
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
            <?php $menu = getNavigationMenu('admin'); ?>
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
            <div class="header-title">All Tickets</div>
            <div class="user-info">
                <span>Welcome, <?php echo htmlspecialchars($_SESSION['user_name']); ?></span>
                <div class="user-avatar"><?php echo strtoupper(substr($_SESSION['user_name'], 0, 1)); ?></div>
            </div>
        </div>

        <?php echo displaySuccess(); ?>
        <?php echo displayError(); ?>

        <!-- Statistics Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value"><?php echo $stats['total']; ?></div>
                <div class="stat-label">Total Tickets</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $stats['open']; ?></div>
                <div class="stat-label">Open Tickets</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $stats['in_progress']; ?></div>
                <div class="stat-label">In Progress</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $stats['resolved']; ?></div>
                <div class="stat-label">Resolved</div>
            </div>
        </div>

        <!-- Filters -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Filters</h3>
                <button onclick="exportToCSV('ticketsTable', 'all_tickets.csv')" class="btn btn-sm btn-secondary">Export CSV</button>
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
                    <div class="form-group">
                        <label for="category" class="form-label">Category</label>
                        <select id="category" name="category" class="form-select">
                            <option value="">All Categories</option>
                            <option value="network" <?php echo $category_filter == 'network' ? 'selected' : ''; ?>>Network</option>
                            <option value="hardware" <?php echo $category_filter == 'hardware' ? 'selected' : ''; ?>>Hardware</option>
                            <option value="software" <?php echo $category_filter == 'software' ? 'selected' : ''; ?>>Software</option>
                            <option value="login" <?php echo $category_filter == 'login' ? 'selected' : ''; ?>>Login</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="technician" class="form-label">Technician</label>
                        <select id="technician" name="technician" class="form-select">
                            <option value="">All Technicians</option>
                            <?php if ($technicians && $technicians->num_rows > 0): ?>
                                <?php while ($tech = $technicians->fetch_assoc()): ?>
                                    <option value="<?php echo $tech['id']; ?>" <?php echo $technician_filter == $tech['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($tech['name']); ?>
                                    </option>
                                <?php endwhile; ?>
                            <?php endif; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary">Apply Filters</button>
                    <a href="all_tickets.php" class="btn btn-secondary">Clear</a>
                </div>
            </form>
        </div>

        <!-- Tickets Table -->
        <div class="card" style="margin-top: 1.5rem;">
            <div class="card-header">
                <h3 class="card-title">All Support Tickets</h3>
                <span style="color: #94a3b8;">
                    <?php echo $tickets ? $tickets->num_rows : 0; ?> tickets found
                </span>
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
                            <th>Assigned To</th>
                            <th>Created</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($tickets && $tickets->num_rows > 0): ?>
                            <?php while ($ticket = $tickets->fetch_assoc()): ?>
                                <tr>
                                    <td><strong>#<?php echo $ticket['id']; ?></strong></td>
                                    <td>
                                        <div style="max-width: 200px;">
                                            <div><?php echo htmlspecialchars($ticket['title']); ?></div>
                                            <?php if (strlen($ticket['description']) > 50): ?>
                                                <div style="font-size: 0.875rem; color: #94a3b8; margin-top: 0.25rem;">
                                                    <?php echo htmlspecialchars(substr($ticket['description'], 0, 50)) . '...'; ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td><?php echo ucfirst($ticket['category']); ?></td>
                                    <td><?php echo getStatusBadge($ticket['status']); ?></td>
                                    <td><?php echo getPriorityBadge($ticket['priority']); ?></td>
                                    <td>
                                        <div>
                                            <?php echo htmlspecialchars($ticket['created_by_name']); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if ($ticket['assigned_to_name']): ?>
                                            <div>
                                                <strong><?php echo htmlspecialchars($ticket['assigned_to_name']); ?></strong>
                                            </div>
                                        <?php else: ?>
                                            <span style="color: #94a3b8; font-style: italic;">Unassigned</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div><?php echo formatDate($ticket['created_at']); ?></div>
                                        <div style="font-size: 0.875rem; color: #94a3b8;"><?php echo timeAgo($ticket['created_at']); ?></div>
                                    </td>
                                    <td>
                                        <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                                            <button onclick="viewTicketDetails(<?php echo $ticket['id']; ?>)" class="btn btn-sm btn-secondary">View</button>
                                            <?php if (!$ticket['assigned_to_name']): ?>
                                                <button onclick="assignTechnician(<?php echo $ticket['id']; ?>)" class="btn btn-sm btn-primary">Assign</button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="9" style="text-align: center; padding: 2rem;">
                                    <div style="color: #94a3b8;">
                                        <div style="font-size: 2rem; margin-bottom: 0.5rem;">📋</div>
                                        <div>No tickets found</div>
                                        <div style="font-size: 0.875rem; margin-top: 0.5rem;">
                                            <?php if ($search || $status_filter || $priority_filter || $category_filter || $technician_filter): ?>
                                                Try adjusting your filters or 
                                                <a href="all_tickets.php" style="color: #3b82f6;">clear all filters</a>
                                            <?php else: ?>
                                                No tickets have been created yet
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Ticket Details Modal -->
    <div id="ticketModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
        <div class="card" style="width: 90%; max-width: 700px; max-height: 90vh; overflow-y: auto;">
            <div class="card-header">
                <h3 class="card-title">Ticket Details</h3>
                <button onclick="closeTicketModal()" style="background: none; border: none; color: #94a3b8; font-size: 1.5rem; cursor: pointer;">&times;</button>
            </div>
            <div id="ticketDetails"></div>
        </div>
    </div>

    <!-- Assign Technician Modal -->
    <div id="assignModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
        <div class="card" style="width: 90%; max-width: 400px;">
            <div class="card-header">
                <h3 class="card-title">Assign Technician</h3>
                <button onclick="closeAssignModal()" style="background: none; border: none; color: #94a3b8; font-size: 1.5rem; cursor: pointer;">&times;</button>
            </div>
            <form method="POST" action="../system/auto_assign.php" id="assignForm">
                <input type="hidden" name="ticket_id" id="assign_ticket_id">
                <div class="form-group">
                    <label for="technician_id" class="form-label">Select Technician</label>
                    <select id="technician_id" name="technician_id" class="form-select" required>
                        <option value="">Choose a technician</option>
                        <?php 
                        // Reset technicians result pointer
                        $technicians->data_seek(0);
                        while ($tech = $technicians->fetch_assoc()): 
                        ?>
                            <option value="<?php echo $tech['id']; ?>"><?php echo htmlspecialchars($tech['name']); ?></option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div style="display: flex; gap: 1rem;">
                    <button type="submit" class="btn btn-primary">Assign</button>
                    <button type="button" onclick="closeAssignModal()" class="btn btn-secondary">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <script src="../assets/js/script.js"></script>
    <script>
        // View ticket details
        function viewTicketDetails(ticketId) {
            // In a real implementation, this would be an AJAX call to fetch ticket details
            const detailsHtml = `
                <div style="padding: 1rem;">
                    <div class="alert alert-info">
                        Loading ticket details for #${ticketId}...
                    </div>
                    <div style="text-align: center; margin-top: 2rem;">
                        <p>In a full implementation, this would show complete ticket details including:</p>
                        <ul style="text-align: left; max-width: 400px; margin: 1rem auto;">
                            <li>Full description and details</li>
                            <li>Complete history and updates</li>
                            <li>Technician notes and solutions</li>
                            <li>Resolution details and time tracking</li>
                            <li>Communication history</li>
                            <li>File attachments (if any)</li>
                        </ul>
                    </div>
                </div>
            `;
            
            document.getElementById('ticketDetails').innerHTML = detailsHtml;
            document.getElementById('ticketModal').style.display = 'flex';
        }

        // Assign technician
        function assignTechnician(ticketId) {
            document.getElementById('assign_ticket_id').value = ticketId;
            document.getElementById('assignModal').style.display = 'flex';
        }

        // Close modals
        function closeTicketModal() {
            document.getElementById('ticketModal').style.display = 'none';
        }

        function closeAssignModal() {
            document.getElementById('assignModal').style.display = 'none';
        }

        // Close modals when clicking outside
        document.getElementById('ticketModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeTicketModal();
            }
        });

        document.getElementById('assignModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeAssignModal();
            }
        });

        // Auto-refresh for real-time updates
        let refreshInterval;
        
        function startAutoRefresh() {
            refreshInterval = setInterval(() => {
                // Only refresh if there are open tickets
                const openTickets = document.querySelectorAll('.badge-open');
                if (openTickets.length > 0) {
                    console.log('Auto-refreshing ticket status...');
                    // In a real implementation, this would be a silent AJAX refresh
                }
            }, 30000); // Refresh every 30 seconds
        }

        // Start auto-refresh when page loads
        document.addEventListener('DOMContentLoaded', function() {
            startAutoRefresh();
        });

        // Stop auto-refresh when page is hidden
        document.addEventListener('visibilitychange', function() {
            if (document.hidden) {
                clearInterval(refreshInterval);
            } else {
                startAutoRefresh();
            }
        });
    </script>
</body>
</html>
