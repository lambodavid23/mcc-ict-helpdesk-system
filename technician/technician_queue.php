<?php
require_once '../config/auth_helper.php';
require_once '../config/database.php';
require_once '../config/NotificationService.php';

requireRole('technician');

$database = new Database();
$conn = $database->getConnection();
$notifications = new NotificationService();

$user_id = $_SESSION['user_id'];

$technician = $conn->query("SELECT * FROM technicians WHERE id = $user_id")->fetch_assoc();

if (!$technician) {
    $user_name = $conn->real_escape_string($_SESSION['user_name']);
    $technician = $conn->query("SELECT * FROM technicians WHERE name = '$user_name' LIMIT 1")->fetch_assoc();
}

if (!$technician) {
    $technician = [
        'id' => 0,
        'name' => $_SESSION['user_name'],
        'specialization' => 'general',
        'status' => 'available',
        'current_workload' => 0
    ];
}

$tech_id = $technician['id'];

require_once '../config/TicketActionService.php';
$ticket_actions = new TicketActionService();
$deletions_remaining = $ticket_actions->deletionsRemaining($tech_id);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $ticket_id = (int)($_POST['ticket_id'] ?? 0);
    $result = null;

    if ($action === 'update_status') {
        $result = $ticket_actions->updateStatus(
            $ticket_id,
            $tech_id,
            $_POST['new_status'] ?? '',
            $_POST['resolution'] ?? ''
        );
    } elseif ($action === 'delete_status_update') {
        $result = $ticket_actions->deleteStatusUpdate(
            $ticket_id,
            $tech_id,
            (int)($_POST['assignment_id'] ?? 0)
        );
    } elseif ($action === 'delete_solution') {
        $result = $ticket_actions->deleteSolution(
            $ticket_id,
            $tech_id,
            (int)($_POST['history_id'] ?? 0)
        );
    } elseif ($action === 'edit_solution') {
        $result = $ticket_actions->editSolution(
            (int)($_POST['history_id'] ?? 0),
            $tech_id,
            $_POST['solution'] ?? ''
        );
    }

    if ($result) {
        $_SESSION['flash_message'] = $result['success'] ? [true, $result['message']] : [false, $result['message']];
    }
    header('Location: technician_queue.php');
    exit;
}

$flash_message = $_SESSION['flash_message'] ?? null;
unset($_SESSION['flash_message']);

$stats = [
    'total' => $conn->query("SELECT COUNT(*) as c FROM tickets WHERE assigned_to = $tech_id")->fetch_assoc()['c'] ?? 0,
    'open' => $conn->query("SELECT COUNT(*) as c FROM tickets WHERE assigned_to = $tech_id AND status = 'open'")->fetch_assoc()['c'] ?? 0,
    'in_progress' => $conn->query("SELECT COUNT(*) as c FROM tickets WHERE assigned_to = $tech_id AND status = 'in_progress'")->fetch_assoc()['c'] ?? 0,
    'resolved' => $conn->query("SELECT COUNT(*) as c FROM tickets WHERE assigned_to = $tech_id AND status = 'resolved'")->fetch_assoc()['c'] ?? 0,
];

$tickets_query = $tech_id > 0 
    ? "SELECT t.*, u.name as created_by_name 
       FROM tickets t 
       LEFT JOIN users u ON t.created_by = u.id 
       WHERE t.assigned_to = $tech_id 
       ORDER BY 
          CASE WHEN t.priority = 'high' THEN 1 
               WHEN t.priority = 'medium' THEN 2 
               ELSE 3 END,
          CASE WHEN t.status = 'in_progress' THEN 1 
               WHEN t.status = 'open' THEN 2 
               ELSE 3 END,
          t.created_at DESC"
    : "SELECT t.*, u.name as created_by_name 
       FROM tickets t 
       LEFT JOIN users u ON t.created_by = u.id 
       WHERE 1=0 LIMIT 0";
$tickets = $conn->query($tickets_query);

$unassigned_query = $tech_id > 0
    ? "SELECT t.*, u.name as created_by_name 
       FROM tickets t 
       LEFT JOIN users u ON t.created_by = u.id 
       WHERE t.assigned_to IS NULL AND t.category = '{$technician['specialization']}'
       ORDER BY 
          CASE WHEN t.priority = 'high' THEN 1 
               WHEN t.priority = 'medium' THEN 2 
               ELSE 3 END,
          t.created_at DESC
       LIMIT 5"
    : "SELECT t.*, u.name as created_by_name 
       FROM tickets t 
       LEFT JOIN users u ON t.created_by = u.id 
       WHERE 1=0 LIMIT 0";
