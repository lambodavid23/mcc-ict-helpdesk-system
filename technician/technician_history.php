<?php
require_once '../config/auth_helper.php';
require_once '../config/database.php';

requireRole('technician');

$database = new Database();
$conn = $database->getConnection();

$user_id = $_SESSION['user_id'];

$technician = $conn->query("SELECT * FROM technicians WHERE user_id = $user_id")->fetch_assoc();

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

$stats = [
    'total_resolved' => $conn->query("SELECT COUNT(*) as c FROM fault_history WHERE resolved_by = $tech_id")->fetch_assoc()['c'] ?? 0,
    'avg_time' => $conn->query("SELECT AVG(time_to_resolve) as avg FROM fault_history WHERE resolved_by = $tech_id")->fetch_assoc()['avg'] ?? 0,
];

$history_query = $tech_id > 0
    ? "SELECT fh.*, t.title, t.category, u.name as resolved_for
       FROM fault_history fh
       JOIN tickets t ON fh.ticket_id = t.id
       LEFT JOIN users u ON t.created_by = u.id
       WHERE fh.resolved_by = $tech_id
       ORDER BY fh.resolved_at DESC
       LIMIT 50"
    : "SELECT fh.*, t.title, t.category, u.name as resolved_for
       FROM fault_history fh
       JOIN tickets t ON fh.ticket_id = t.id
       LEFT JOIN users u ON t.created_by = u.id
       WHERE 1=0 LIMIT 0";
$history = $conn->query($history_query);

logActivity('VIEW_TECHNICIAN_HISTORY', 'Technician viewed their history');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My History - MCC ICT Helpdesk</title>
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
    </style>
</head>
<body class="min-h-screen grid-bg">
    <div class="flex">
        <aside class="fixed top-0 left-0 h-screen w-64 bg-[#0a0a0f]/95 border-r border-[#1a1a2e] p-4 flex flex-col overflow-hidden">
            <div class="flex items-center gap-3 mb-6 pb-4 border-b border-[#1a1a2e]">
                <img src="../assets/images/mutarelogo.png" alt="MCC" class="w-10 h-10">
                <div>
                    <span class="text-sm font-bold text-white">MCC ICT</span>
                    <p class="text-[10px] text-[#00ff88] uppercase tracking-wider">Technician</p>
                </div>
            </div>
            
            <nav class="flex-1 space-y-1">
                <a href="dashboard.php" class="sidebar-item">
                    <i data-lucide="layout-dashboard" class="w-4 h-4"></i>
                    Dashboard
                </a>
                <a href="technician_queue.php" class="sidebar-item">
                    <i data-lucide="list-checks" class="w-4 h-4"></i>
                    My Queue
                </a>
                <a href="technician_history.php" class="sidebar-item active">
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
                <a href="../auth/logout.php" class="flex items-center gap-2 text-[#666] hover:text-[#ef4444] text-xs transition-colors">
                    <i data-lucide="log-out" class="w-4 h-4"></i>
                    Logout
                </a>
            </div>
        </aside>
        
        <main class="ml-64 flex-1 p-6 h-screen overflow-y-auto">
            <header class="flex items-center justify-between mb-6">
                <div>
                    <h1 class="text-xl font-bold text-white glow-text">My History</h1>
                    <p class="text-xs text-[#666] mt-0.5">Your resolved tickets</p>
                </div>
            </header>
            
            <!-- Stats -->
            <div class="grid grid-cols-2 gap-4 mb-6">
                <div class="cyber-card p-4">
                    <div class="flex items-center gap-3">
                        <div class="stat-icon">
                            <i data-lucide="check-circle" class="w-5 h-5 text-[#00ff88]"></i>
                        </div>
                        <div>
                            <p class="text-2xl font-bold text-white"><?php echo $stats['total_resolved']; ?></p>
                            <p class="text-[10px] text-[#666] uppercase">Tickets Resolved</p>
                        </div>
                    </div>
                </div>
                <div class="cyber-card p-4">
                    <div class="flex items-center gap-3">
                        <div class="stat-icon" style="background: rgba(139, 92, 246, 0.1); border-color: rgba(139, 92, 246, 0.2);">
                            <i data-lucide="clock" class="w-5 h-5 text-[#8b5cf6]"></i>
                        </div>
                        <div>
                            <p class="text-2xl font-bold text-white"><?php echo round($stats['avg_time'] ?? 0, 0); ?><span class="text-sm text-[#666] font-normal">min</span></p>
                            <p class="text-[10px] text-[#666] uppercase">Avg Resolution</p>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- History Table -->
            <div class="cyber-card p-4">
                <h2 class="text-sm font-semibold text-white mb-4">Resolved Tickets</h2>
                
                <?php if ($history && $history->num_rows > 0): ?>
                    <div class="overflow-x-auto">
                        <table class="cyber-table">
                            <thead>
                                <tr>
                                    <th>Ticket</th>
                                    <th>Category</th>
                                    <th>For User</th>
                                    <th>Resolution</th>
                                    <th>Time</th>
                                    <th>Resolved At</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($row = $history->fetch_assoc()): ?>
                                    <tr>
                                        <td>
                                            <div class="text-[#00ff88] font-mono">#<?php echo $row['ticket_id']; ?></div>
                                            <div class="text-white text-sm truncate max-w-xs"><?php echo htmlspecialchars($row['title']); ?></div>
                                        </td>
                                        <td class="capitalize"><?php echo $row['category']; ?></td>
                                        <td><?php echo htmlspecialchars($row['resolved_for']); ?></td>
                                        <td>
                                            <button onclick="showSolution(<?php echo htmlspecialchars(json_encode($row['solution'])); ?>)" class="text-[#00ff88] hover:underline text-xs">View Solution</button>
                                        </td>
                                        <td class="text-[#00ff88]"><?php echo $row['time_to_resolve']; ?> min</td>
                                        <td class="text-[#666] text-xs"><?php echo date('M d, Y H:i', strtotime($row['resolved_at'])); ?></td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="text-center py-12">
                        <i data-lucide="inbox" class="w-12 h-12 text-[#333] mx-auto mb-3"></i>
                        <p class="text-[#666]">No resolved tickets yet</p>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>
    
    <!-- Solution Modal -->
    <div id="solutionModal" class="fixed inset-0 bg-black/70 flex items-center justify-center z-50 hidden">
        <div class="cyber-card p-6 w-full max-w-lg mx-4">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-lg font-bold text-white">Resolution Details</h3>
                <button onclick="closeModal()" class="text-[#666] hover:text-white">
                    <i data-lucide="x" class="w-5 h-5"></i>
                </button>
            </div>
            <div id="solutionContent" class="text-[#ccc] whitespace-pre-wrap"></div>
        </div>
    </div>
    
    <script>
        lucide.createIcons();
        
        function showSolution(solution) {
            document.getElementById('solutionContent').textContent = solution;
            document.getElementById('solutionModal').classList.remove('hidden');
        }
        
        function closeModal() {
            document.getElementById('solutionModal').classList.add('hidden');
        }
        
        document.getElementById('solutionModal').addEventListener('click', function(e) {
            if (e.target === this) closeModal();
        });
    </script>
</body>
</html>
