<?php
/**
 * Reports Dashboard
 * Smart ICT Helpdesk System - Mutare City Council
 */

require_once '../config/auth_helper.php';
require_once '../config/database.php';

requireRole('admin');

$database = new Database();
$conn = $database->getConnection();

// Get date range from GET parameters or default to last 30 days
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d', strtotime('-30 days'));
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');

// Generate reports data
$reports = [];

// Ticket statistics by date range
$ticket_stats_query = "SELECT 
                          COUNT(*) as total_tickets,
                          COUNT(CASE WHEN status = 'resolved' THEN 1 END) as resolved_tickets,
                          COUNT(CASE WHEN status IN ('open', 'in_progress') THEN 1 END) as open_tickets,
                          AVG(CASE WHEN fh.time_to_resolve IS NOT NULL THEN fh.time_to_resolve END) as avg_resolution_time
                       FROM tickets t
                       LEFT JOIN fault_history fh ON t.id = fh.ticket_id
                       WHERE DATE(t.created_at) BETWEEN '$start_date' AND '$end_date'";
$ticket_stats = $conn->query($ticket_stats_query)->fetch_assoc();

// Tickets by category
$category_query = "SELECT category, COUNT(*) as count 
                 FROM tickets 
                 WHERE DATE(created_at) BETWEEN '$start_date' AND '$end_date'
                 GROUP BY category 
                 ORDER BY count DESC";
$category_stats = $conn->query($category_query);

// Tickets by priority
$priority_query = "SELECT priority, COUNT(*) as count 
                  FROM tickets 
                  WHERE DATE(created_at) BETWEEN '$start_date' AND '$end_date'
                  GROUP BY priority 
                  ORDER BY count DESC";
$priority_stats = $conn->query($priority_query);

// Technician performance
$tech_performance_query = "SELECT 
                              tech.name,
                              tech.specialization,
                              COUNT(t.id) as total_assigned,
                              COUNT(CASE WHEN t.status = 'resolved' THEN 1 END) as resolved,
                              AVG(fh.time_to_resolve) as avg_resolution_time
                           FROM technicians tech
                           LEFT JOIN tickets t ON tech.id = t.assigned_to
                           LEFT JOIN fault_history fh ON t.id = fh.ticket_id
                           WHERE DATE(t.created_at) BETWEEN '$start_date' AND '$end_date' OR t.id IS NULL
                           GROUP BY tech.id
                           ORDER BY resolved DESC";
$tech_performance = $conn->query($tech_performance_query);

// Monthly trend
$monthly_trend_query = "SELECT 
                           DATE_FORMAT(created_at, '%Y-%m') as month,
                           COUNT(*) as tickets,
                           COUNT(CASE WHEN status = 'resolved' THEN 1 END) as resolved
                        FROM tickets 
                        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
                        GROUP BY DATE_FORMAT(created_at, '%Y-%m')
                        ORDER BY month";
$monthly_trend = $conn->query($monthly_trend_query);

// Department statistics
$dept_stats_query = "SELECT 
                        department,
                        COUNT(*) as tickets,
                        COUNT(CASE WHEN status = 'resolved' THEN 1 END) as resolved
                     FROM tickets 
                     WHERE DATE(created_at) BETWEEN '$start_date' AND '$end_date'
                     GROUP BY department 
                     ORDER BY tickets DESC";
$dept_stats = $conn->query($dept_stats_query);

