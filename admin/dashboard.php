<?php
/**
 * Admin Dashboard
 * Smart ICT Helpdesk System - Mutare City Council
 */

require_once '../config/auth_helper.php';
require_once '../config/database.php';

requireRole('admin');

$database = new Database();
$conn = $database->getConnection();

// Get dashboard statistics
$stats = [];

// Total tickets
$result = $conn->query("SELECT COUNT(*) as total FROM tickets");
$stats['total_tickets'] = $result->fetch_assoc()['total'];

// Open tickets
$result = $conn->query("SELECT COUNT(*) as total FROM tickets WHERE status IN ('open', 'in_progress')");
$stats['open_tickets'] = $result->fetch_assoc()['total'];

// Resolved tickets
$result = $conn->query("SELECT COUNT(*) as total FROM tickets WHERE status = 'resolved'");
$stats['resolved_tickets'] = $result->fetch_assoc()['total'];

// Total technicians
$result = $conn->query("SELECT COUNT(*) as total FROM technicians");
$stats['total_technicians'] = $result->fetch_assoc()['total'];

// Available technicians
$result = $conn->query("SELECT COUNT(*) as total FROM technicians WHERE status = 'available'");
$stats['available_technicians'] = $result->fetch_assoc()['total'];

// Total users
$result = $conn->query("SELECT COUNT(*) as total FROM users WHERE role = 'user'");
$stats['total_users'] = $result->fetch_assoc()['total'];

// Average resolution time
$result = $conn->query("SELECT AVG(time_to_resolve) as avg_time FROM fault_history WHERE time_to_resolve IS NOT NULL");
$avg_time = $result->fetch_assoc()['avg_time'];
$stats['avg_resolution_time'] = $avg_time ? round($avg_time, 1) : 0;

// Recent tickets
$recent_tickets_query = "SELECT t.*, u.name as created_by_name, tech.name as assigned_to_name 
                        FROM tickets t 
                        LEFT JOIN users u ON t.created_by = u.id 
                        LEFT JOIN technicians tech ON t.assigned_to = tech.id 
                        ORDER BY t.created_at DESC 
                        LIMIT 10";
$recent_tickets = $conn->query($recent_tickets_query);

// Tickets by category
$category_stats = [];
$result = $conn->query("SELECT category, COUNT(*) as count FROM tickets GROUP BY category");
while ($row = $result->fetch_assoc()) {
    $category_stats[$row['category']] = $row['count'];
}

// Top technicians by resolved tickets
$top_technicians = $conn->query("SELECT tech.name, COUNT(fh.id) as resolved_count 
                                 FROM technicians tech 
                                 LEFT JOIN fault_history fh ON tech.id = fh.resolved_by 
                                 GROUP BY tech.id, tech.name 
                                 ORDER BY resolved_count DESC 
                                 LIMIT 5");

logActivity('VIEW_ADMIN_DASHBOARD', 'Admin viewed dashboard');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - MCC ICT Helpdesk</title>
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
            <div class="header-title">Admin Dashboard</div>
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
                <div class="stat-value"><?php echo $stats['total_tickets']; ?></div>
                <div class="stat-label">Total Tickets</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $stats['open_tickets']; ?></div>
                <div class="stat-label">Open Tickets</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $stats['resolved_tickets']; ?></div>
                <div class="stat-label">Resolved Tickets</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $stats['total_technicians']; ?></div>
                <div class="stat-label">Total Technicians</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $stats['available_technicians']; ?></div>
                <div class="stat-label">Available Technicians</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $stats['total_users']; ?></div>
                <div class="stat-label">System Users</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $stats['avg_resolution_time']; ?> min</div>
                <div class="stat-label">Avg Resolution Time</div>
            </div>
        </div>

        <!-- Recent Tickets and Category Stats -->
        <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 1.5rem;">
            <!-- Recent Tickets -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Recent Tickets</h3>
                    <a href="all_tickets.php" class="btn btn-sm btn-secondary">View All</a>
                </div>
                <div class="table-container">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Title</th>
                                <th>Category</th>
                                <th>Status</th>
                                <th>Priority</th>
                                <th>Assigned To</th>
                                <th>Created</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($recent_tickets && $recent_tickets->num_rows > 0): ?>
                                <?php while ($ticket = $recent_tickets->fetch_assoc()): ?>
                                    <tr>
                                        <td>#<?php echo $ticket['id']; ?></td>
                                        <td><?php echo htmlspecialchars($ticket['title']); ?></td>
                                        <td><?php echo ucfirst($ticket['category']); ?></td>
                                        <td><?php echo getStatusBadge($ticket['status']); ?></td>
                                        <td><?php echo getPriorityBadge($ticket['priority']); ?></td>
                                        <td><?php echo $ticket['assigned_to_name'] ? htmlspecialchars($ticket['assigned_to_name']) : 'Unassigned'; ?></td>
                                        <td><?php echo timeAgo($ticket['created_at']); ?></td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" style="text-align: center;">No tickets found</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Category Statistics -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Tickets by Category</h3>
                </div>
                <div style="padding: 1rem 0;">
                    <?php if (!empty($category_stats)): ?>
                        <?php foreach ($category_stats as $category => $count): ?>
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; padding: 0.75rem; background: #0f172a; border-radius: 8px;">
                                <span style="text-transform: capitalize; font-weight: 500;"><?php echo $category; ?></span>
                                <span style="background: #3b82f6; color: white; padding: 0.25rem 0.75rem; border-radius: 9999px; font-size: 0.875rem; font-weight: 600;"><?php echo $count; ?></span>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p style="text-align: center; color: #94a3b8;">No category data available</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Top Performers -->
        <div class="card" style="margin-top: 1.5rem;">
            <div class="card-header">
                <h3 class="card-title">Top Performing Technicians</h3>
            </div>
            <div class="table-container">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Technician Name</th>
                            <th>Resolved Tickets</th>
                            <th>Specialization</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($top_technicians && $top_technicians->num_rows > 0): ?>
                            <?php while ($tech = $top_technicians->fetch_assoc()): ?>
                                <?php 
                                // Get technician details
                                $tech_query = "SELECT specialization, status FROM technicians WHERE name = '" . $conn->real_escape_string($tech['name']) . "'";
                                $tech_details = $conn->query($tech_query)->fetch_assoc();
                                ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($tech['name']); ?></td>
                                    <td><?php echo $tech['resolved_count']; ?></td>
                                    <td><?php echo ucfirst($tech_details['specialization']); ?></td>
                                    <td><?php echo getStatusBadge($tech_details['status']); ?></td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="4" style="text-align: center;">No technician performance data available</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script src="../assets/js/script.js"></script>
</body>
</html>
