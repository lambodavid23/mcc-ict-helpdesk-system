<?php
require_once '../config/auth_helper.php';
require_once '../config/database.php';

requireRole('admin');

$database = new Database();
$conn = $database->getConnection();

// Get dashboard statistics
$stats = [];

$result = $conn->query("SELECT COUNT(*) as total FROM tickets");
$stats['total_tickets'] = $result->fetch_assoc()['total'];

$result = $conn->query("SELECT COUNT(*) as total FROM tickets WHERE status IN ('open', 'in_progress')");
$stats['open_tickets'] = $result->fetch_assoc()['total'];

$result = $conn->query("SELECT COUNT(*) as total FROM tickets WHERE status = 'resolved'");
$stats['resolved_tickets'] = $result->fetch_assoc()['total'];

$result = $conn->query("SELECT COUNT(*) as total FROM technicians");
$stats['total_technicians'] = $result->fetch_assoc()['total'];

require_once '../config/AttendanceService.php';
$attendance = new AttendanceService();
$stats['on_duty_technicians'] = $attendance->getOnDutyCount();

$result = $conn->query("SELECT COUNT(*) as total FROM users");
$stats['total_users'] = $result->fetch_assoc()['total'];

$result = $conn->query("SELECT AVG(time_to_resolve) as avg_time FROM fault_history WHERE time_to_resolve IS NOT NULL");
$avg_time = $result->fetch_assoc()['avg_time'];
$stats['avg_resolution_time'] = $avg_time ? round($avg_time, 1) : 0;

$recent_tickets_query = "SELECT t.*, u.name as created_by_name, tech.name as assigned_to_name 
                        FROM tickets t 
                        LEFT JOIN users u ON t.created_by = u.id 
                        LEFT JOIN technicians tech ON t.assigned_to = tech.id 
                        ORDER BY t.created_at DESC 
                        LIMIT 10";
$recent_tickets = $conn->query($recent_tickets_query);

$category_stats = [];
$result = $conn->query("SELECT category, COUNT(*) as count FROM tickets GROUP BY category");
while ($row = $result->fetch_assoc()) {
    $category_stats[$row['category']] = $row['count'];
}

