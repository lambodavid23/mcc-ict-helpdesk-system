<?php
/**
 * My Requests - User View
 * Smart ICT Helpdesk System - Mutare City Council
 */

require_once '../config/auth_helper.php';
require_once '../config/database.php';

requireRole('user');

$database = new Database();
$conn = $database->getConnection();

// Get filter parameters
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$priority_filter = isset($_GET['priority']) ? $_GET['priority'] : '';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Build query
$where_conditions = ["created_by = " . $_SESSION['user_id']];

if ($status_filter) {
    $where_conditions[] = "status = '" . $conn->real_escape_string($status_filter) . "'";
}

if ($priority_filter) {
    $where_conditions[] = "priority = '" . $conn->real_escape_string($priority_filter) . "'";
}

if ($search) {
    $search = $conn->real_escape_string($search);
    $where_conditions[] = "(title LIKE '%$search%' OR description LIKE '%$search%')";
}

$where_clause = implode(' AND ', $where_conditions);

// Get tickets
$tickets_query = "SELECT t.*, tech.name as assigned_to_name, tech.specialization 
                  FROM tickets t 
                  LEFT JOIN technicians tech ON t.assigned_to = tech.id 
                  WHERE $where_clause 
                  ORDER BY 
                    CASE WHEN t.status = 'open' THEN 1 
                         WHEN t.status = 'in_progress' THEN 2 
                         ELSE 3 END,
                    CASE WHEN t.priority = 'high' THEN 1 
                         WHEN t.priority = 'medium' THEN 2 
                         ELSE 3 END,
                    t.created_at DESC";
$tickets = $conn->query($tickets_query);

// Get statistics
$stats_query = "SELECT 
                   COUNT(*) as total,
                   COUNT(CASE WHEN status = 'open' THEN 1 END) as open,
                   COUNT(CASE WHEN status = 'in_progress' THEN 1 END) as in_progress,
                   COUNT(CASE WHEN status = 'resolved' THEN 1 END) as resolved
                FROM tickets 
                WHERE created_by = " . $_SESSION['user_id'];
$stats = $conn->query($stats_query)->fetch_assoc();