$unassigned = $conn->query($unassigned_query);

$recent_comments = $conn->query("SELECT tc.*, t.title as ticket_title, COALESCE(u.name, tech.name, a.name, 'Unknown') as user_name 
                                  FROM ticket_comments tc 
                                  JOIN tickets t ON tc.ticket_id = t.id 
                                  LEFT JOIN users u ON tc.user_type = 'user' AND tc.user_id = u.id
                                  LEFT JOIN technicians tech ON tc.user_type = 'technician' AND tc.user_id = tech.id
                                  LEFT JOIN admins a ON tc.user_type = 'admin' AND tc.user_id = a.id
                                  WHERE t.assigned_to = $tech_id AND tc.is_internal = 0
                                  ORDER BY tc.created_at DESC 
                                  LIMIT 5");

$success = '';
if (isset($_GET['claimed'])) {
    $success = 'Ticket claimed successfully';
}

logActivity('VIEW_TECHNICIAN_QUEUE', 'Technician viewed their queue');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Queue - MCC ICT Helpdesk</title>
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
        .cyber-btn-orange { background: linear-gradient(135deg, #f59e0b, #d97706); color: white; }
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
        .badge-in_progress { background: rgba(245, 158, 11, 0.2); color: #f59e0b; }
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
        .ticket-card {
            padding: 1rem;
            background: rgba(15, 15, 21, 0.8);
            border: 1px solid #1a1a2e;
            border-radius: 8px;
            margin-bottom: 0.75rem;
            transition: all 0.3s;
        }
        .ticket-card:hover {
            border-color: #00ff88;
            background: rgba(0, 255, 136, 0.03);
        }
        .alert { padding: 0.75rem 1rem; border-radius: 8px; font-size: 0.8rem; margin-bottom: 1rem; }
        .alert-success { background: rgba(0, 255, 136, 0.1); border: 1px solid rgba(0, 255, 136, 0.3); color: #00ff88; }
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
    </style>
</head>
<body class="min-h-screen grid-bg">
    <div class="flex">
        <aside class="fixed top-0 left-0 h-screen w-64 bg-[#0a0a0f]/95 border-r border-[#1a1a2e] p-4 flex flex-col overflow-hidden">
            <div class="flex items-center gap-3 mb-6 pb-4 border-b border-[#1a1a2e]">
                <img src="../assets/images/mutarelogo.png" alt="MCC" class="w-10 h-10">
                <div>
                    <span class="text-sm font-bold text-white">MCC ICT</span>
                    <p class="text-[10px] text-[#00ff88] uppercase tracking-wider">Technician Panel</p>
                </div>
            </div>
            
            <nav class="flex-1 space-y-1">
                <a href="dashboard.php" class="sidebar-item">
                    <i data-lucide="layout-dashboard" class="w-4 h-4"></i>
                    Dashboard
                </a>
                <a href="technician_queue.php" class="sidebar-item active">
                    <i data-lucide="list-checks" class="w-4 h-4"></i>
                    My Queue
                </a>
                <a href="technician_history.php" class="sidebar-item">
                    <i data-lucide="history" class="w-4 h-4"></i>
                    History
                </a>
            </nav>
            
            <div class="pt-4 border-t border-[#1a1a2e]">
                <div class="flex items-center gap-3 mb-3">
                    <div class="w-9 h-9 rounded-lg bg-[#00ff88]/20 border border-[#00ff88]/30 flex items-center justify-center text-[#00ff88] font-bold text-sm">
                        <?php echo strtoupper(substr($_SESSION['user_name'], 0, 1)); ?>
                    </div>
                    <div>
                        <p class="text-xs font-medium text-white"><?php echo htmlspecialchars($_SESSION['user_name']); ?></p>
                        <p class="text-[10px] text-[#666] capitalize"><?php echo $technician['specialization']; ?></p>
                    </div>
                </div>
                <div class="flex items-center gap-2 mb-2">
                    <div class="pulse-indicator"></div>
                    <span class="text-[10px] text-[#00ff88] capitalize"><?php echo $technician['status']; ?></span>
                </div>
                <a href="../auth/logout.php" class="flex items-center gap-2 text-[#666] hover:text-[#ef4444] text-xs transition-colors">
                    <i data-lucide="log-out" class="w-4 h-4"></i>
                    Logout
                </a>
            </div>
        </aside>
        
        <main class="ml-64 flex-1 p-6 h-screen overflow-y-auto">
            <?php if ($success): ?>
                <div class="alert alert-success flex items-center gap-2">
                    <i data-lucide="check-circle" class="w-4 h-4"></i>
                    <?php echo $success; ?>
                </div>
            <?php endif; ?>
            
            <header class="flex items-center justify-between mb-6">
                <div>
                    <h1 class="text-xl font-bold text-white glow-text">My Queue</h1>
                    <p class="text-xs text-[#666] mt-0.5">Manage your assigned tickets</p>
                </div>
                <div class="text-xs text-[#666]">
                    <span class="text-[#00ff88]"><?php echo $stats['in_progress']; ?></span> in progress | 
                    <span class="text-[#ef4444]"><?php echo $stats['open']; ?></span> open
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
                        <div class="stat-icon" style="background: rgba(245, 158, 11, 0.1); border-color: rgba(245, 158, 11, 0.2);">
                            <i data-lucide="loader" class="w-5 h-5 text-[#f59e0b]"></i>
                        </div>
                        <div>
                            <p class="text-2xl font-bold text-white"><?php echo $stats['in_progress']; ?></p>
                            <p class="text-[10px] text-[#666] uppercase">In Progress</p>
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
                        <div class="stat-icon">
                            <i data-lucide="check-circle" class="w-5 h-5 text-[#00ff88]"></i>
                        </div>
                        <div>
                            <p class="text-2xl font-bold text-white"><?php echo $stats['resolved']; ?></p>
                            <p class="text-[10px] text-[#666] uppercase">Resolved</p>
                        </div>
                    </div>
                </div>
            </div>
            
            <?php if ($flash_message): ?>
                <div class="mb-4" style="padding: 0.75rem 1rem; border-radius: 10px; font-size: 0.8rem; <?php echo $flash_message[0] ? 'background: rgba(0,255,136,0.1); border: 1px solid rgba(0,255,136,0.3); color: #00ff88;' : 'background: rgba(239,68,68,0.1); border: 1px solid rgba(239,68,68,0.3); color: #ef4444;'; ?>">
                    <?php echo htmlspecialchars($flash_message[1]); ?>
                </div>
            <?php endif; ?>
            
            <div class="grid grid-cols-3 gap-6">
                <!-- My Tickets -->
                <div class="col-span-2">
                    <div class="cyber-card p-4">
                        <h2 class="text-sm font-semibold text-white mb-4 flex items-center gap-2">
                            <i data-lucide="list" class="w-4 h-4 text-[#00ff88]"></i>
                            My Assigned Tickets
                        </h2>
                        
                        <?php if ($tickets && $tickets->num_rows > 0): ?>
                            <?php while ($ticket = $tickets->fetch_assoc()): ?>
                                <div class="ticket-card">
                                    <div class="flex items-start justify-between mb-2">
                                        <div class="flex items-center gap-2">
                                            <span class="text-[#00ff88] font-mono text-sm">#<?php echo $ticket['id']; ?></span>
                                            <span class="badge badge-<?php echo $ticket['status']; ?>"><?php echo ucfirst(str_replace('_', ' ', $ticket['status'])); ?></span>
                                            <span class="badge badge-<?php echo $ticket['priority']; ?>"><?php echo ucfirst($ticket['priority']); ?></span>
                                        </div>
                                        <span class="text-[10px] text-[#666]"><?php echo timeAgo($ticket['created_at']); ?></span>
                                    </div>
                                    <h3 class="text-white font-medium mb-1"><?php echo htmlspecialchars($ticket['title']); ?></h3>
                                    <p class="text-xs text-[#666] mb-3">
                                        <?php echo htmlspecialchars($ticket['created_by_name']); ?> | 
                                        <span class="capitalize"><?php echo $ticket['category']; ?></span> | 
                                        <?php echo $ticket['department']; ?>
                                    </p>
                                    <div class="flex items-center gap-2">
                                        <a href="ticket_detail.php?id=<?php echo $ticket['id']; ?>" class="cyber-btn-secondary px-3 py-1 text-xs">
                                            <i data-lucide="eye" class="w-3 h-3"></i>
                                            View
                                        </a>
                                        <button type="button" onclick="toggleManage(<?php echo $ticket['id']; ?>)" class="cyber-btn px-3 py-1 text-xs">
                                            <i data-lucide="edit" class="w-3 h-3"></i>
                                            Manage
                                        </button>
</div>
                                <?php include '_manage_panel.php'; ?>
                            </div>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <div class="text-center py-12">
                                <i data-lucide="inbox" class="w-12 h-12 text-[#333] mx-auto mb-3"></i>
                                <p class="text-[#666]">No tickets assigned to you</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Sidebar -->
                <div class="space-y-6">
                    <!-- Available Tickets -->
                    <?php if ($unassigned && $unassigned->num_rows > 0): ?>
                    <div class="cyber-card p-4">
                        <h3 class="text-sm font-semibold text-white mb-4 flex items-center gap-2">
                            <i data-lucide="inbox" class="w-4 h-4 text-[#00ff88]"></i>
                            Available Tickets
                        </h3>
                        <p class="text-xs text-[#666] mb-3">Tickets matching your specialization</p>
                        
                        <?php while ($ticket = $unassigned->fetch_assoc()): ?>
                            <div class="p-3 bg-[#0f0f15] rounded-lg mb-2">
                                <div class="flex items-center gap-2 mb-1">
                                    <span class="text-[#00ff88] font-mono text-xs">#<?php echo $ticket['id']; ?></span>
                                    <span class="badge badge-<?php echo $ticket['priority']; ?>"><?php echo ucfirst($ticket['priority']); ?></span>
                                </div>
                                <p class="text-sm text-[#ccc] mb-2 truncate"><?php echo htmlspecialchars($ticket['title']); ?></p>
                                <form method="POST" action="technician_update.php">
                                    <input type="hidden" name="action" value="claim">
                                    <input type="hidden" name="ticket_id" value="<?php echo $ticket['id']; ?>">
                                    <button type="submit" class="cyber-btn-orange w-full justify-center text-xs py-1">
                                        <i data-lucide="plus" class="w-3 h-3"></i>
                                        Claim Ticket
                                    </button>
                                </form>
                            </div>
                        <?php endwhile; ?>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Recent Activity -->
                    <?php if ($recent_comments && $recent_comments->num_rows > 0): ?>
                    <div class="cyber-card p-4">
                        <h3 class="text-sm font-semibold text-white mb-4 flex items-center gap-2">
                            <i data-lucide="message-circle" class="w-4 h-4 text-[#00ff88]"></i>
                            Recent Replies
                        </h3>
                        
                        <?php while ($comment = $recent_comments->fetch_assoc()): ?>
                            <div class="p-3 bg-[#0f0f15] rounded-lg mb-2">
                                <div class="flex items-center justify-between mb-1">
                                    <span class="text-xs text-[#ccc]"><?php echo htmlspecialchars($comment['user_name']); ?></span>
                                    <span class="text-[10px] text-[#666]"><?php echo timeAgo($comment['created_at']); ?></span>
                                </div>
                                <p class="text-xs text-[#666] truncate"><?php echo htmlspecialchars($comment['comment']); ?></p>
                                <a href="ticket_detail.php?id=<?php echo $comment['ticket_id']; ?>" class="text-[10px] text-[#00ff88]">View #<?php echo $comment['ticket_id']; ?></a>
                            </div>
                        <?php endwhile; ?>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Quick Actions -->
                    <div class="cyber-card p-4">
                        <h3 class="text-sm font-semibold text-white mb-4">Quick Actions</h3>
                        <div class="space-y-2">
                            <a href="technician_history.php" class="flex items-center gap-2 text-xs text-[#666] hover:text-[#00ff88] p-2 rounded hover:bg-[#0f0f15]">
                                <i data-lucide="history" class="w-4 h-4"></i>
                                View Resolved History
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
    
    <script>
        lucide.createIcons();

        function toggleManage(id) {
            const panel = document.getElementById('manage-' + id);
            if (panel) {
                panel.classList.toggle('hidden');
            }
        }
    </script>
</body>
</html>