$pending_users = $conn->query("SELECT id, name, email, department, pending_expires_at FROM users WHERE status = 'pending' ORDER BY created_at DESC");
$pending_count = $pending_users ? $pending_users->num_rows : 0;

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
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { font-family: 'Space Grotesk', sans-serif; }
        body { background: #050507; }
        .grid-bg {
            background-image: 
                linear-gradient(rgba(26, 26, 46, 0.3) 1px, transparent 1px),
                linear-gradient(90deg, rgba(26, 26, 46, 0.3) 1px, transparent 1px);
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
        .cyber-btn {
            background: rgba(10, 10, 15, 0.9);
            border: 1px solid #1a1a2e;
            color: #00ff88;
            padding: 0.5rem 1rem;
            border-radius: 8px;
            font-size: 0.75rem;
            font-weight: 500;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }
        .cyber-btn:hover {
            border-color: #00ff88;
            background: rgba(0, 255, 136, 0.1);
        }
        .cyber-btn-primary {
            background: linear-gradient(135deg, #00ff88, #00cc6a);
            color: #050507;
            border: none;
        }
        .cyber-btn-primary:hover {
            background: linear-gradient(135deg, #00cc6a, #00ff88);
            box-shadow: 0 0 20px rgba(0, 255, 136, 0.3);
        }
        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(0, 255, 136, 0.1);
            border: 1px solid rgba(0, 255, 136, 0.2);
        }
        .cyber-table {
            width: 100%;
            border-collapse: collapse;
        }
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
        .cyber-table tr:hover td {
            background: rgba(0, 255, 136, 0.03);
        }
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
        .badge-progress { background: rgba(245, 158, 11, 0.2); color: #f59e0b; }
        .badge-resolved { background: rgba(0, 255, 136, 0.2); color: #00ff88; }
        .badge-high { background: rgba(239, 68, 68, 0.2); color: #ef4444; }
        .badge-medium { background: rgba(245, 158, 11, 0.2); color: #f59e0b; }
        .badge-low { background: rgba(59, 130, 246, 0.2); color: #60a5fa; }
        .category-bar {
            height: 8px;
            background: rgba(0, 255, 136, 0.1);
            border-radius: 4px;
            overflow: hidden;
            margin-top: 0.5rem;
        }
        .category-fill {
            height: 100%;
            background: linear-gradient(90deg, #00ff88, #00cc6a);
            border-radius: 4px;
            transition: width 0.5s ease;
        }
        @keyframes pulse-green {
            0%, 100% { box-shadow: 0 0 0 0 rgba(0, 255, 136, 0.4); }
            50% { box-shadow: 0 0 0 8px rgba(0, 255, 136, 0); }
        }
        .pulse-indicator {
            width: 8px;
            height: 8px;
            background: #00ff88;
            border-radius: 50%;
            animation: pulse-green 2s infinite;
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
        .sidebar-item.active {
            border-left: 2px solid #00ff88;
        }
    </style>
</head>
<body class="min-h-screen grid-bg">
    <!-- Scan Line -->
    <div class="fixed top-0 left-0 right-0 h-0.5 bg-gradient-to-r from-transparent via-[#00ff88]/20 to-transparent animate-[scan_8s_linear_infinite] pointer-events-none z-50" style="animation: scan 8s linear infinite;"></div>
    
    <div class="flex">
        <!-- Sidebar -->
        <aside class="fixed top-0 left-0 h-screen w-64 bg-[#0a0a0f]/95 border-r border-[#1a1a2e] p-4 flex flex-col overflow-hidden">
            <!-- Logo -->
            <div class="flex items-center gap-3 mb-6 pb-4 border-b border-[#1a1a2e]">
                <img src="../assets/images/mutarelogo.png" alt="MCC" class="w-10 h-10">
                <div>
                    <span class="text-sm font-bold text-white">MCC ICT</span>
                    <p class="text-[10px] text-[#00ff88] uppercase tracking-wider">Admin Panel</p>
                </div>
            </div>
            
            <!-- Navigation -->
            <nav class="flex-1 space-y-1">
                <a href="dashboard.php" class="sidebar-item active">
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
                <a href="reports.php" class="sidebar-item">
                    <i data-lucide="bar-chart-3" class="w-4 h-4"></i>
                    Reports
                </a>
            </nav>
            
            <!-- User Info -->
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
            <!-- Header -->
            <header class="flex items-center justify-between mb-6">
                <div>
                    <h1 class="text-xl font-bold text-white glow-text">Dashboard</h1>
                    <p class="text-xs text-[#666] mt-0.5">System overview and analytics</p>
                </div>
                <div class="flex items-center gap-3">
                    <button type="button" onclick="location.reload()" class="cyber-btn px-3 py-1.5" title="Refresh dashboard">
                        <i data-lucide="refresh-cw" class="w-3.5 h-3.5"></i>
                        Refresh
                    </button>
                    <div class="flex items-center gap-2 text-xs text-[#666]">
                        <div class="pulse-indicator"></div>
                        <span>System Online</span>
                    </div>
                </div>
            </header>
            
            <?php if ($pending_count > 0): ?>
            <div class="cyber-card p-4 mb-6" style="border-color: rgba(251, 191, 36, 0.35);">
                <div class="flex items-center justify-between flex-wrap gap-3">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-lg flex items-center justify-center" style="background: rgba(251, 191, 36, 0.1); border: 1px solid rgba(251, 191, 36, 0.3);">
                            <i data-lucide="user-check" class="w-5 h-5 text-[#fbbf24]"></i>
                        </div>
                        <div>
                            <p class="text-sm font-semibold text-white"><?php echo $pending_count; ?> user<?php echo $pending_count > 1 ? 's' : ''; ?> awaiting approval</p>
                            <p class="text-[11px] text-[#888] mt-0.5">
                                <?php
                                $soonest = null;
                                $pending_users->data_seek(0);
                                while ($p = $pending_users->fetch_assoc()) {
                                    if (!empty($p['pending_expires_at'])) {
                                        $exp = strtotime($p['pending_expires_at']);
                                        if ($soonest === null || $exp < $soonest) $soonest = $exp;
                                    }
                                }
                                if ($soonest) {
                                    $hours = max(0, ceil(($soonest - time()) / 3600));
                                    echo "Expires in $hours hour" . ($hours != 1 ? 's' : '') . " unless approved.";
                                } else {
                                    echo "These need your review.";
                                }
                                ?>
                            </p>
                        </div>
                    </div>
                    <a href="manage_users.php" class="cyber-btn" style="background: rgba(251, 191, 36, 0.1); border-color: rgba(251, 191, 36, 0.3); color: #fbbf24;">
                        <i data-lucide="external-link" class="w-3.5 h-3.5"></i>
                        Review
                    </a>
                </div>
            </div>
            <?php endif; ?>

            <!-- Stats Grid -->
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
                <div class="cyber-card p-4">
                    <div class="flex items-start justify-between">
                        <div>
                            <p class="text-[10px] text-[#666] uppercase tracking-wider mb-1">Total Tickets</p>
                            <p class="text-2xl font-bold text-white"><?php echo $stats['total_tickets']; ?></p>
                        </div>
                        <div class="stat-icon">
                            <i data-lucide="ticket" class="w-5 h-5 text-[#00ff88]"></i>
                        </div>
                    </div>
                </div>
                
                <div class="cyber-card p-4">
                    <div class="flex items-start justify-between">
                        <div>
                            <p class="text-[10px] text-[#666] uppercase tracking-wider mb-1">Open Tickets</p>
                            <p class="text-2xl font-bold text-white"><?php echo $stats['open_tickets']; ?></p>
                        </div>
                        <div class="stat-icon" style="background: rgba(239, 68, 68, 0.1); border-color: rgba(239, 68, 68, 0.2);">
                            <i data-lucide="alert-circle" class="w-5 h-5 text-[#ef4444]"></i>
                        </div>
                    </div>
                </div>
                
                <div class="cyber-card p-4">
                    <div class="flex items-start justify-between">
                        <div>
                            <p class="text-[10px] text-[#666] uppercase tracking-wider mb-1">Resolved</p>
                            <p class="text-2xl font-bold text-white"><?php echo $stats['resolved_tickets']; ?></p>
                        </div>
                        <div class="stat-icon" style="background: rgba(59, 130, 246, 0.1); border-color: rgba(59, 130, 246, 0.2);">
                            <i data-lucide="check-circle" class="w-5 h-5 text-[#60a5fa]"></i>
                        </div>
                    </div>
                </div>
                
                <div class="cyber-card p-4">
                    <div class="flex items-start justify-between">
                        <div>
                            <p class="text-[10px] text-[#666] uppercase tracking-wider mb-1">Technicians On Duty</p>
                            <p class="text-2xl font-bold text-white"><?php echo $stats['on_duty_technicians']; ?><span class="text-sm text-[#666] font-normal">/<?php echo $stats['total_technicians']; ?></span></p>
                        </div>
                        <div class="stat-icon" style="background: rgba(245, 158, 11, 0.1); border-color: rgba(245, 158, 11, 0.2);">
                            <i data-lucide="headphones" class="w-5 h-5 text-[#f59e0b]"></i>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Additional Stats -->
            <div class="grid grid-cols-2 lg:grid-cols-3 gap-4 mb-6">
                <div class="cyber-card p-4">
                    <div class="flex items-center gap-3">
                        <div class="stat-icon" style="background: rgba(139, 92, 246, 0.1); border-color: rgba(139, 92, 246, 0.2);">
                            <i data-lucide="users" class="w-5 h-5 text-[#8b5cf6]"></i>
                        </div>
                        <div>
                            <p class="text-[10px] text-[#666] uppercase tracking-wider">System Users</p>
                            <p class="text-xl font-bold text-white"><?php echo $stats['total_users']; ?></p>
                        </div>
                    </div>
                </div>
                
                <div class="cyber-card p-4">
                    <div class="flex items-center gap-3">
                        <div class="stat-icon">
                            <i data-lucide="clock" class="w-5 h-5 text-[#00ff88]"></i>
                        </div>
                        <div>
                            <p class="text-[10px] text-[#666] uppercase tracking-wider">Avg Resolution</p>
                            <p class="text-xl font-bold text-white"><?php echo $stats['avg_resolution_time']; ?> <span class="text-sm text-[#666] font-normal">min</span></p>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Tables Section -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <!-- Recent Tickets -->
                <div class="lg:col-span-2 cyber-card p-4">
                    <div class="flex items-center justify-between mb-4">
                        <h2 class="text-sm font-semibold text-white">Recent Tickets</h2>
                        <a href="all_tickets.php" class="cyber-btn">
                            <i data-lucide="arrow-right" class="w-3 h-3"></i>
                            View All
                        </a>
                    </div>
                    
                    <div class="overflow-x-auto">
                        <table class="cyber-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Title</th>
                                    <th>Status</th>
                                    <th>Priority</th>
                                    <th>Assigned</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($recent_tickets && $recent_tickets->num_rows > 0): ?>
                                    <?php while ($ticket = $recent_tickets->fetch_assoc()): ?>
                                        <tr>
                                            <td class="text-[#00ff88] font-mono">#<?php echo $ticket['id']; ?></td>
                                            <td class="text-white"><?php echo htmlspecialchars($ticket['title']); ?></td>
                                            <td>
                                                <?php 
                                                $status_class = $ticket['status'] == 'open' ? 'badge-open' : ($ticket['status'] == 'in_progress' ? 'badge-progress' : 'badge-resolved');
                                                echo '<span class="badge ' . $status_class . '">' . ucfirst(str_replace('_', ' ', $ticket['status'])) . '</span>';
                                                ?>
                                            </td>
                                            <td>
                                                <?php 
                                                $priority_class = 'badge-' . $ticket['priority'];
                                                echo '<span class="badge ' . $priority_class . '">' . ucfirst($ticket['priority']) . '</span>';
                                                ?>
                                            </td>
                                            <td class="text-[#666]"><?php echo $ticket['assigned_to_name'] ? htmlspecialchars($ticket['assigned_to_name']) : '<span class="text-[#444]">Unassigned</span>'; ?></td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="5" class="text-center text-[#666]">No tickets found</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <!-- Category Stats -->
                <div class="cyber-card p-4">
                    <h2 class="text-sm font-semibold text-white mb-4">Tickets by Category</h2>
                    
                    <?php if (!empty($category_stats)): ?>
                        <?php 
                        $total = array_sum($category_stats);
                        foreach ($category_stats as $category => $count): 
                            $percentage = $total > 0 ? ($count / $total) * 100 : 0;
                        ?>
                            <div class="mb-4">
                                <div class="flex items-center justify-between text-xs">
                                    <span class="text-[#ccc] capitalize"><?php echo $category; ?></span>
                                    <span class="text-[#00ff88] font-semibold"><?php echo $count; ?></span>
                                </div>
                                <div class="category-bar">
                                    <div class="category-fill" style="width: <?php echo $percentage; ?>%"></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p class="text-center text-[#666] text-sm py-8">No category data available</p>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Top Performers -->
            <div class="cyber-card p-4 mt-6">
                <h2 class="text-sm font-semibold text-white mb-4">Top Performing Technicians</h2>
                
                <table class="cyber-table">
                    <thead>
                        <tr>
                            <th>Technician</th>
                            <th>Resolved</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($top_technicians && $top_technicians->num_rows > 0): ?>
                            <?php while ($tech = $top_technicians->fetch_assoc()): ?>
                                <?php 
                                $tech_query = "SELECT status FROM technicians WHERE name = '" . $conn->real_escape_string($tech['name']) . "'";
                                $tech_details = $conn->query($tech_query)->fetch_assoc();
                                $status_class = $tech_details['status'] == 'available' ? 'badge-resolved' : ($tech_details['status'] == 'busy' ? 'badge-progress' : 'badge-open');
                                ?>
                                <tr>
                                    <td class="flex items-center gap-2">
                                        <div class="w-7 h-7 rounded-lg bg-[#00ff88]/10 border border-[#00ff88]/20 flex items-center justify-center text-[#00ff88] text-xs font-bold">
                                            <?php echo strtoupper(substr($tech['name'], 0, 1)); ?>
                                        </div>
                                        <span class="text-white"><?php echo htmlspecialchars($tech['name']); ?></span>
                                    </td>
                                    <td class="text-[#00ff88] font-semibold"><?php echo $tech['resolved_count']; ?></td>
                                    <td><span class="badge <?php echo $status_class; ?>"><?php echo ucfirst($tech_details['status']); ?></span></td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="3" class="text-center text-[#666]">No performance data available</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </main>
    </div>
    
    <script>
        lucide.createIcons();
    </script>
</body>
</html>