logActivity('VIEW_REPORTS', 'Admin viewed reports dashboard');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports - MCC ICT Helpdesk</title>
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
            <div class="header-title">Reports Dashboard</div>
            <div class="user-info">
                <span>Welcome, <?php echo htmlspecialchars($_SESSION['user_name']); ?></span>
                <div class="user-avatar"><?php echo strtoupper(substr($_SESSION['user_name'], 0, 1)); ?></div>
            </div>
        </div>

        <?php echo displaySuccess(); ?>
        <?php echo displayError(); ?>

        <!-- Date Range Filter -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Date Range Filter</h3>
            </div>
            <form method="GET" action="">
                <div style="display: grid; grid-template-columns: 1fr 1fr auto; gap: 1rem; align-items: end;">
                    <div class="form-group">
                        <label for="start_date" class="form-label">Start Date</label>
                        <input type="date" id="start_date" name="start_date" class="form-input" value="<?php echo $start_date; ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="end_date" class="form-label">End Date</label>
                        <input type="date" id="end_date" name="end_date" class="form-input" value="<?php echo $end_date; ?>" required>
                    </div>
                    <button type="submit" class="btn btn-primary">Apply Filter</button>
                </div>
            </form>
        </div>

        <!-- Summary Statistics -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value"><?php echo $ticket_stats['total_tickets']; ?></div>
                <div class="stat-label">Total Tickets</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $ticket_stats['resolved_tickets']; ?></div>
                <div class="stat-label">Resolved Tickets</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $ticket_stats['open_tickets']; ?></div>
                <div class="stat-label">Open Tickets</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo round($ticket_stats['avg_resolution_time'], 1); ?> min</div>
                <div class="stat-label">Avg Resolution Time</div>
            </div>
        </div>

        <!-- Charts Grid -->
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; margin-bottom: 1.5rem;">
            <!-- Tickets by Category -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Tickets by Category</h3>
                </div>
                <div style="padding: 1rem 0;">
                    <?php if ($category_stats && $category_stats->num_rows > 0): ?>
                        <?php while ($category = $category_stats->fetch_assoc()): ?>
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; padding: 0.75rem; background: #0f172a; border-radius: 8px;">
                                <span style="text-transform: capitalize; font-weight: 500;"><?php echo $category['category']; ?></span>
                                <span style="background: #3b82f6; color: white; padding: 0.25rem 0.75rem; border-radius: 9999px; font-size: 0.875rem; font-weight: 600;"><?php echo $category['count']; ?></span>
                            </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <p style="text-align: center; color: #94a3b8;">No data available</p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Tickets by Priority -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Tickets by Priority</h3>
                </div>
                <div style="padding: 1rem 0;">
                    <?php if ($priority_stats && $priority_stats->num_rows > 0): ?>
                        <?php while ($priority = $priority_stats->fetch_assoc()): ?>
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; padding: 0.75rem; background: #0f172a; border-radius: 8px;">
                                <span style="text-transform: capitalize; font-weight: 500;"><?php echo $priority['priority']; ?></span>
                                <span style="background: <?php echo $priority['priority'] == 'high' ? '#ef4444' : ($priority['priority'] == 'medium' ? '#fbbf24' : '#10b981'); ?>; color: white; padding: 0.25rem 0.75rem; border-radius: 9999px; font-size: 0.875rem; font-weight: 600;"><?php echo $priority['count']; ?></span>
                            </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <p style="text-align: center; color: #94a3b8;">No data available</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Technician Performance -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Technician Performance</h3>
                <button onclick="exportToCSV('techPerformanceTable', 'technician_performance.csv')" class="btn btn-sm btn-secondary">Export CSV</button>
            </div>
            <div class="table-container">
                <table class="table" id="techPerformanceTable">
                    <thead>
                        <tr>
                            <th>Technician</th>
                            <th>Specialization</th>
                            <th>Total Assigned</th>
                            <th>Resolved</th>
                            <th>Resolution Rate</th>
                            <th>Avg Resolution Time</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($tech_performance && $tech_performance->num_rows > 0): ?>
                            <?php while ($tech = $tech_performance->fetch_assoc()): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($tech['name']); ?></td>
                                    <td><?php echo ucfirst($tech['specialization']); ?></td>
                                    <td><?php echo $tech['total_assigned']; ?></td>
                                    <td><?php echo $tech['resolved']; ?></td>
                                    <td>
                                        <?php 
                                        $resolution_rate = $tech['total_assigned'] > 0 ? round(($tech['resolved'] / $tech['total_assigned']) * 100, 1) : 0;
                                        echo $resolution_rate . '%';
                                        ?>
                                    </td>
                                    <td><?php echo $tech['avg_resolution_time'] ? round($tech['avg_resolution_time'], 1) . ' min' : 'N/A'; ?></td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" style="text-align: center;">No performance data available</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Department Statistics -->
        <div class="card" style="margin-top: 1.5rem;">
            <div class="card-header">
                <h3 class="card-title">Department Statistics</h3>
                <button onclick="exportToCSV('deptStatsTable', 'department_statistics.csv')" class="btn btn-sm btn-secondary">Export CSV</button>
            </div>
            <div class="table-container">
                <table class="table" id="deptStatsTable">
                    <thead>
                        <tr>
                            <th>Department</th>
                            <th>Total Tickets</th>
                            <th>Resolved Tickets</th>
                            <th>Resolution Rate</th>
                            <th>Open Tickets</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($dept_stats && $dept_stats->num_rows > 0): ?>
                            <?php while ($dept = $dept_stats->fetch_assoc()): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($dept['department']); ?></td>
                                    <td><?php echo $dept['tickets']; ?></td>
                                    <td><?php echo $dept['resolved']; ?></td>
                                    <td>
                                        <?php 
                                        $resolution_rate = round(($dept['resolved'] / $dept['tickets']) * 100, 1);
                                        echo $resolution_rate . '%';
                                        ?>
                                    </td>
                                    <td><?php echo $dept['tickets'] - $dept['resolved']; ?></td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="5" style="text-align: center;">No department data available</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Monthly Trend -->
        <div class="card" style="margin-top: 1.5rem;">
            <div class="card-header">
                <h3 class="card-title">Monthly Trend (Last 12 Months)</h3>
            </div>
            <div class="table-container">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Month</th>
                            <th>Total Tickets</th>
                            <th>Resolved Tickets</th>
                            <th>Resolution Rate</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($monthly_trend && $monthly_trend->num_rows > 0): ?>
                            <?php while ($month = $monthly_trend->fetch_assoc()): ?>
                                <tr>
                                    <td><?php echo date('F Y', strtotime($month['month'] . '-01')); ?></td>
                                    <td><?php echo $month['tickets']; ?></td>
                                    <td><?php echo $month['resolved']; ?></td>
                                    <td>
                                        <?php 
                                        $resolution_rate = round(($month['resolved'] / $month['tickets']) * 100, 1);
                                        echo $resolution_rate . '%';
                                        ?>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="4" style="text-align: center;">No trend data available</td>
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
