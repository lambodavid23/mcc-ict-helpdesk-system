<?php
require_once '../config/auth_helper.php';
require_once '../config/database.php';
require_once '../config/AttendanceService.php';

requireRole('admin');

$database = new Database();
$conn = $database->getConnection();
$attendance = new AttendanceService();

$selected_date = isset($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date'])
    ? $_GET['date']
    : date('Y-m-d');

$rows = $attendance->getAttendanceForDate($selected_date);

$on_duty_count = 0;
$clocked_out_count = 0;
$absent_count = 0;
foreach ($rows as $row) {
    if ($row['attendance_status'] === 'on_duty') $on_duty_count++;
    elseif ($row['attendance_status'] === 'clocked_out') $clocked_out_count++;
    else $absent_count++;
}
$total_techs = count($rows);

logActivity('VIEW_ADMIN_ATTENDANCE', "Admin viewed technician attendance for $selected_date");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Technician Attendance - MCC ICT Helpdesk</title>
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
            padding: 0.25rem 0.625rem;
            border-radius: 9999px;
            font-size: 0.65rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .badge-on-duty { background: rgba(0, 255, 136, 0.2); color: #00ff88; }
        .badge-clocked-out { background: rgba(245, 158, 11, 0.2); color: #f59e0b; }
        .badge-absent { background: rgba(239, 68, 68, 0.2); color: #ef4444; }
        .badge-available { background: rgba(0, 255, 136, 0.2); color: #00ff88; }
        .badge-busy { background: rgba(245, 158, 11, 0.2); color: #f59e0b; }
        .badge-offline { background: rgba(239, 68, 68, 0.2); color: #ef4444; }
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
                <a href="attendance.php" class="sidebar-item active">
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

        <main class="ml-64 flex-1 p-6 h-screen overflow-y-auto">
            <header class="flex items-center justify-between mb-6">
                <div>
                    <h1 class="text-xl font-bold text-white glow-text">Technician Attendance</h1>
                    <p class="text-xs text-[#666] mt-0.5">Daily clock-in/out records and on-duty availability</p>
                </div>
                <form method="GET" class="flex items-center gap-2">
                    <input type="date" name="date" value="<?php echo htmlspecialchars($selected_date); ?>"
                        class="bg-[#0f0f15] border border-[#1a1a2e] rounded-lg px-3 py-2 text-[#e0e0e0] text-xs focus:outline-none focus:border-[#00ff88]">
                    <button type="submit" class="cyber-btn">
                        <i data-lucide="calendar" class="w-3.5 h-3.5"></i>
                        View
                    </button>
                </form>
            </header>

            <!-- Stats -->
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
                <div class="cyber-card p-4">
                    <div class="flex items-start justify-between">
                        <div>
                            <p class="text-[10px] text-[#666] uppercase tracking-wider mb-1">Total Technicians</p>
                            <p class="text-2xl font-bold text-white"><?php echo $total_techs; ?></p>
                        </div>
                        <div class="stat-icon">
                            <i data-lucide="users" class="w-5 h-5 text-[#00ff88]"></i>
                        </div>
                    </div>
                </div>

                <div class="cyber-card p-4">
                    <div class="flex items-start justify-between">
                        <div>
                            <p class="text-[10px] text-[#666] uppercase tracking-wider mb-1">On Duty</p>
                            <p class="text-2xl font-bold text-[#00ff88]"><?php echo $on_duty_count; ?></p>
                        </div>
                        <div class="stat-icon">
                            <i data-lucide="check-circle" class="w-5 h-5 text-[#00ff88]"></i>
                        </div>
                    </div>
                </div>

                <div class="cyber-card p-4">
                    <div class="flex items-start justify-between">
                        <div>
                            <p class="text-[10px] text-[#666] uppercase tracking-wider mb-1">Clocked Out</p>
                            <p class="text-2xl font-bold text-[#f59e0b]"><?php echo $clocked_out_count; ?></p>
                        </div>
                        <div class="stat-icon" style="background: rgba(245, 158, 11, 0.1); border-color: rgba(245, 158, 11, 0.2);">
                            <i data-lucide="log-out" class="w-5 h-5 text-[#f59e0b]"></i>
                        </div>
                    </div>
                </div>

                <div class="cyber-card p-4">
                    <div class="flex items-start justify-between">
                        <div>
                            <p class="text-[10px] text-[#666] uppercase tracking-wider mb-1">Not Clocked In</p>
                            <p class="text-2xl font-bold text-[#ef4444]"><?php echo $absent_count; ?></p>
                        </div>
                        <div class="stat-icon" style="background: rgba(239, 68, 68, 0.1); border-color: rgba(239, 68, 68, 0.2);">
                            <i data-lucide="alert-circle" class="w-5 h-5 text-[#ef4444]"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Attendance Table -->
            <div class="cyber-card p-4">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-sm font-semibold text-white">Attendance - <?php echo date('D, M j, Y', strtotime($selected_date)); ?></h2>
                </div>

                <div class="overflow-x-auto">
                    <table class="cyber-table">
                        <thead>
                            <tr>
                                <th>Technician</th>
                                <th>Specialization</th>
                                <th>Clock In</th>
                                <th>Clock Out</th>
                                <th>Hours</th>
                                <th>Attendance</th>
                                <th>System Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($rows)): ?>
                                <?php foreach ($rows as $row): ?>
                                    <tr>
                                        <td>
                                            <div class="flex items-center gap-2">
                                                <div class="w-7 h-7 rounded-lg bg-[#00ff88]/10 border border-[#00ff88]/20 flex items-center justify-center text-[#00ff88] text-xs font-bold">
                                                    <?php echo strtoupper(substr($row['name'], 0, 1)); ?>
                                                </div>
                                                <span class="text-white"><?php echo htmlspecialchars($row['name']); ?></span>
                                            </div>
                                        </td>
                                        <td class="capitalize"><?php echo $row['specialization']; ?></td>
                                        <td><?php echo $row['clock_in'] ? date('g:i A', strtotime($row['clock_in'])) : '<span class="text-[#444]">&mdash;</span>'; ?></td>
                                        <td><?php echo $row['clock_out'] ? date('g:i A', strtotime($row['clock_out'])) : ($row['attendance_status'] === 'on_duty' ? '<span class="text-[#00ff88] text-[10px]">On shift</span>' : '<span class="text-[#444]">&mdash;</span>'); ?></td>
                                        <td class="text-[#00ff88]">
                                            <?php
                                            if ($row['clock_in']) {
                                                $end = $row['clock_out'] ? strtotime($row['clock_out']) : time();
                                                $hours = ($end - strtotime($row['clock_in'])) / 3600;
                                                echo round($hours, 1);
                                            } else {
                                                echo '<span class="text-[#444]">&mdash;</span>';
                                            }
                                            ?>
                                        </td>
                                        <td>
                                            <?php
                                            $status_badge = 'badge-absent';
                                            $status_label = 'Not Clocked In';
                                            if ($row['attendance_status'] === 'on_duty') {
                                                $status_badge = 'badge-on-duty';
                                                $status_label = 'On Duty';
                                            } elseif ($row['attendance_status'] === 'clocked_out') {
                                                $status_badge = 'badge-clocked-out';
                                                $status_label = 'Clocked Out';
                                            }
                                            echo '<span class="badge ' . $status_badge . '">' . $status_label . '</span>';
                                            ?>
                                        </td>
                                        <td>
                                            <?php
                                            $sys_class = 'badge-' . $row['status'];
                                            echo '<span class="badge ' . $sys_class . '">' . ucfirst($row['status']) . '</span>';
                                            ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" class="text-center text-[#666]">No attendance records found</td>
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