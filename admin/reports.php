<?php
require_once '../config/auth_helper.php';
require_once '../config/database.php';

requireRole('admin');

$database = new Database();
$conn = $database->getConnection();

$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d', strtotime('-30 days'));
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');

$ticket_stats_query = "SELECT 
                          COUNT(*) as total_tickets,
                          COUNT(CASE WHEN status = 'resolved' THEN 1 END) as resolved_tickets,
                          COUNT(CASE WHEN status IN ('open', 'in_progress') THEN 1 END) as open_tickets,
                          AVG(CASE WHEN fh.time_to_resolve IS NOT NULL THEN fh.time_to_resolve END) as avg_resolution_time
                       FROM tickets t
                       LEFT JOIN fault_history fh ON t.id = fh.ticket_id
                       WHERE DATE(t.created_at) BETWEEN '$start_date' AND '$end_date'";
$ticket_stats = $conn->query($ticket_stats_query)->fetch_assoc();

$category_query = "SELECT category, COUNT(*) as count 
                 FROM tickets 
                 WHERE DATE(created_at) BETWEEN '$start_date' AND '$end_date'
                 GROUP BY category 
                 ORDER BY count DESC";
$category_stats = $conn->query($category_query);

$priority_query = "SELECT priority, COUNT(*) as count 
                  FROM tickets 
                  WHERE DATE(created_at) BETWEEN '$start_date' AND '$end_date'
                  GROUP BY priority 
                  ORDER BY count DESC";
$priority_stats = $conn->query($priority_query);

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

$monthly_trend_query = "SELECT 
                           DATE_FORMAT(created_at, '%Y-%m') as month,
                           COUNT(*) as tickets,
                           COUNT(CASE WHEN status = 'resolved' THEN 1 END) as resolved
                        FROM tickets 
                        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
                        GROUP BY DATE_FORMAT(created_at, '%Y-%m')
                        ORDER BY month";
