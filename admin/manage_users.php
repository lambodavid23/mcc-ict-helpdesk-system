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
            case 'add_user':
                $name = trim($_POST['name']);
                $email = trim($_POST['email']);
                $password = $_POST['password'];
                $department = $_POST['department'];
                
                if (empty($name) || empty($email) || empty($password) || empty($department)) {
                    $error = 'All fields are required';
                } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $error = 'Invalid email format';
                } else {
                    $check_query = "SELECT id FROM users WHERE email = '" . $conn->real_escape_string($email) . "' LIMIT 1
                                    UNION ALL
                                    SELECT id FROM admins WHERE email = '" . $conn->real_escape_string($email) . "' LIMIT 1
                                    UNION ALL
                                    SELECT id FROM technicians WHERE email = '" . $conn->real_escape_string($email) . "' LIMIT 1";
                    $check_result = $conn->query($check_query);
                    
                    if ($check_result && $check_result->num_rows > 0) {
                        $error = 'Email address already exists';
                    } else {
                        $name = $conn->real_escape_string($name);
                        $email = $conn->real_escape_string($email);
                        $department = $conn->real_escape_string($department);
                        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                        
                        $insert_query = "INSERT INTO users (name, email, password, department) 
                                       VALUES ('$name', '$email', '$hashed_password', '$department')";
                        
                        if ($conn->query($insert_query)) {
                            $success = 'User added successfully';
                            logActivity('ADD_USER', "Added user: $name ($email)");
                        } else {
                            $error = 'Failed to add user';
                        }
                    }
                }
                break;
                
            case 'delete_user':
                $user_id = $_POST['user_id'];
                
                if ($user_id == $_SESSION['user_id']) {
                    $error = 'You cannot delete your own account';
                } else {
                    $delete_query = "DELETE FROM users WHERE id = " . (int)$user_id;
                    if ($conn->query($delete_query)) {
                        $success = 'User deleted successfully';
                        logActivity('DELETE_USER', "Deleted user ID: $user_id");
                    } else {
                        $error = 'Failed to delete user';
                    }
                }
                break;

            case 'approve_user':
                $user_id = (int)$_POST['user_id'];
                if ($conn->query("UPDATE users SET status = 'active', pending_expires_at = NULL WHERE id = $user_id")) {
                    $conn->query("UPDATE notifications SET is_read = 1 WHERE type = 'account_pending' AND message LIKE '%" . $user_id . "%'");
                    $success = 'User approved successfully';
                    logActivity('APPROVE_USER', "Approved user ID: $user_id");
                } else {
                    $error = 'Failed to approve user';
                }
                break;

            case 'reject_user':
                $user_id = (int)$_POST['user_id'];
                if ($conn->query("UPDATE users SET status = 'rejected', pending_expires_at = NULL WHERE id = $user_id")) {
                    $conn->query("UPDATE notifications SET is_read = 1 WHERE type = 'account_pending' AND message LIKE '%" . $user_id . "%'");
                    $success = 'User request rejected';
                    logActivity('REJECT_USER', "Rejected user ID: $user_id");
                } else {
                    $error = 'Failed to reject user';
                }
                break;

            case 'suspend_user':
                $user_id = (int)$_POST['user_id'];
                if ($conn->query("UPDATE users SET status = 'suspended' WHERE id = $user_id")) {
                    $success = 'User suspended successfully';
                    logActivity('SUSPEND_USER', "Suspended user ID: $user_id");
                } else {
                    $error = 'Failed to suspend user';
                }
                break;

            case 'activate_user':
                $user_id = (int)$_POST['user_id'];
                if ($conn->query("UPDATE users SET status = 'active' WHERE id = $user_id")) {
                    $success = 'User activated successfully';
                    logActivity('ACTIVATE_USER', "Activated user ID: $user_id");
                } else {
                    $error = 'Failed to activate user';
                }
                break;
        }
    }
}

$users_query = "SELECT id, name, email, department, status, created_at FROM users ORDER BY created_at DESC";
$users = $conn->query($users_query);