logActivity('VIEW_MY_REQUESTS', 'User viewed their ticket requests');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Requests - MCC ICT Helpdesk</title>
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
            <?php $menu = getNavigationMenu('user'); ?>
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
            <div class="header-title">My Support Requests</div>
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
                <div class="stat-label">Total Requests</div>
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

        <!-- Filters and New Ticket Button -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Filters & Actions</h3>
                <a href="submit_ticket.php" class="btn btn-primary">+ New Ticket</a>
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
                    <a href="my_requests.php" class="btn btn-secondary">Clear</a>
                </div>
            </form>
        </div>

        <!-- Tickets Table -->
        <div class="card" style="margin-top: 1.5rem;">
            <div class="card-header">
                <h3 class="card-title">Your Support Requests</h3>
                <div>
                    <span style="margin-right: 1rem; color: #94a3b8;">
                        <?php echo $tickets ? $tickets->num_rows : 0; ?> tickets found
                    </span>
                    <button onclick="exportToCSV('ticketsTable', 'my_requests.csv')" class="btn btn-sm btn-secondary">Export CSV</button>
                </div>
            </div>
            <div class="table-container">
                <table class="table" id="ticketsTable">
                    <thead>
                        <tr>
                            <th>Ticket ID</th>
                            <th>Title</th>
                            <th>Category</th>
                            <th>Status</th>
                            <th>Priority</th>
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
                                        <div style="max-width: 250px;">
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
                                        <?php if ($ticket['assigned_to_name']): ?>
                                            <div>
                                                <strong><?php echo htmlspecialchars($ticket['assigned_to_name']); ?></strong>
                                                <?php if ($ticket['specialization']): ?>
                                                    <div style="font-size: 0.75rem; color: #94a3b8;">
                                                        <?php echo ucfirst($ticket['specialization']); ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        <?php else: ?>
                                            <span style="color: #94a3b8; font-style: italic;">Not assigned yet</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div><?php echo formatDate($ticket['created_at']); ?></div>
                                        <div style="font-size: 0.875rem; color: #94a3b8;"><?php echo timeAgo($ticket['created_at']); ?></div>
                                    </td>
                                    <td>
                                        <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                                            <button onclick="viewTicketDetails(<?php echo $ticket['id']; ?>)" class="btn btn-sm btn-secondary">View</button>
                                            <?php if ($ticket['status'] == 'resolved'): ?>
                                                <button onclick="reopenTicket(<?php echo $ticket['id']; ?>)" class="btn btn-sm btn-primary">Reopen</button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="8" style="text-align: center; padding: 2rem;">
                                    <div style="color: #94a3b8;">
                                        <div style="font-size: 2rem; margin-bottom: 0.5rem;">📋</div>
                                        <div>No tickets found</div>
                                        <div style="font-size: 0.875rem; margin-top: 0.5rem;">
                                            <?php if ($search || $status_filter || $priority_filter): ?>
                                                Try adjusting your filters or 
                                                <a href="my_requests.php" style="color: #3b82f6;">clear all filters</a>
                                            <?php else: ?>
                                                <a href="submit_ticket.php" style="color: #3b82f6;">Submit your first ticket</a>
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

    <script src="../assets/js/script.js"></script>
    <script>
        // View ticket details
        function viewTicketDetails(ticketId) {
            // In a real implementation, this would be an AJAX call to fetch ticket details
            // For now, we'll show a placeholder
            const detailsHtml = `
                <div style="padding: 1rem;">
                    <div class="alert alert-info">
                        Loading ticket details for #${ticketId}...
                    </div>
                    <div style="text-align: center; margin-top: 2rem;">
                        <p>In a full implementation, this would show complete ticket details including:</p>
                        <ul style="text-align: left; max-width: 400px; margin: 1rem auto;">
                            <li>Full description</li>
                            <li>Complete history and updates</li>
                            <li>Technician notes and solutions</li>
                            <li>Resolution details</li>
                            <li>Communication history</li>
                        </ul>
                    </div>
                </div>
            `;
            
            document.getElementById('ticketDetails').innerHTML = detailsHtml;
            document.getElementById('ticketModal').style.display = 'flex';
        }

        // Close modal
        function closeTicketModal() {
            document.getElementById('ticketModal').style.display = 'none';
        }

        // Reopen ticket
        function reopenTicket(ticketId) {
            if (confirm('Are you sure you want to reopen this ticket? Please provide a reason for reopening.')) {
                // In a real implementation, this would submit an AJAX request
                alert('Ticket #' + ticketId + ' would be reopened. This feature requires backend implementation.');
            }
        }

        // Close modal when clicking outside
        document.getElementById('ticketModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeTicketModal();
            }
        });

        // Auto-refresh for open tickets
        let refreshInterval;
        
        function startAutoRefresh() {
            refreshInterval = setInterval(() => {
                // Only refresh if there are open tickets
                const openTickets = document.querySelectorAll('.badge-open');
                if (openTickets.length > 0) {
                    // In a real implementation, this would be a silent AJAX refresh
                    console.log('Auto-refreshing ticket status...');
                }
            }, 30000); // Refresh every 30 seconds
        }

        function stopAutoRefresh() {
            if (refreshInterval) {
                clearInterval(refreshInterval);
            }
        }

        // Start auto-refresh when page loads
        document.addEventListener('DOMContentLoaded', function() {
            startAutoRefresh();
        });

        // Stop auto-refresh when page is hidden
        document.addEventListener('visibilitychange', function() {
            if (document.hidden) {
                stopAutoRefresh();
            } else {
                startAutoRefresh();
            }
        });
    </script>
</body>
</html>
