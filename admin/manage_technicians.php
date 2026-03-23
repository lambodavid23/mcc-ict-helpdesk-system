<?php
require_once '../config/auth_helper.php';
require_once '../config/database.php';

requireRole('admin');

$database = new Database();
$conn = $database->getConnection();

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add_technician':
                $name = trim($_POST['name']);
                $specialization = $_POST['specialization'];
                $phone = trim($_POST['phone']);
                $email = trim($_POST['email']);
                
                if (empty($name) || empty($specialization)) {
                    $error = 'Name and specialization are required';
                } else {
                    $name = $conn->real_escape_string($name);
                    $specialization = $conn->real_escape_string($specialization);
                    $phone = $conn->real_escape_string($phone);
                    $email = $conn->real_escape_string($email);
                    
                    $insert_query = "INSERT INTO technicians (name, specialization, phone, email) 
                                   VALUES ('$name', '$specialization', '$phone', '$email')";
                    
                    if ($conn->query($insert_query)) {
                        $success = 'Technician added successfully';
                        logActivity('ADD_TECHNICIAN', "Added technician: $name");
                    } else {
                        $error = 'Failed to add technician';
                    }
                }
                break;
                
            case 'update_technician':
                $tech_id = $_POST['tech_id'];
                $name = trim($_POST['name']);
                $specialization = $_POST['specialization'];
                $status = $_POST['status'];
                $phone = trim($_POST['phone']);
                $email = trim($_POST['email']);
                
                if (empty($name) || empty($specialization)) {
                    $error = 'Name and specialization are required';
                } else {
                    $name = $conn->real_escape_string($name);
                    $specialization = $conn->real_escape_string($specialization);
                    $status = $conn->real_escape_string($status);
                    $phone = $conn->real_escape_string($phone);
                    $email = $conn->real_escape_string($email);
                    
                    $update_query = "UPDATE technicians 
                                   SET name = '$name', specialization = '$specialization', 
                                       status = '$status', phone = '$phone', email = '$email'
                                   WHERE id = " . (int)$tech_id;
                    
                    if ($conn->query($update_query)) {
                        $success = 'Technician updated successfully';
                        logActivity('UPDATE_TECHNICIAN', "Updated technician ID: $tech_id");
                    } else {
                        $error = 'Failed to update technician';
                    }
                }
                break;
                
            case 'delete_technician':
                $tech_id = $_POST['tech_id'];
                
                $check_query = "SELECT COUNT(*) as count FROM tickets WHERE assigned_to = " . (int)$tech_id . " AND status IN ('open', 'in_progress')";
                $check_result = $conn->query($check_query);
                $active_tickets = $check_result->fetch_assoc()['count'];
                
                if ($active_tickets > 0) {
                    $error = 'Cannot delete technician with active tickets';
                } else {
                    $delete_query = "DELETE FROM technicians WHERE id = " . (int)$tech_id;
                    if ($conn->query($delete_query)) {
                        $success = 'Technician deleted successfully';
                        logActivity('DELETE_TECHNICIAN', "Deleted technician ID: $tech_id");
                    } else {
                        $error = 'Failed to delete technician';
                    }
                }
                break;
                
            case 'reset_workload':
                $tech_id = $_POST['tech_id'];
                $update_query = "UPDATE technicians SET current_workload = 0 WHERE id = " . (int)$tech_id;
                if ($conn->query($update_query)) {
                    $success = 'Workload reset successfully';
                    logActivity('RESET_WORKLOAD', "Reset workload for technician ID: $tech_id");
                } else {
                    $error = 'Failed to reset workload';
                }
                break;
        }
    }
}

$technicians_query = "SELECT t.*, 
                             COUNT(CASE WHEN tk.status IN ('open', 'in_progress') THEN 1 END) as active_tickets,
                             COUNT(CASE WHEN tk.status = 'resolved' THEN 1 END) as resolved_tickets
                      FROM technicians t
                      LEFT JOIN tickets tk ON t.id = tk.assigned_to
                      GROUP BY t.id
                      ORDER BY t.name";
$technicians = $conn->query($technicians_query);

$total_techs = $conn->query("SELECT COUNT(*) as total FROM technicians")->fetch_assoc()['total'];
$available_techs = $conn->query("SELECT COUNT(*) as total FROM technicians WHERE status = 'available'")->fetch_assoc()['total'];
$busy_techs = $conn->query("SELECT COUNT(*) as total FROM technicians WHERE status = 'busy'")->fetch_assoc()['total'];