$monthly_trend = $conn->query($monthly_trend_query);

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
        .cyber-input, .cyber-select {
            padding: 0.5rem 0.75rem;
            background: rgba(15, 15, 21, 0.8);
            border: 1px solid #1a1a2e;
            border-radius: 6px;
            color: #e0e0e0;
            font-size: 0.8rem;
            transition: all 0.3s;
        }
        .cyber-input:focus, .cyber-select:focus {
            outline: none;
            border-color: #00ff88;
            box-shadow: 0 0 0 2px rgba(0, 255, 136, 0.1);
        }
        .cyber-select option { background: #0a0a0f; }
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
        .cyber-table { width: 100%; border-collapse: collapse; }
        .cyber-table th {
            text-align: left;
            padding: 0.75rem 1rem;
            font-size: 0.65rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            color: #666;
            border-bottom: 1px solid #1a1a2e;
        }
        .cyber-table td {
            padding: 0.875rem 1rem;
            font-size: 0.8rem;
            color: #ccc;
            border-bottom: 1px solid #0f0f15;
        }
        .cyber-table tr:hover td { background: rgba(0, 255, 136, 0.03); }
        .badge {
            display: inline-flex;
            align-items: center;
            padding: 0.2rem 0.5rem;
            border-radius: 9999px;
            font-size: 0.6rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .badge-high { background: rgba(239, 68, 68, 0.2); color: #ef4444; }
        .badge-medium { background: rgba(245, 158, 11, 0.2); color: #f59e0b; }
        .badge-low { background: rgba(59, 130, 246, 0.2); color: #60a5fa; }
        .stat-icon {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(0, 255, 136, 0.1);
            border: 1px solid rgba(0, 255, 136, 0.2);
        }
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
        .sidebar-item.active { border-left: 2px solid #00ff88; }
        .category-bar {
            height: 8px;
            background: rgba(15, 15, 21, 0.8);
            border-radius: 4px;
            overflow: hidden;
        }
        .category-fill {
            height: 100%;
            background: linear-gradient(90deg, #00ff88, #00cc6a);
            border-radius: 4px;
            transition: width 0.5s ease;
        }
    </style>
</head>
<body class="min-h-screen grid-bg">
    <div class="flex">
        <!-- Sidebar -->
        <aside class="fixed top-0 left-0 h-screen w-64 bg-[#0a0a0f]/95 border-r border-[#1a1a2e] p-4 flex flex-col overflow-hidden">
            <div class="flex items-center gap-3 mb-6 pb-4 border-b border-[#1a1a2e]">
                <img src="../assets/images/mutarelogo.png" alt="MCC" class="w-10 h-10">
                <div>
                    <span class="text-sm font-bold text-white">MCC ICT</span>
                    <p class="text-[10px] text-[#00ff88] uppercase tracking-wider">Admin Panel</p>
                </div>
            </div>
            
            <nav class="flex-1 space-y-1">
                <a href="dashboard.php" class="sidebar-item">
                    <i data-lucide="layout-dashboard" class="w-4 h-4"></i>
                    Dashboard
                </a>
                <a href="all_tickets.php" class="sidebar-item">
                    <i data-lucide="ticket" class="w-4 h-4"></i>
                    All Tickets
                </a>
                <a href="manage_users.php" class="sidebar-item">
                    <i data-lucide="users" class="w-4 h-4"></i>
                    Manage Users
                </a>
                <a href="manage_technicians.php" class="sidebar-item">
                    <i data-lucide="headphones" class="w-4 h-4"></i>
                    Technicians
                </a>
                <a href="attendance.php" class="sidebar-item">
                    <i data-lucide="clock" class="w-4 h-4"></i>
                    Attendance
                </a>
                <a href="reports.php" class="sidebar-item active">
                    <i data-lucide="bar-chart-3" class="w-4 h-4"></i>
                    Reports
                </a>
            </nav>
            
            <div class="pt-4 border-t border-[#1a1a2e]">
                <div class="flex items-center gap-3 mb-3">
                    <div class="w-9 h-9 rounded-lg bg-[#00ff88]/20 border border-[#00ff88]/30 flex items-center justify-center text-[#00ff88] font-bold text-sm">
                        <?php echo strtoupper(substr($_SESSION['user_name'], 0, 1)); ?>
                    </div>
                    <div>
                        <p class="text-xs font-medium text-white"><?php echo htmlspecialchars($_SESSION['user_name']); ?></p>
                        <p class="text-[10px] text-[#666]">Administrator</p>
                    </div>
                </div>
                <a href="../auth/logout.php" class="flex items-center gap-2 text-[#666] hover:text-[#ef4444] text-xs transition-colors">
                    <i data-lucide="log-out" class="w-4 h-4"></i>
                    Logout
                </a>
            </div>
        </aside>
        
        <!-- Main Content -->
        <main class="ml-64 flex-1 p-6 h-screen overflow-y-auto">
            <header class="flex items-center justify-between mb-6">
                <div>
                    <h1 class="text-xl font-bold text-white glow-text">Reports</h1>
                    <p class="text-xs text-[#666] mt-0.5">Analytics and performance metrics</p>
                </div>
            </header>
            
            <!-- Date Range Filter -->
            <div class="cyber-card p-4 mb-6">
                <form method="GET" class="flex flex-wrap gap-4 items-end">
                    <div>
                        <label class="block text-[10px] text-[#666] uppercase tracking-wider mb-2">Start Date</label>
                        <input type="date" name="start_date" class="cyber-input" value="<?php echo $start_date; ?>" required>
                    </div>
                    <div>
                        <label class="block text-[10px] text-[#666] uppercase tracking-wider mb-2">End Date</label>
                        <input type="date" name="end_date" class="cyber-input" value="<?php echo $end_date; ?>" required>
                    </div>
                    <button type="submit" class="cyber-btn">
                        <i data-lucide="filter" class="w-4 h-4"></i>
                        Apply Filter
                    </button>
                </form>
            </div>
            
            <!-- Stats -->
            <div class="grid grid-cols-4 gap-4 mb-6">
                <div class="cyber-card p-4">
                    <div class="flex items-center gap-3">
                        <div class="stat-icon">
                            <i data-lucide="ticket" class="w-5 h-5 text-[#00ff88]"></i>
                        </div>
                        <div>
                            <p class="text-2xl font-bold text-white"><?php echo $ticket_stats['total_tickets']; ?></p>
                            <p class="text-[10px] text-[#666] uppercase">Total Tickets</p>
                        </div>
                    </div>
                </div>
                <div class="cyber-card p-4">
                    <div class="flex items-center gap-3">
                        <div class="stat-icon" style="background: rgba(0, 255, 136, 0.1); border-color: rgba(0, 255, 136, 0.2);">
                            <i data-lucide="check-circle" class="w-5 h-5 text-[#00ff88]"></i>
                        </div>
                        <div>
                            <p class="text-2xl font-bold text-white"><?php echo $ticket_stats['resolved_tickets']; ?></p>
                            <p class="text-[10px] text-[#666] uppercase">Resolved</p>
                        </div>
                    </div>
                </div>
                <div class="cyber-card p-4">
                    <div class="flex items-center gap-3">
                        <div class="stat-icon" style="background: rgba(245, 158, 11, 0.1); border-color: rgba(245, 158, 11, 0.2);">
                            <i data-lucide="alert-circle" class="w-5 h-5 text-[#f59e0b]"></i>
                        </div>
                        <div>
                            <p class="text-2xl font-bold text-white"><?php echo $ticket_stats['open_tickets']; ?></p>
                            <p class="text-[10px] text-[#666] uppercase">Open</p>
                        </div>
                    </div>
                </div>
                <div class="cyber-card p-4">
                    <div class="flex items-center gap-3">
                        <div class="stat-icon" style="background: rgba(139, 92, 246, 0.1); border-color: rgba(139, 92, 246, 0.2);">
                            <i data-lucide="clock" class="w-5 h-5 text-[#8b5cf6]"></i>
                        </div>
                        <div>
                            <p class="text-2xl font-bold text-white"><?php echo round($ticket_stats['avg_resolution_time'] ?? 0, 1); ?><span class="text-sm text-[#666] font-normal">min</span></p>
                            <p class="text-[10px] text-[#666] uppercase">Avg Resolution</p>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Charts Grid -->
            <div class="grid grid-cols-2 gap-6 mb-6">
                <!-- Tickets by Category -->
                <div class="cyber-card p-4">
                    <h2 class="text-sm font-semibold text-white mb-4 flex items-center gap-2">
                        <i data-lucide="folder" class="w-4 h-4 text-[#00ff88]"></i>
                        Tickets by Category
                    </h2>
                    <?php if ($category_stats && $category_stats->num_rows > 0): ?>
                        <?php 
                        $category_data = [];
                        while ($cat = $category_stats->fetch_assoc()) {
                            $category_data[] = $cat;
                        }
                        $total_cat = array_sum(array_column($category_data, 'count'));
                        foreach ($category_data as $cat): 
                            $pct = $total_cat > 0 ? ($cat['count'] / $total_cat) * 100 : 0;
                        ?>
                            <div class="mb-4">
                                <div class="flex items-center justify-between text-xs mb-1">
                                    <span class="text-[#ccc] capitalize"><?php echo $cat['category']; ?></span>
                                    <span class="text-[#00ff88] font-semibold"><?php echo $cat['count']; ?></span>
                                </div>
                                <div class="category-bar">
                                    <div class="category-fill" style="width: <?php echo $pct; ?>%;"></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p class="text-center text-[#666] py-8">No category data available</p>
                    <?php endif; ?>
                </div>
                
                <!-- Tickets by Priority -->
                <div class="cyber-card p-4">
                    <h2 class="text-sm font-semibold text-white mb-4 flex items-center gap-2">
                        <i data-lucide="alert-triangle" class="w-4 h-4 text-[#00ff88]"></i>
                        Tickets by Priority
                    </h2>
                    <?php if ($priority_stats && $priority_stats->num_rows > 0): ?>
                        <?php 
                        $priority_data = [];
                        while ($pri = $priority_stats->fetch_assoc()) {
                            $priority_data[] = $pri;
                        }
                        $total_pri = array_sum(array_column($priority_data, 'count'));
                        $priority_colors = ['high' => '#ef4444', 'medium' => '#f59e0b', 'low' => '#60a5fa'];
                        foreach ($priority_data as $pri): 
                            $pct = $total_pri > 0 ? ($pri['count'] / $total_pri) * 100 : 0;
                            $color = $priority_colors[$pri['priority']] ?? '#666';
                        ?>
                            <div class="mb-4">
                                <div class="flex items-center justify-between text-xs mb-1">
                                    <span class="capitalize" style="color: <?php echo $color; ?>;"><?php echo $pri['priority']; ?></span>
                                    <span class="font-semibold" style="color: <?php echo $color; ?>;"><?php echo $pri['count']; ?></span>
                                </div>
                                <div class="category-bar">
                                    <div class="category-fill" style="width: <?php echo $pct; ?>%; background: <?php echo $color; ?>;"></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p class="text-center text-[#666] py-8">No priority data available</p>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Technician Performance -->
            <div class="cyber-card p-4 mb-6">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-sm font-semibold text-white flex items-center gap-2">
                        <i data-lucide="trending-up" class="w-4 h-4 text-[#00ff88]"></i>
                        Technician Performance
                    </h2>
                </div>
                
                <div class="overflow-x-auto">
                    <table class="cyber-table">
                        <thead>
                            <tr>
                                <th>Technician</th>
                                <th>Specialization</th>
                                <th>Assigned</th>
                                <th>Resolved</th>
                                <th>Resolution Rate</th>
                                <th>Avg Time</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($tech_performance && $tech_performance->num_rows > 0): ?>
                                <?php while ($tech = $tech_performance->fetch_assoc()): ?>
                                    <?php 
                                    $resolution_rate = $tech['total_assigned'] > 0 ? round(($tech['resolved'] / $tech['total_assigned']) * 100, 1) : 0;
                                    ?>
                                    <tr>
                                        <td class="text-white font-medium flex items-center gap-2">
                                            <div class="w-7 h-7 rounded-lg bg-[#00ff88]/10 border border-[#00ff88]/20 flex items-center justify-center text-[#00ff88] text-xs font-bold">
                                                <?php echo strtoupper(substr($tech['name'], 0, 1)); ?>
                                            </div>
                                            <?php echo htmlspecialchars($tech['name']); ?>
                                        </td>
                                        <td class="capitalize text-[#ccc]"><?php echo $tech['specialization']; ?></td>
                                        <td class="text-[#ccc]"><?php echo $tech['total_assigned']; ?></td>
                                        <td class="text-[#00ff88] font-semibold"><?php echo $tech['resolved']; ?></td>
                                        <td>
                                            <div class="flex items-center gap-2">
                                                <div class="category-bar w-20">
                                                    <div class="category-fill" style="width: <?php echo $resolution_rate; ?>%;"></div>
                                                </div>
                                                <span class="text-xs text-[#00ff88]"><?php echo $resolution_rate; ?>%</span>
                                            </div>
                                        </td>
                                        <td class="text-[#666]"><?php echo $tech['avg_resolution_time'] ? round($tech['avg_resolution_time'], 1) . ' min' : 'N/A'; ?></td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" class="text-center text-[#666] py-8">No performance data available</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <!-- Department Statistics -->
            <div class="cyber-card p-4 mb-6">
                <h2 class="text-sm font-semibold text-white mb-4 flex items-center gap-2">
                    <i data-lucide="building" class="w-4 h-4 text-[#00ff88]"></i>
                    Department Statistics
                </h2>
                
                <div class="overflow-x-auto">
                    <table class="cyber-table">
                        <thead>
                            <tr>
                                <th>Department</th>
                                <th>Total Tickets</th>
                                <th>Resolved</th>
                                <th>Resolution Rate</th>
                                <th>Open</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($dept_stats && $dept_stats->num_rows > 0): ?>
                                <?php while ($dept = $dept_stats->fetch_assoc()): ?>
                                    <?php 
                                    $resolution_rate = $dept['tickets'] > 0 ? round(($dept['resolved'] / $dept['tickets']) * 100, 1) : 0;
                                    $open = $dept['tickets'] - $dept['resolved'];
                                    ?>
                                    <tr>
                                        <td class="text-white font-medium"><?php echo htmlspecialchars($dept['department']); ?></td>
                                        <td class="text-[#ccc]"><?php echo $dept['tickets']; ?></td>
                                        <td class="text-[#00ff88]"><?php echo $dept['resolved']; ?></td>
                                        <td>
                                            <div class="flex items-center gap-2">
                                                <div class="category-bar w-20">
                                                    <div class="category-fill" style="width: <?php echo $resolution_rate; ?>%;"></div>
                                                </div>
                                                <span class="text-xs text-[#00ff88]"><?php echo $resolution_rate; ?>%</span>
                                            </div>
                                        </td>
                                        <td class="<?php echo $open > 0 ? 'text-[#f59e0b]' : 'text-[#666]'; ?>"><?php echo $open; ?></td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" class="text-center text-[#666] py-8">No department data available</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <!-- Monthly Trend -->
            <div class="cyber-card p-4">
                <h2 class="text-sm font-semibold text-white mb-4 flex items-center gap-2">
                    <i data-lucide="calendar" class="w-4 h-4 text-[#00ff88]"></i>
                    Monthly Trend (Last 12 Months)
                </h2>
                
                <div class="overflow-x-auto">
                    <table class="cyber-table">
                        <thead>
                            <tr>
                                <th>Month</th>
                                <th>Total Tickets</th>
                                <th>Resolved</th>
                                <th>Resolution Rate</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($monthly_trend && $monthly_trend->num_rows > 0): ?>
                                <?php while ($month = $monthly_trend->fetch_assoc()): ?>
                                    <?php 
                                    $resolution_rate = $month['tickets'] > 0 ? round(($month['resolved'] / $month['tickets']) * 100, 1) : 0;
                                    ?>
                                    <tr>
                                        <td class="text-white font-medium"><?php echo date('F Y', strtotime($month['month'] . '-01')); ?></td>
                                        <td class="text-[#ccc]"><?php echo $month['tickets']; ?></td>
                                        <td class="text-[#00ff88]"><?php echo $month['resolved']; ?></td>
                                        <td>
                                            <div class="flex items-center gap-2">
                                                <div class="category-bar w-20">
                                                    <div class="category-fill" style="width: <?php echo $resolution_rate; ?>%;"></div>
                                                </div>
                                                <span class="text-xs text-[#00ff88]"><?php echo $resolution_rate; ?>%</span>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="4" class="text-center text-[#666] py-8">No trend data available</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>
    
    <script>
        lucide.createIcons();
    </script>
</body>
</html>
