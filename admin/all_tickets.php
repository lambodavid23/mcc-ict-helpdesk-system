<?php
require_once '../config/auth_helper.php';
require_once '../config/database.php';

requireRole('admin');

$database = new Database();
$conn = $database->getConnection();

$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$priority_filter = isset($_GET['priority']) ? $_GET['priority'] : '';
$category_filter = isset($_GET['category']) ? $_GET['category'] : '';
$technician_filter = isset($_GET['technician']) ? $_GET['technician'] : '';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

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

$technicians_query = "SELECT id, name FROM technicians ORDER BY name";
$technicians = $conn->query($technicians_query);

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
            width: 100%;
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
        .cyber-input::placeholder { color: #444; }
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
        .badge-open { background: rgba(239, 68, 68, 0.2); color: #ef4444; }
        .badge-progress { background: rgba(245, 158, 11, 0.2); color: #f59e0b; }
        .badge-resolved { background: rgba(0, 255, 136, 0.2); color: #00ff88; }
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
                <a href="all_tickets.php" class="sidebar-item active">
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
                    <h1 class="text-xl font-bold text-white glow-text">All Tickets</h1>
                    <p class="text-xs text-[#666] mt-0.5">Manage all support tickets</p>
                </div>
            </header>
            
            <!-- Stats -->
            <div class="grid grid-cols-4 gap-4 mb-6">
                <div class="cyber-card p-4">
                    <div class="flex items-center gap-3">
                        <div class="stat-icon">
                            <i data-lucide="ticket" class="w-5 h-5 text-[#00ff88]"></i>
                        </div>
                        <div>
                            <p class="text-2xl font-bold text-white"><?php echo $stats['total']; ?></p>
                            <p class="text-[10px] text-[#666] uppercase">Total</p>
                        </div>
                    </div>
                </div>
                <div class="cyber-card p-4">
                    <div class="flex items-center gap-3">
                        <div class="stat-icon" style="background: rgba(239, 68, 68, 0.1); border-color: rgba(239, 68, 68, 0.2);">
                            <i data-lucide="alert-circle" class="w-5 h-5 text-[#ef4444]"></i>
                        </div>
                        <div>
                            <p class="text-2xl font-bold text-white"><?php echo $stats['open']; ?></p>
                            <p class="text-[10px] text-[#666] uppercase">Open</p>
                        </div>
                    </div>
                </div>
                <div class="cyber-card p-4">
                    <div class="flex items-center gap-3">
                        <div class="stat-icon" style="background: rgba(245, 158, 11, 0.1); border-color: rgba(245, 158, 11, 0.2);">
                            <i data-lucide="clock" class="w-5 h-5 text-[#f59e0b]"></i>
                        </div>
                        <div>
                            <p class="text-2xl font-bold text-white"><?php echo $stats['in_progress']; ?></p>
                            <p class="text-[10px] text-[#666] uppercase">In Progress</p>
                        </div>
                    </div>
                </div>
                <div class="cyber-card p-4">
                    <div class="flex items-center gap-3">
                        <div class="stat-icon" style="background: rgba(59, 130, 246, 0.1); border-color: rgba(59, 130, 246, 0.2);">
                            <i data-lucide="check-circle" class="w-5 h-5 text-[#60a5fa]"></i>
                        </div>
                        <div>
                            <p class="text-2xl font-bold text-white"><?php echo $stats['resolved']; ?></p>
                            <p class="text-[10px] text-[#666] uppercase">Resolved</p>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Filters -->
            <div class="cyber-card p-4 mb-6">
                <form method="GET" class="flex flex-wrap gap-4 items-end">
                    <div class="flex-1 min-w-[200px]">
                        <label class="block text-[10px] text-[#666] uppercase tracking-wider mb-2">Search</label>
                        <div class="relative">
                            <i data-lucide="search" class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-[#444]"></i>
                            <input type="text" name="search" class="cyber-input pl-10" placeholder="Search tickets..." value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                    </div>
                    <div>
                        <label class="block text-[10px] text-[#666] uppercase tracking-wider mb-2">Status</label>
                        <select name="status" class="cyber-select">
                            <option value="">All</option>
                            <option value="open" <?php echo $status_filter == 'open' ? 'selected' : ''; ?>>Open</option>
                            <option value="in_progress" <?php echo $status_filter == 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                            <option value="resolved" <?php echo $status_filter == 'resolved' ? 'selected' : ''; ?>>Resolved</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-[10px] text-[#666] uppercase tracking-wider mb-2">Priority</label>
                        <select name="priority" class="cyber-select">
                            <option value="">All</option>
                            <option value="high" <?php echo $priority_filter == 'high' ? 'selected' : ''; ?>>High</option>
                            <option value="medium" <?php echo $priority_filter == 'medium' ? 'selected' : ''; ?>>Medium</option>
                            <option value="low" <?php echo $priority_filter == 'low' ? 'selected' : ''; ?>>Low</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-[10px] text-[#666] uppercase tracking-wider mb-2">Category</label>
                        <select name="category" class="cyber-select">
                            <option value="">All</option>
                            <option value="network" <?php echo $category_filter == 'network' ? 'selected' : ''; ?>>Network</option>
                            <option value="hardware" <?php echo $category_filter == 'hardware' ? 'selected' : ''; ?>>Hardware</option>
                            <option value="software" <?php echo $category_filter == 'software' ? 'selected' : ''; ?>>Software</option>
                            <option value="login" <?php echo $category_filter == 'login' ? 'selected' : ''; ?>>Login</option>
                        </select>
                    </div>
                    <button type="submit" class="cyber-btn">Apply</button>
                    <a href="all_tickets.php" class="cyber-btn cyber-btn-secondary">Clear</a>
                </form>
            </div>
            
            <!-- Tickets Table -->
            <div class="cyber-card p-4">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-sm font-semibold text-white">Tickets (<?php echo $tickets ? $tickets->num_rows : 0; ?>)</h2>
                </div>
                
                <div class="overflow-x-auto">
                    <table class="cyber-table">
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
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($tickets && $tickets->num_rows > 0): ?>
                                <?php while ($ticket = $tickets->fetch_assoc()): ?>
                                    <tr>
                                        <td class="text-[#00ff88] font-mono">#<?php echo $ticket['id']; ?></td>
                                        <td class="text-white font-medium"><?php echo htmlspecialchars($ticket['title']); ?></td>
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
                                        <td class="text-[#ccc]"><?php echo htmlspecialchars($ticket['created_by_name']); ?></td>
                                        <td class="text-[#666]">
                                            <?php echo $ticket['assigned_to_name'] ? htmlspecialchars($ticket['assigned_to_name']) : '<span class="text-[#444]">Pending</span>'; ?>
                                        </td>
                                        <td class="text-[#666] text-xs"><?php echo date('M d, Y', strtotime($ticket['created_at'])); ?></td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="8" class="text-center text-[#666] py-12">
                                        <div class="flex flex-col items-center gap-3">
                                            <i data-lucide="inbox" class="w-12 h-12 text-[#333]"></i>
                                            <span class="text-sm">No tickets found</span>
                                        </div>
                                    </td>
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
