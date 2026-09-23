<?php
require_once '../config/auth_helper.php';
require_once '../config/database.php';
require_once '../config/AttendanceService.php';

requireRole('technician');

$database = new Database();
$conn = $database->getConnection();
$attendance = new AttendanceService();

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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tech_id > 0) {
    $action = $_POST['action'] ?? '';

    if ($action === 'clock_in') {
        if ($attendance->clockIn($tech_id)) {
            setSuccess('You have logged in. You are now on duty and will receive auto-assigned tickets.');
            logActivity('TECHNICIAN_CLOCK_IN', "Technician {$technician['name']} clocked in");
        } else {
            setError('You are already logged in for today.');
        }
    } elseif ($action === 'clock_out') {
        if ($attendance->clockOut($tech_id)) {
            setSuccess('You have logged out. No new tickets will be auto-assigned until you log in again.');
            logActivity('TECHNICIAN_CLOCK_OUT', "Technician {$technician['name']} clocked out");
        } else {
            setError('You are not currently logged in.');
        }
    }
    header('Location: attendance.php');
    exit;
}

$today = $attendance->getToday($tech_id);
$on_duty = $attendance->isOnDuty($tech_id);
$history = $attendance->getRecentHistory($tech_id, 14);

logActivity('VIEW_TECHNICIAN_ATTENDANCE', 'Technician viewed their attendance');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance - MCC ICT Helpdesk</title>
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
        .cyber-btn-out {
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: white;
        }
        .cyber-btn-out:hover { box-shadow: 0 0 20px rgba(239, 68, 68, 0.3); }
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
        .badge-on-duty { background: rgba(0, 255, 136, 0.2); color: #00ff88; }
        .badge-off-duty { background: rgba(239, 68, 68, 0.2); color: #ef4444; }
        .badge-clocked-out { background: rgba(245, 158, 11, 0.2); color: #f59e0b; }
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
                <a href="technician_queue.php" class="sidebar-item">
                    <i data-lucide="list-checks" class="w-4 h-4"></i>
                    My Queue
                </a>
                <a href="technician_history.php" class="sidebar-item">
                    <i data-lucide="history" class="w-4 h-4"></i>
                    History
                </a>
                <a href="attendance.php" class="sidebar-item active">
                    <i data-lucide="clock" class="w-4 h-4"></i>
                    Attendance
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
                    <div class="pulse-indicator" style="<?php echo $on_duty ? '' : 'background: #f59e0b; animation: none; box-shadow: none;'; ?>"></div>
                    <span class="text-[10px] <?php echo $on_duty ? 'text-[#00ff88]' : 'text-[#f59e0b]'; ?>"><?php echo $on_duty ? 'On Duty' : 'Off Duty'; ?></span>
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
                    <h1 class="text-xl font-bold text-white glow-text">Daily Attendance</h1>
                    <p class="text-xs text-[#666] mt-0.5">Clock in to start receiving auto-assigned tickets</p>
                </div>
                <div class="text-xs text-[#666]">
                    <?php echo date('D, M j, Y'); ?>
                </div>
            </header>
            
            <?php echo displaySuccess(); ?>
            <?php echo displayError(); ?>
            
            <!-- Status Card -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
                <div class="lg:col-span-1 cyber-card p-6 text-center">
                    <div class="w-16 h-16 mx-auto mb-4 rounded-full flex items-center justify-center" style="<?php echo $on_duty ? 'background: rgba(0,255,136,0.1); border: 2px solid #00ff88;' : 'background: rgba(15,15,21,0.8); border: 2px solid #1a1a2e;'; ?>">
                        <i data-lucide="<?php echo $on_duty ? 'check-circle' : 'power'; ?>" class="w-8 h-8 <?php echo $on_duty ? 'text-[#00ff88]' : 'text-[#666]'; ?>"></i>
                    </div>
                    <h2 class="text-lg font-bold text-white mb-1"><?php echo $on_duty ? 'ON DUTY' : 'OFF DUTY'; ?></h2>
                    <p class="text-xs text-[#666] mb-5">
                        <?php if ($on_duty): ?>
                            Logged in at <?php echo date('g:i A', strtotime($today['clock_in'])); ?>
                        <?php elseif ($today): ?>
                            Last log out: <?php echo date('g:i A', strtotime($today['clock_out'])); ?>
                        <?php else: ?>
                            You have not logged in today
                        <?php endif; ?>
                    </p>
                    
                    <?php if ($on_duty): ?>
                        <form method="POST" onsubmit="return confirm('Log out for the day? Your assigned tickets will be kept, but no new ones will be assigned to you.');">
                            <input type="hidden" name="action" value="clock_out">
                            <button type="submit" class="cyber-btn cyber-btn-out w-full justify-center py-3 text-sm">
                                <i data-lucide="log-out" class="w-4 h-4"></i>
                                Log Out
                            </button>
                        </form>
                    <?php else: ?>
                        <form method="POST">
                            <input type="hidden" name="action" value="clock_in">
                            <button type="submit" class="cyber-btn w-full justify-center py-3 text-sm">
                                <i data-lucide="log-in" class="w-4 h-4"></i>
                                Log In
                            </button>
                        </form>
                        <p class="text-[10px] text-[#666] mt-3">Tickets will only be auto-assigned to you while you are logged in.</p>
                    <?php endif; ?>
                </div>
                
                <div class="lg:col-span-2 cyber-card p-6">
                    <h2 class="text-sm font-semibold text-white mb-4">Recent Days</h2>
                    
                    <div class="overflow-x-auto">
                        <table class="cyber-table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Log In</th>
                                    <th>Log Out</th>
                                    <th>Hours</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($history)): ?>
                                    <?php foreach ($history as $day): ?>
                                        <tr>
                                            <td class="text-white"><?php echo date('M d, Y', strtotime($day['work_date'])); ?></td>
                                            <td><?php echo $day['clock_in'] ? date('g:i A', strtotime($day['clock_in'])) : '&mdash;'; ?></td>
                                            <td><?php echo $day['clock_out'] ? date('g:i A', strtotime($day['clock_out'])) : '&mdash;'; ?></td>
                                            <td class="text-[#00ff88]"><?php echo round($day['minutes_worked'] / 60, 1); ?></td>
                                            <td>
                                                <?php if ($day['clock_out'] === null): ?>
                                                    <span class="badge badge-on-duty">On Duty</span>
                                                <?php else: ?>
                                                    <span class="badge badge-clocked-out">Clocked Out</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="5" class="text-center text-[#666]">No attendance records yet</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </main>
    </div>
    
    <script>
        lucide.createIcons();
    </script>
</body>
</html>