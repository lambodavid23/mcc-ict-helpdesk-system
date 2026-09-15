<?php
require_once '../config/auth_helper.php';
require_once '../config/database.php';

requireRole('user');

$database = new Database();
$conn = $database->getConnection();

$is_pending = isPendingUser();
$pending_expires_at = null;
if ($is_pending) {
    $uid = (int)$_SESSION['user_id'];
    $exp_result = $conn->query("SELECT pending_expires_at FROM users WHERE id = $uid AND status = 'pending' LIMIT 1");
    if ($exp_result && $exp_result->num_rows > 0) {
        $pending_expires_at = $exp_result->fetch_assoc()['pending_expires_at'];
    }
}

// Get user stats
$my_tickets_query = "SELECT COUNT(*) as total FROM tickets WHERE created_by = " . $_SESSION['user_id'];
$result = $conn->query($my_tickets_query);
$my_total = $result->fetch_assoc()['total'];

$my_open_query = "SELECT COUNT(*) as total FROM tickets WHERE created_by = " . $_SESSION['user_id'] . " AND status IN ('open', 'in_progress')";
$result = $conn->query($my_open_query);
$my_open = $result->fetch_assoc()['total'];

$my_resolved_query = "SELECT COUNT(*) as total FROM tickets WHERE created_by = " . $_SESSION['user_id'] . " AND status = 'resolved'";
$result = $conn->query($my_resolved_query);
$my_resolved = $result->fetch_assoc()['total'];

// Recent tickets
$recent_tickets = $conn->query("SELECT * FROM tickets WHERE created_by = " . $_SESSION['user_id'] . " ORDER BY created_at DESC LIMIT 5");

