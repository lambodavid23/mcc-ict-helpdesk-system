<?php
/**
 * Technician Dashboard
 * Smart ICT Helpdesk System - Mutare City Council
 */

require_once '../config/auth_helper.php';
require_once '../config/database.php';

requireRole('technician');

$database = new Database();
$conn = $database->getConnection();

// Get current technician info
$technician_query = "SELECT * FROM technicians WHERE name = '" . $conn->real_escape_string($_SESSION['user_name']) . "'";
$technician = $conn->query($technician_query)->fetch_assoc();

if (!$technician) {
    setError('Technician profile not found. Please contact administrator.');
    header('Location: ../index.php');
    exit();
}

// Get technician statistics
$stats = [];

// Assigned tickets
$result = $conn->query("SELECT COUNT(*) as total FROM tickets WHERE assigned_to = " . $technician['id']);
$stats['assigned_tickets'] = $result->fetch_assoc()['total'];

// Open tickets
$result = $conn->query("SELECT COUNT(*) as total FROM tickets WHERE assigned_to = " . $technician['id'] . " AND status IN ('open', 'in_progress')");
$stats['open_tickets'] = $result->fetch_assoc()['total'];

// Resolved tickets
$result = $conn->query("SELECT COUNT(*) as total FROM tickets WHERE assigned_to = " . $technician['id'] . " AND status = 'resolved'");
$stats['resolved_tickets'] = $result->fetch_assoc()['total'];

// Average resolution time
$result = $conn->query("SELECT AVG(fh.time_to_resolve) as avg_time FROM fault_history fh 
                       JOIN tickets t ON fh.ticket_id = t.id 
                       WHERE fh.resolved_by = " . $technician['id']);
$avg_time = $result->fetch_assoc()['avg_time'];
$stats['avg_resolution_time'] = $avg_time ? round($avg_time, 1) : 0;

// Recent assigned tickets
$recent_tickets_query = "SELECT t.*, u.name as created_by_name 
                         FROM tickets t 
                         LEFT JOIN users u ON t.created_by = u.id 
                         WHERE t.assigned_to = " . $technician['id'] . " 
                         ORDER BY t.created_at DESC 
                         LIMIT 10";
$recent_tickets = $conn->query($recent_tickets_query);

// Tickets by priority
$priority_stats = [];
$result = $conn->query("SELECT priority, COUNT(*) as count FROM tickets 
                       WHERE assigned_to = " . $technician['id'] . " 
                       GROUP BY priority");
while ($row = $result->fetch_assoc()) {
    $priority_stats[$row['priority']] = $row['count'];
}

logActivity('VIEW_TECH_DASHBOARD', 'Technician viewed dashboard');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Technician Dashboard - MCC ICT Helpdesk</title>
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
            <div class="header-title">Technician Dashboard</div>
            <div class="user-info">
                <span><?php echo htmlspecialchars($_SESSION['user_name']); ?> (<?php echo ucfirst($technician['specialization']); ?>)</span>
                <div class="user-avatar"><?php echo strtoupper(substr($_SESSION['user_name'], 0, 1)); ?></div>
            </div>
        </div>

        <?php echo displaySuccess(); ?>
        <?php echo displayError(); ?>

        <!-- Technician Status Card -->
        <div class="card" style="margin-bottom: 1.5rem;">
            <div class="card-header">
                <h3 class="card-title">My Status</h3>
                <span><?php echo getStatusBadge($technician['status']); ?></span>
            </div>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;">
                <div style="text-align: center;">
                    <div style="font-size: 0.875rem; color: #94a3b8; margin-bottom: 0.5rem;">Current Workload</div>
                    <div style="font-size: 2rem; font-weight: 700; color: #f1f5f9;"><?php echo $technician['current_workload']; ?></div>
                </div>
                <div style="text-align: center;">
                    <div style="font-size: 0.875rem; color: #94a3b8; margin-bottom: 0.5rem;">Specialization</div>
                    <div style="font-size: 1.25rem; font-weight: 600; color: #3b82f6;"><?php echo ucfirst($technician['specialization']); ?></div>
                </div>
                <div style="text-align: center;">
                    <div style="font-size: 0.875rem; color: #94a3b8; margin-bottom: 0.5rem;">Contact</div>
                    <div style="font-size: 0.875rem; color: #e2e8f0;">
                        <?php if ($technician['phone']): ?><?php echo htmlspecialchars($technician['phone']); ?><?php endif; ?>
                        <?php if ($technician['email']): ?><br><?php echo htmlspecialchars($technician['email']); ?><?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Statistics Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value"><?php echo $stats['assigned_tickets']; ?></div>
                <div class="stat-label">Assigned Tickets</div>
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
                <div class="stat-value"><?php echo $stats['avg_resolution_time']; ?> min</div>
                <div class="stat-label">Avg Resolution Time</div>
            </div>
        </div>

        <!-- Recent Tickets and Priority Stats -->
        <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 1.5rem;">
            <!-- Recent Tickets -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">My Recent Tickets</h3>
                    <a href="my_tickets.php" class="btn btn-sm btn-secondary">View All</a>
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
                                        <td><?php echo timeAgo($ticket['created_at']); ?></td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" style="text-align: center;">No tickets assigned</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Priority Statistics -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Tickets by Priority</h3>
                </div>
                <div style="padding: 1rem 0;">
                    <?php if (!empty($priority_stats)): ?>
                        <?php 
                        $priorities = ['high' => 'High', 'medium' => 'Medium', 'low' => 'Low'];
                        foreach ($priorities as $key => $label): 
                            $count = isset($priority_stats[$key]) ? $priority_stats[$key] : 0;
                        ?>
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; padding: 0.75rem; background: #0f172a; border-radius: 8px;">
                                <span style="font-weight: 500;"><?php echo $label; ?></span>
                                <span style="background: <?php echo $key == 'high' ? '#ef4444' : ($key == 'medium' ? '#fbbf24' : '#10b981'); ?>; color: white; padding: 0.25rem 0.75rem; border-radius: 9999px; font-size: 0.875rem; font-weight: 600;"><?php echo $count; ?></span>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p style="text-align: center; color: #94a3b8;">No priority data available</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="card" style="margin-top: 1.5rem;">
            <div class="card-header">
                <h3 class="card-title">Quick Actions</h3>
            </div>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;">
                <a href="my_tickets.php" class="btn btn-primary" style="justify-content: center;">
                    📋 View All Tickets
                </a>
                <a href="update_ticket.php" class="btn btn-secondary" style="justify-content: center;">
                    ✏️ Update Ticket Status
                </a>
                <a href="../system/knowledge_base.php" class="btn btn-secondary" style="justify-content: center;">
                    📚 Knowledge Base
                </a>
            </div>
        </div>
    </div>

    <script src="../assets/js/script.js"></script>
</body>
</html>