logActivity('VIEW_MANAGE_TECHNICIANS', 'Admin viewed technician management page');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Technicians - MCC ICT Helpdesk</title>
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
        .cyber-btn-danger { background: linear-gradient(135deg, #ef4444, #dc2626); color: white; }
        .cyber-btn-danger:hover { box-shadow: 0 0 20px rgba(239, 68, 68, 0.3); }
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
        .badge-available { background: rgba(0, 255, 136, 0.2); color: #00ff88; }
        .badge-busy { background: rgba(245, 158, 11, 0.2); color: #f59e0b; }
        .badge-offline { background: rgba(100, 116, 139, 0.2); color: #94a3b8; }
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
        .alert-success { background: rgba(0, 255, 136, 0.1); border: 1px solid rgba(0, 255, 136, 0.3); color: #00ff88; padding: 0.75rem 1rem; border-radius: 8px; font-size: 0.8rem; margin-bottom: 1rem; }
        .alert-error { background: rgba(239, 68, 68, 0.1); border: 1px solid rgba(239, 68, 68, 0.3); color: #ef4444; padding: 0.75rem 1rem; border-radius: 8px; font-size: 0.8rem; margin-bottom: 1rem; }
        .workload-bar {
            height: 6px;
            background: rgba(15, 15, 21, 0.8);
            border-radius: 3px;
            overflow: hidden;
            width: 80px;
        }
        .workload-fill {
            height: 100%;
            border-radius: 3px;
            transition: width 0.3s;
        }
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.7);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1000;
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
                <a href="manage_technicians.php" class="sidebar-item active">
                    <i data-lucide="headphones" class="w-4 h-4"></i>
                    Technicians
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
                    <h1 class="text-xl font-bold text-white glow-text">Technicians</h1>
                    <p class="text-xs text-[#666] mt-0.5">Manage support technicians</p>
                </div>
            </header>
            
            <?php if ($success): ?>
                <div class="alert-success flex items-center gap-2">
                    <i data-lucide="check-circle" class="w-4 h-4"></i>
                    <?php echo $success; ?>
                </div>
            <?php endif; ?>
            
            <?php if ($error): ?>
                <div class="alert-error flex items-center gap-2">
                    <i data-lucide="x-circle" class="w-4 h-4"></i>
                    <?php echo $error; ?>
                </div>
            <?php endif; ?>
            
            <!-- Stats -->
            <div class="grid grid-cols-3 gap-4 mb-6">
                <div class="cyber-card p-4">
                    <div class="flex items-center gap-3">
                        <div class="stat-icon">
                            <i data-lucide="users" class="w-5 h-5 text-[#00ff88]"></i>
                        </div>
                        <div>
                            <p class="text-2xl font-bold text-white"><?php echo $total_techs; ?></p>
                            <p class="text-[10px] text-[#666] uppercase">Total Techs</p>
                        </div>
                    </div>
                </div>
                <div class="cyber-card p-4">
                    <div class="flex items-center gap-3">
                        <div class="stat-icon">
                            <i data-lucide="check-circle" class="w-5 h-5 text-[#00ff88]"></i>
                        </div>
                        <div>
                            <p class="text-2xl font-bold text-white"><?php echo $available_techs; ?></p>
                            <p class="text-[10px] text-[#666] uppercase">Available</p>
                        </div>
                    </div>
                </div>
                <div class="cyber-card p-4">
                    <div class="flex items-center gap-3">
                        <div class="stat-icon" style="background: rgba(245, 158, 11, 0.1); border-color: rgba(245, 158, 11, 0.2);">
                            <i data-lucide="clock" class="w-5 h-5 text-[#f59e0b]"></i>
                        </div>
                        <div>
                            <p class="text-2xl font-bold text-white"><?php echo $busy_techs; ?></p>
                            <p class="text-[10px] text-[#666] uppercase">Busy</p>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Add Technician Form -->
            <div class="cyber-card p-4 mb-6">
                <h2 class="text-sm font-semibold text-white mb-4 flex items-center gap-2">
                    <i data-lucide="user-plus" class="w-4 h-4 text-[#00ff88]"></i>
                    Add New Technician
                </h2>
                <form method="POST" class="flex flex-wrap gap-4 items-end">
                    <input type="hidden" name="action" value="add_technician">
                    <div class="flex-1 min-w-[180px]">
                        <label class="block text-[10px] text-[#666] uppercase tracking-wider mb-2">Full Name</label>
                        <input type="text" name="name" class="cyber-input" placeholder="John Smith" required>
                    </div>
                    <div class="flex-1 min-w-[140px]">
                        <label class="block text-[10px] text-[#666] uppercase tracking-wider mb-2">Specialization</label>
                        <select name="specialization" class="cyber-select" required>
                            <option value="">Select</option>
                            <option value="network">Network</option>
                            <option value="hardware">Hardware</option>
                            <option value="software">Software</option>
                            <option value="general">General</option>
                        </select>
                    </div>
                    <div class="flex-1 min-w-[150px]">
                        <label class="block text-[10px] text-[#666] uppercase tracking-wider mb-2">Phone</label>
                        <input type="tel" name="phone" class="cyber-input" placeholder="+263...">
                    </div>
                    <div class="flex-1 min-w-[200px]">
                        <label class="block text-[10px] text-[#666] uppercase tracking-wider mb-2">Email</label>
                        <input type="email" name="email" class="cyber-input" placeholder="tech@mcc.co.zw">
                    </div>
                    <button type="submit" class="cyber-btn">
                        <i data-lucide="plus" class="w-4 h-4"></i>
                        Add Technician
                    </button>
                </form>
            </div>
            
            <!-- Technicians Table -->
            <div class="cyber-card p-4">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-sm font-semibold text-white flex items-center gap-2">
                        <i data-lucide="headphones" class="w-4 h-4 text-[#00ff88]"></i>
                        Technicians (<?php echo $technicians ? $technicians->num_rows : 0; ?>)
                    </h2>
                    <div class="relative">
                        <i data-lucide="search" class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-[#444]"></i>
                        <input type="text" id="searchTechs" class="cyber-input pl-10 w-64" placeholder="Search technicians..." onkeyup="filterTable()">
                    </div>
                </div>
                
                <div class="overflow-x-auto">
                    <table class="cyber-table" id="techsTable">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Name</th>
                                <th>Specialization</th>
                                <th>Status</th>
                                <th>Workload</th>
                                <th>Active</th>
                                <th>Resolved</th>
                                <th>Contact</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($technicians && $technicians->num_rows > 0): ?>
                                <?php while ($tech = $technicians->fetch_assoc()): ?>
                                    <?php 
                                    $status_class = 'badge-' . $tech['status'];
                                    $workload_pct = min($tech['current_workload'] * 10, 100);
                                    $workload_color = $tech['current_workload'] > 5 ? '#ef4444' : ($tech['current_workload'] > 2 ? '#f59e0b' : '#00ff88');
                                    ?>
                                    <tr data-search="<?php echo strtolower($tech['name'] . ' ' . $tech['specialization']); ?>">
                                        <td class="text-[#00ff88] font-mono">#<?php echo $tech['id']; ?></td>
                                        <td class="text-white font-medium flex items-center gap-2">
                                            <div class="w-7 h-7 rounded-lg bg-[#00ff88]/10 border border-[#00ff88]/20 flex items-center justify-center text-[#00ff88] text-xs font-bold">
                                                <?php echo strtoupper(substr($tech['name'], 0, 1)); ?>
                                            </div>
                                            <?php echo htmlspecialchars($tech['name']); ?>
                                        </td>
                                        <td class="capitalize text-[#ccc]"><?php echo $tech['specialization']; ?></td>
                                        <td><span class="badge <?php echo $status_class; ?>"><?php echo ucfirst($tech['status']); ?></span></td>
                                        <td>
                                            <div class="flex items-center gap-2">
                                                <div class="workload-bar">
                                                    <div class="workload-fill" style="width: <?php echo $workload_pct; ?>%; background: <?php echo $workload_color; ?>;"></div>
                                                </div>
                                                <span class="text-xs text-[#666]"><?php echo $tech['current_workload']; ?></span>
                                            </div>
                                        </td>
                                        <td class="text-[#f59e0b] font-semibold"><?php echo $tech['active_tickets']; ?></td>
                                        <td class="text-[#00ff88] font-semibold"><?php echo $tech['resolved_tickets']; ?></td>
                                        <td class="text-[#666] text-xs">
                                            <?php if ($tech['phone']): ?>
                                                <div><?php echo htmlspecialchars($tech['phone']); ?></div>
                                            <?php endif; ?>
                                            <?php if ($tech['email']): ?>
                                                <div class="text-[#60a5fa]"><?php echo htmlspecialchars($tech['email']); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="flex items-center gap-1">
                                                <button onclick="openEditModal(<?php echo $tech['id']; ?>, '<?php echo htmlspecialchars($tech['name'], ENT_QUOTES); ?>', '<?php echo $tech['specialization']; ?>', '<?php echo $tech['status']; ?>', '<?php echo htmlspecialchars($tech['phone'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($tech['email'], ENT_QUOTES); ?>')" class="cyber-btn-secondary px-2 py-1 text-xs">
                                                    <i data-lucide="edit-2" class="w-3 h-3"></i>
                                                </button>
                                                <form method="POST" class="inline" onsubmit="return confirm('Reset workload for this technician?')">
                                                    <input type="hidden" name="action" value="reset_workload">
                                                    <input type="hidden" name="tech_id" value="<?php echo $tech['id']; ?>">
                                                    <button type="submit" class="cyber-btn-secondary px-2 py-1 text-xs">
                                                        <i data-lucide="refresh-cw" class="w-3 h-3"></i>
                                                    </button>
                                                </form>
                                                <form method="POST" class="inline" onsubmit="return confirm('Delete this technician?')">
                                                    <input type="hidden" name="action" value="delete_technician">
                                                    <input type="hidden" name="tech_id" value="<?php echo $tech['id']; ?>">
                                                    <button type="submit" class="cyber-btn-danger px-2 py-1 text-xs">
                                                        <i data-lucide="trash-2" class="w-3 h-3"></i>
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="9" class="text-center text-[#666] py-12">
                                        <div class="flex flex-col items-center gap-3">
                                            <i data-lucide="headphones" class="w-12 h-12 text-[#333]"></i>
                                            <span class="text-sm">No technicians found</span>
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
    
    <!-- Edit Modal -->
    <div id="editModal" class="modal-overlay" style="display: none;">
        <div class="cyber-card p-6 w-full max-w-md mx-4">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-lg font-bold text-white flex items-center gap-2">
                    <i data-lucide="edit-2" class="w-5 h-5 text-[#00ff88]"></i>
                    Edit Technician
                </h2>
                <button onclick="closeEditModal()" class="text-[#666] hover:text-white">
                    <i data-lucide="x" class="w-5 h-5"></i>
                </button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="update_technician">
                <input type="hidden" id="edit_tech_id" name="tech_id">
                <div class="space-y-4">
                    <div>
                        <label class="block text-[10px] text-[#666] uppercase tracking-wider mb-2">Full Name</label>
                        <input type="text" id="edit_name" name="name" class="cyber-input" required>
                    </div>
                    <div>
                        <label class="block text-[10px] text-[#666] uppercase tracking-wider mb-2">Specialization</label>
                        <select id="edit_specialization" name="specialization" class="cyber-select" required>
                            <option value="network">Network</option>
                            <option value="hardware">Hardware</option>
                            <option value="software">Software</option>
                            <option value="general">General</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-[10px] text-[#666] uppercase tracking-wider mb-2">Status</label>
                        <select id="edit_status" name="status" class="cyber-select" required>
                            <option value="available">Available</option>
                            <option value="busy">Busy</option>
                            <option value="offline">Offline</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-[10px] text-[#666] uppercase tracking-wider mb-2">Phone</label>
                        <input type="tel" id="edit_phone" name="phone" class="cyber-input">
                    </div>
                    <div>
                        <label class="block text-[10px] text-[#666] uppercase tracking-wider mb-2">Email</label>
                        <input type="email" id="edit_email" name="email" class="cyber-input">
                    </div>
                </div>
                <div class="flex gap-3 mt-6">
                    <button type="submit" class="cyber-btn flex-1 justify-center">
                        <i data-lucide="check" class="w-4 h-4"></i>
                        Update
                    </button>
                    <button type="button" onclick="closeEditModal()" class="cyber-btn-secondary flex-1 justify-center">
                        Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        lucide.createIcons();
        
        function filterTable() {
            const input = document.getElementById('searchTechs');
            const filter = input.value.toLowerCase();
            const rows = document.querySelectorAll('#techsTable tbody tr');
            
            rows.forEach(row => {
                const searchText = row.getAttribute('data-search');
                row.style.display = searchText.includes(filter) ? '' : 'none';
            });
        }
        
        function openEditModal(id, name, specialization, status, phone, email) {
            document.getElementById('edit_tech_id').value = id;
            document.getElementById('edit_name').value = name;
            document.getElementById('edit_specialization').value = specialization;
            document.getElementById('edit_status').value = status;
            document.getElementById('edit_phone').value = phone;
            document.getElementById('edit_email').value = email;
            document.getElementById('editModal').style.display = 'flex';
        }
        
        function closeEditModal() {
            document.getElementById('editModal').style.display = 'none';
        }
        
        document.getElementById('editModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeEditModal();
            }
        });
    </script>
</body>
</html>