logActivity('VIEW_USER_DASHBOARD', 'User viewed dashboard');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Dashboard - MCC ICT Helpdesk</title>
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
            font-weight: 600;
        }
        .cyber-btn-primary:hover {
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
        .quick-action {
            background: rgba(10, 10, 15, 0.9);
            border: 1px solid #1a1a2e;
            border-radius: 12px;
            padding: 1.5rem;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.75rem;
            transition: all 0.3s;
            cursor: pointer;
        }
        .quick-action:hover {
            border-color: #00ff88;
            transform: translateY(-2px);
        }
        .quick-action-icon {
            width: 56px;
            height: 56px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(0, 255, 136, 0.1);
            border: 1px solid rgba(0, 255, 136, 0.2);
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
    
    <div class="flex min-h-screen">
        <!-- Sidebar -->
        <aside class="w-64 bg-[#0a0a0f]/95 border-r border-[#1a1a2e] p-4 flex flex-col">
            <!-- Logo -->
            <div class="flex items-center gap-3 mb-6 pb-4 border-b border-[#1a1a2e]">
                <img src="../assets/images/mutarelogo.png" alt="MCC" class="w-10 h-10">
                <div>
                    <span class="text-sm font-bold text-white">MCC ICT</span>
                    <p class="text-[10px] text-[#00ff88] uppercase tracking-wider">User Panel</p>
                </div>
            </div>
            
            <!-- Navigation -->
            <nav class="flex-1 space-y-1">
                <a href="dashboard.php" class="sidebar-item active">
                    <i data-lucide="layout-dashboard" class="w-4 h-4"></i>
                    Dashboard
                </a>
                <a href="submit_ticket.php" class="sidebar-item">
                    <i data-lucide="plus-circle" class="w-4 h-4"></i>
                    New Ticket
                </a>
                <?php if (!$is_pending): ?>
                <a href="my_requests.php" class="sidebar-item">
                    <i data-lucide="ticket" class="w-4 h-4"></i>
                    My Tickets
                </a>
                <?php endif; ?>
            </nav>
            
            <!-- User Info -->
            <div class="pt-4 border-t border-[#1a1a2e]">
                <div class="flex items-center gap-3 mb-3">
                    <div class="w-9 h-9 rounded-lg bg-[#00ff88]/20 border border-[#00ff88]/30 flex items-center justify-center text-[#00ff88] font-bold text-sm">
                        <?php echo strtoupper(substr($_SESSION['user_name'], 0, 1)); ?>
                    </div>
                    <div>
                        <p class="text-xs font-medium text-white"><?php echo htmlspecialchars($_SESSION['user_name']); ?></p>
                        <p class="text-[10px] text-[#666]"><?php echo htmlspecialchars($_SESSION['user_department']); ?></p>
                    </div>
                </div>
                <a href="../auth/logout.php" class="flex items-center gap-2 text-[#666] hover:text-[#ef4444] text-xs transition-colors">
                    <i data-lucide="log-out" class="w-4 h-4"></i>
                    Logout
                </a>
            </div>
        </aside>
        
        <!-- Main Content -->
        <main class="flex-1 p-6">
            <!-- Header -->
            <header class="flex items-center justify-between mb-6">
                <div>
                    <h1 class="text-xl font-bold text-white glow-text">Welcome, <?php echo htmlspecialchars($_SESSION['user_name']); ?></h1>
                    <p class="text-xs text-[#666] mt-0.5"><?php echo htmlspecialchars($_SESSION['user_department']); ?> Department</p>
                </div>
                <div class="flex items-center gap-2 text-xs text-[#666]">
                    <div class="pulse-indicator"></div>
                    <span>System Online</span>
                </div>
            </header>
            
            <?php if ($is_pending && $pending_expires_at): ?>
            <?php
                $exp = strtotime($pending_expires_at);
                $hours_left = max(0, ceil(($exp - time()) / 3600));
                $days_left = floor($hours_left / 24);
                $hours_display = $hours_left % 24;
            ?>
            <div class="cyber-card p-4 mb-6" style="border-color: rgba(251, 191, 36, 0.35);">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-lg flex items-center justify-center" style="background: rgba(251, 191, 36, 0.1); border: 1px solid rgba(251, 191, 36, 0.3);">
                        <i data-lucide="clock" class="w-5 h-5 text-[#fbbf24]"></i>
                    </div>
                    <div>
                        <p class="text-sm font-semibold text-white">Temporary Access Active</p>
                        <p class="text-[11px] text-[#888] mt-0.5">
                            Your account is pending admin approval. Access expires in
                            <span class="text-[#fbbf24] font-semibold">
                                <?php if ($days_left > 0): ?>
                                    <?php echo $days_left; ?> day<?php echo $days_left != 1 ? 's' : ''; ?>
                                    <?php if ($hours_display > 0): ?>, <?php echo $hours_display; ?>h<?php endif; ?>
                                <?php else: ?>
                                    <?php echo $hours_left; ?> hour<?php echo $hours_left != 1 ? 's' : ''; ?>
                                <?php endif; ?>
                            </span>.
                            You can submit tickets only. File uploads are disabled until your account is approved.
                        </p>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($is_pending): ?>
            <style>
                .pending-disabled { opacity: 0.4; pointer-events: none; position: relative; }
                .pending-disabled::after {
                    content: 'Approval Required'; position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%);
                    background: rgba(251, 191, 36, 0.2); border: 1px solid rgba(251, 191, 36, 0.4);
                    color: #fbbf24; padding: 4px 12px; border-radius: 6px; font-size: 10px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.1em; white-space: nowrap; pointer-events: auto;
                }
            </style>
            <?php endif; ?>
            
            <!-- Stats Grid -->
            <div class="grid grid-cols-3 gap-4 mb-6">
                <div class="cyber-card p-4">
                    <div class="flex items-start justify-between">
                        <div>
                            <p class="text-[10px] text-[#666] uppercase tracking-wider mb-1">Total Tickets</p>
                            <p class="text-2xl font-bold text-white"><?php echo $my_total; ?></p>
                        </div>
                        <div class="stat-icon">
                            <i data-lucide="ticket" class="w-5 h-5 text-[#00ff88]"></i>
                        </div>
                    </div>
                </div>
                
                <div class="cyber-card p-4">
                    <div class="flex items-start justify-between">
                        <div>
                            <p class="text-[10px] text-[#666] uppercase tracking-wider mb-1">In Progress</p>
                            <p class="text-2xl font-bold text-white"><?php echo $my_open; ?></p>
                        </div>
                        <div class="stat-icon" style="background: rgba(245, 158, 11, 0.1); border-color: rgba(245, 158, 11, 0.2);">
                            <i data-lucide="clock" class="w-5 h-5 text-[#f59e0b]"></i>
                        </div>
                    </div>
                </div>
                
                <div class="cyber-card p-4">
                    <div class="flex items-start justify-between">
                        <div>
                            <p class="text-[10px] text-[#666] uppercase tracking-wider mb-1">Resolved</p>
                            <p class="text-2xl font-bold text-white"><?php echo $my_resolved; ?></p>
                        </div>
                        <div class="stat-icon" style="background: rgba(59, 130, 246, 0.1); border-color: rgba(59, 130, 246, 0.2);">
                            <i data-lucide="check-circle" class="w-5 h-5 text-[#60a5fa]"></i>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Quick Actions -->
            <h2 class="text-sm font-semibold text-white mb-4">Quick Actions</h2>
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
                <a href="submit_ticket.php" class="quick-action">
                    <div class="quick-action-icon">
                        <i data-lucide="plus" class="w-6 h-6 text-[#00ff88]"></i>
                    </div>
                    <span class="text-xs font-medium text-white">New Ticket</span>
                    <span class="text-[10px] text-[#666]">Submit a request</span>
                </a>
                
                <?php if (!$is_pending): ?>
                <a href="my_requests.php" class="quick-action">
                    <div class="quick-action-icon" style="background: rgba(59, 130, 246, 0.1); border-color: rgba(59, 130, 246, 0.2);">
                        <i data-lucide="list" class="w-6 h-6 text-[#60a5fa]"></i>
                    </div>
                    <span class="text-xs font-medium text-white">My Tickets</span>
                    <span class="text-[10px] text-[#666]">View all requests</span>
                </a>
                <?php endif; ?>
            </div>
            
            <!-- Recent Tickets -->
            <div class="cyber-card p-4">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-sm font-semibold text-white">Recent Tickets</h2>
                    <a href="my_requests.php" class="cyber-btn">
                        <i data-lucide="arrow-right" class="w-3 h-3"></i>
                        View All
                    </a>
                </div>
                
                <table class="cyber-table">
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
                                    <td class="text-[#00ff88] font-mono">#<?php echo $ticket['id']; ?></td>
                                    <td class="text-white"><?php echo htmlspecialchars($ticket['title']); ?></td>
                                    <td class="capitalize"><?php echo $ticket['category']; ?></td>
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
                                    <td class="text-[#666] text-xs"><?php echo date('M d, Y', strtotime($ticket['created_at'])); ?></td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" class="text-center text-[#666] py-8">
                                    <div class="flex flex-col items-center gap-3">
                                        <i data-lucide="inbox" class="w-10 h-10 text-[#333]"></i>
                                        <span>No tickets submitted yet</span>
                                        <a href="submit_ticket.php" class="cyber-btn cyber-btn-primary">Create First Ticket</a>
                                    </div>
                                </td>
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