$total_users = $conn->query("SELECT COUNT(*) as total FROM users")->fetch_assoc()['total'];
$admin_count = $conn->query("SELECT COUNT(*) as total FROM admins")->fetch_assoc()['total'];
$user_count = $conn->query("SELECT COUNT(*) as total FROM users")->fetch_assoc()['total'];
$tech_count = $conn->query("SELECT COUNT(*) as total FROM technicians")->fetch_assoc()['total'];
$pending_count = $conn->query("SELECT COUNT(*) as total FROM users WHERE status = 'pending'")->fetch_assoc()['total'];
$pending_users = $conn->query("SELECT id, name, email, department, pending_expires_at FROM users WHERE status = 'pending' ORDER BY created_at DESC");

logActivity('VIEW_MANAGE_USERS', 'Admin viewed user management page');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Users - MCC ICT Helpdesk</title>
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
        .badge-admin { background: rgba(239, 68, 68, 0.2); color: #ef4444; }
        .badge-tech { background: rgba(59, 130, 246, 0.2); color: #60a5fa; }
        .badge-user { background: rgba(0, 255, 136, 0.2); color: #00ff88; }
        .badge-pending { background: rgba(251, 191, 36, 0.15); color: #fbbf24; }
        .badge-rejected { background: rgba(239, 68, 68, 0.15); color: #ef4444; }
        .badge-suspended { background: rgba(148, 163, 184, 0.15); color: #94a3b8; }
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
                <a href="manage_users.php" class="sidebar-item active">
                    <i data-lucide="users" class="w-4 h-4"></i>
                    Manage Users
                </a>
                <a href="manage_technicians.php" class="sidebar-item">
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
                    <h1 class="text-xl font-bold text-white glow-text">Manage Users</h1>
                    <p class="text-xs text-[#666] mt-0.5">System user management</p>
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
            <div class="grid grid-cols-4 gap-4 mb-6">
                <div class="cyber-card p-4">
                    <div class="flex items-center gap-3">
                        <div class="stat-icon">
                            <i data-lucide="users" class="w-5 h-5 text-[#00ff88]"></i>
                        </div>
                        <div>
                            <p class="text-2xl font-bold text-white"><?php echo $total_users; ?></p>
                            <p class="text-[10px] text-[#666] uppercase">Total Users</p>
                        </div>
                    </div>
                </div>
                <div class="cyber-card p-4">
                    <div class="flex items-center gap-3">
                        <div class="stat-icon" style="background: rgba(239, 68, 68, 0.1); border-color: rgba(239, 68, 68, 0.2);">
                            <i data-lucide="shield" class="w-5 h-5 text-[#ef4444]"></i>
                        </div>
                        <div>
                            <p class="text-2xl font-bold text-white"><?php echo $admin_count; ?></p>
                            <p class="text-[10px] text-[#666] uppercase">Admins</p>
                        </div>
                    </div>
                </div>
                <div class="cyber-card p-4">
                    <div class="flex items-center gap-3">
                        <div class="stat-icon" style="background: rgba(59, 130, 246, 0.1); border-color: rgba(59, 130, 246, 0.2);">
                            <i data-lucide="wrench" class="w-5 h-5 text-[#60a5fa]"></i>
                        </div>
                        <div>
                            <p class="text-2xl font-bold text-white"><?php echo $tech_count; ?></p>
                            <p class="text-[10px] text-[#666] uppercase">Technicians</p>
                        </div>
                    </div>
                </div>
                <div class="cyber-card p-4">
                    <div class="flex items-center gap-3">
                        <div class="stat-icon" style="background: rgba(251, 191, 36, 0.1); border-color: rgba(251, 191, 36, 0.2);">
                            <i data-lucide="clock" class="w-5 h-5 text-[#fbbf24]"></i>
                        </div>
                        <div>
                            <p class="text-2xl font-bold text-white"><?php echo $pending_count; ?></p>
                            <p class="text-[10px] text-[#666] uppercase">Pending Approvals</p>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Pending Approvals -->
            <?php if ($pending_users && $pending_users->num_rows > 0): ?>
            <div class="cyber-card p-4 mb-6" style="border-color: rgba(251, 191, 36, 0.25);">
                <h2 class="text-sm font-semibold text-white mb-4 flex items-center gap-2">
                    <i data-lucide="clock" class="w-4 h-4 text-[#fbbf24]"></i>
                    Pending Approvals (<?php echo $pending_users->num_rows; ?>)
                </h2>
                <div class="overflow-x-auto">
                    <table class="cyber-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Department</th>
                                <th>Expires</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($p = $pending_users->fetch_assoc()): ?>
                                <tr>
                                    <td class="text-[#fbbf24] font-mono">#<?php echo $p['id']; ?></td>
                                    <td class="text-white font-medium"><?php echo htmlspecialchars($p['name']); ?></td>
                                    <td class="text-[#ccc]"><?php echo htmlspecialchars($p['email']); ?></td>
                                    <td class="text-[#ccc]"><?php echo htmlspecialchars($p['department']); ?></td>
                                    <td class="text-xs">
                                        <?php if (!empty($p['pending_expires_at'])): ?>
                                            <?php
                                            $exp = strtotime($p['pending_expires_at']);
                                            $hours = max(0, ceil(($exp - time()) / 3600));
                                            if ($hours <= 24) {
                                                echo '<span class="text-[#ef4444] font-semibold">' . $hours . 'h left</span>';
                                            } else {
                                                echo '<span class="text-[#fbbf24]">' . date('M d, H:i', $exp) . '</span>';
                                            }
                                            ?>
                                        <?php else: ?>
                                            <span class="text-[#666]">No expiry</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <form method="POST" class="inline">
                                            <input type="hidden" name="action" value="approve_user">
                                            <input type="hidden" name="user_id" value="<?php echo $p['id']; ?>">
                                            <button type="submit" class="cyber-btn px-2 py-1 text-xs">
                                                <i data-lucide="check" class="w-3 h-3"></i> Approve
                                            </button>
                                        </form>
                                        <form method="POST" class="inline ml-1" onsubmit="return confirm('Reject this request?')">
                                            <input type="hidden" name="action" value="reject_user">
                                            <input type="hidden" name="user_id" value="<?php echo $p['id']; ?>">
                                            <button type="submit" class="cyber-btn-danger px-2 py-1 text-xs">
                                                <i data-lucide="x" class="w-3 h-3"></i> Reject
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>

            <!-- Add User Form -->
            <div class="cyber-card p-4 mb-6">
                <h2 class="text-sm font-semibold text-white mb-4 flex items-center gap-2">
                    <i data-lucide="user-plus" class="w-4 h-4 text-[#00ff88]"></i>
                    Add New User
                </h2>
                <form method="POST" class="flex flex-wrap gap-4 items-end">
                    <input type="hidden" name="action" value="add_user">
                    <div class="flex-1 min-w-[180px]">
                        <label class="block text-[10px] text-[#666] uppercase tracking-wider mb-2">Full Name</label>
                        <input type="text" name="name" class="cyber-input" placeholder="John Doe" required>
                    </div>
                    <div class="flex-1 min-w-[200px]">
                        <label class="block text-[10px] text-[#666] uppercase tracking-wider mb-2">Email</label>
                        <input type="email" name="email" class="cyber-input" placeholder="john@mcc.co.zw" required>
                    </div>
                    <div class="flex-1 min-w-[150px]">
                        <label class="block text-[10px] text-[#666] uppercase tracking-wider mb-2">Password</label>
                        <input type="password" name="password" class="cyber-input" placeholder="******" required>
                    </div>
                    <div class="flex-1 min-w-[140px]">
                        <label class="block text-[10px] text-[#666] uppercase tracking-wider mb-2">Department</label>
                        <select name="department" class="cyber-select" required>
                            <option value="">Select</option>
                            <option value="Finance">Finance</option>
                            <option value="HR">Human Resources</option>
                            <option value="Administration">Administration</option>
                            <option value="Engineering">Engineering</option>
                            <option value="Health">Health Services</option>
                            <option value="Education">Education</option>
                            <option value="Housing">Housing</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                    <button type="submit" class="cyber-btn">
                        <i data-lucide="plus" class="w-4 h-4"></i>
                        Add User
                    </button>
                </form>
            </div>
            
            <!-- Users Table -->
            <div class="cyber-card p-4">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-sm font-semibold text-white flex items-center gap-2">
                        <i data-lucide="users" class="w-4 h-4 text-[#00ff88]"></i>
                        System Users (<?php echo $users ? $users->num_rows : 0; ?>)
                    </h2>
                    <div class="relative">
                        <i data-lucide="search" class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-[#444]"></i>
                        <input type="text" id="searchUsers" class="cyber-input pl-10 w-64" placeholder="Search users..." onkeyup="filterTable()">
                    </div>
                </div>
                
                <div class="overflow-x-auto">
                    <table class="cyber-table" id="usersTable">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Department</th>
                                <th>Status</th>
                                <th>Created</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($users && $users->num_rows > 0): ?>
                                <?php while ($user = $users->fetch_assoc()): ?>
                                    <?php
                                        $status_badge = '';
                                        switch ($user['status']) {
                                            case 'active': $status_badge = '<span class="badge badge-user">Active</span>'; break;
                                            case 'pending': $status_badge = '<span class="badge badge-pending">Pending</span>'; break;
                                            case 'rejected': $status_badge = '<span class="badge badge-rejected">Rejected</span>'; break;
                                            case 'suspended': $status_badge = '<span class="badge badge-suspended">Suspended</span>'; break;
                                            default: $status_badge = '<span class="badge badge-user">Active</span>';
                                        }
                                    ?>
                                    <tr data-search="<?php echo strtolower($user['name'] . ' ' . $user['email'] . ' ' . $user['department'] . ' ' . $user['status']); ?>">
                                        <td class="text-[#00ff88] font-mono">#<?php echo $user['id']; ?></td>
                                        <td class="text-white font-medium"><?php echo htmlspecialchars($user['name']); ?></td>
                                        <td class="text-[#ccc]"><?php echo htmlspecialchars($user['email']); ?></td>
                                        <td class="text-[#ccc]"><?php echo htmlspecialchars($user['department']); ?></td>
                                        <td><?php echo $status_badge; ?></td>
                                        <td class="text-[#666] text-xs"><?php echo date('M d, Y', strtotime($user['created_at'])); ?></td>
                                        <td>
                                            <?php if ($user['id'] != $_SESSION['user_id']): ?>
                                                <?php if ($user['status'] == 'pending'): ?>
                                                    <form method="POST" class="inline">
                                                        <input type="hidden" name="action" value="approve_user">
                                                        <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                                        <button type="submit" class="cyber-btn px-2 py-1 text-xs" title="Approve">
                                                            <i data-lucide="check" class="w-3 h-3"></i>
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                                <?php if ($user['status'] == 'active'): ?>
                                                    <form method="POST" class="inline" onsubmit="return confirm('Suspend this user?')">
                                                        <input type="hidden" name="action" value="suspend_user">
                                                        <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                                        <button type="submit" class="cyber-btn px-2 py-1 text-xs" style="background: linear-gradient(135deg,#fbbf24,#f59e0b); color:#050507;" title="Suspend">
                                                            <i data-lucide="pause" class="w-3 h-3"></i>
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                                <?php if ($user['status'] == 'suspended'): ?>
                                                    <form method="POST" class="inline">
                                                        <input type="hidden" name="action" value="activate_user">
                                                        <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                                        <button type="submit" class="cyber-btn px-2 py-1 text-xs" title="Activate">
                                                            <i data-lucide="play" class="w-3 h-3"></i>
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                                <form method="POST" class="inline ml-1" onsubmit="return confirm('Delete this user?')">
                                                    <input type="hidden" name="action" value="delete_user">
                                                    <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                                    <button type="submit" class="cyber-btn-danger px-2 py-1 text-xs">
                                                        <i data-lucide="trash-2" class="w-3 h-3"></i>
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                <span class="text-[#444] text-xs">Current User</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="8" class="text-center text-[#666] py-12">
                                        <div class="flex flex-col items-center gap-3">
                                            <i data-lucide="users" class="w-12 h-12 text-[#333]"></i>
                                            <span class="text-sm">No users found</span>
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
        
        function filterTable() {
            const input = document.getElementById('searchUsers');
            const filter = input.value.toLowerCase();
            const rows = document.querySelectorAll('#usersTable tbody tr');
            
            rows.forEach(row => {
                const searchText = row.getAttribute('data-search');
                row.style.display = searchText.includes(filter) ? '' : 'none';
            });
        }
    </script>
</body>
</html>
