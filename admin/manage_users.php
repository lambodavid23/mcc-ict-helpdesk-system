<?php
/**
 * Manage Users
 * Smart ICT Helpdesk System - Mutare City Council
 */

require_once '../config/auth_helper.php';
require_once '../config/database.php';

requireRole('admin');

$database = new Database();
$conn = $database->getConnection();

$success = '';
$error = '';

// Handle user actions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add_user':
                $name = trim($_POST['name']);
                $email = trim($_POST['email']);
                $password = $_POST['password'];
                $role = $_POST['role'];
                $department = $_POST['department'];
                
                // Validation
                if (empty($name) || empty($email) || empty($password) || empty($role) || empty($department)) {
                    $error = 'All fields are required';
                } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $error = 'Invalid email format';
                } else {
                    // Check if email exists
                    $check_query = "SELECT id FROM users WHERE email = '" . $conn->real_escape_string($email) . "'";
                    $check_result = $conn->query($check_query);
                    
                    if ($check_result && $check_result->num_rows > 0) {
                        $error = 'Email address already exists';
                    } else {
                        // Add user
                        $name = $conn->real_escape_string($name);
                        $email = $conn->real_escape_string($email);
                        $role = $conn->real_escape_string($role);
                        $department = $conn->real_escape_string($department);
                        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                        
                        $insert_query = "INSERT INTO users (name, email, password, role, department) 
                                       VALUES ('$name', '$email', '$hashed_password', '$role', '$department')";
                        
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
                
                // Prevent deleting self
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
                
            case 'update_role':
                $user_id = $_POST['user_id'];
                $new_role = $_POST['new_role'];
                
                // Prevent changing own role
                if ($user_id == $_SESSION['user_id']) {
                    $error = 'You cannot change your own role';
                } else {
                    $update_query = "UPDATE users SET role = '" . $conn->real_escape_string($new_role) . "' WHERE id = " . (int)$user_id;
                    if ($conn->query($update_query)) {
                        $success = 'User role updated successfully';
                        logActivity('UPDATE_USER_ROLE', "Updated user ID $user_id role to $new_role");
                    } else {
                        $error = 'Failed to update user role';
                    }
                }
                break;
        }
    }
}

// Get all users
$users_query = "SELECT id, name, email, role, department, created_at FROM users ORDER BY created_at DESC";
$users = $conn->query($users_query);

logActivity('VIEW_MANAGE_USERS', 'Admin viewed user management page');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Users - MCC ICT Helpdesk</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
    <!-- Mobile Menu Toggle -->
    <button class="mobile-menu-toggle">☰</button>

    <!-- Sidebar -->
    <div class="sidebar">
        <div class="sidebar-header">
            <div class="logo">
                <img src="../assets/images/mutarelogo.png" alt="MCC Logo">
                <div class="logo-text">MCC Helpdesk</div>
            </div>
        </div>
        <nav class="nav-menu">
            <?php $menu = getNavigationMenu('admin'); ?>
            <?php foreach ($menu as $item): ?>
                <a href="<?php echo $item['url']; ?>" class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == basename($item['url']) ? 'active' : ''; ?>">
                    <?php echo $item['icon']; ?> <?php echo $item['title']; ?>
                </a>
            <?php endforeach; ?>
            <a href="../auth/logout.php" class="nav-item" style="margin-top: auto; border-top: 1px solid #334155;">
                🚪 Logout
            </a>
        </nav>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Header -->
        <div class="header">
            <div class="header-title">Manage Users</div>
            <div class="user-info">
                <span>Welcome, <?php echo htmlspecialchars($_SESSION['user_name']); ?></span>
                <div class="user-avatar"><?php echo strtoupper(substr($_SESSION['user_name'], 0, 1)); ?></div>
            </div>
        </div>

        <?php echo displaySuccess(); ?>
        <?php echo displayError(); ?>
        
        <?php if ($success): ?>
            <div class="alert alert-success fade-in"><?php echo $success; ?></div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="alert alert-error fade-in"><?php echo $error; ?></div>
        <?php endif; ?>

        <!-- Add User Form -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Add New User</h3>
            </div>
            <form method="POST" action="" id="addUserForm" onsubmit="return validateForm('addUserForm')">
                <input type="hidden" name="action" value="add_user">
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1rem;">
                    <div class="form-group">
                        <label for="name" class="form-label">Full Name</label>
                        <input type="text" id="name" name="name" class="form-input" required>
                    </div>
                    <div class="form-group">
                        <label for="email" class="form-label">Email Address</label>
                        <input type="email" id="email" name="email" class="form-input" required>
                    </div>
                    <div class="form-group">
                        <label for="password" class="form-label">Password</label>
                        <input type="password" id="password" name="password" class="form-input" required>
                    </div>
                    <div class="form-group">
                        <label for="role" class="form-label">Role</label>
                        <select id="role" name="role" class="form-select" required>
                            <option value="">Select Role</option>
                            <option value="user">User</option>
                            <option value="technician">Technician</option>
                            <option value="admin">Admin</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="department" class="form-label">Department</label>
                        <select id="department" name="department" class="form-select" required>
                            <option value="">Select Department</option>
                            <option value="ICT">ICT</option>
                            <option value="Finance">Finance</option>
                            <option value="HR">Human Resources</option>
                            <option value="Administration">Administration</option>
                            <option value="Engineering">Engineering</option>
                            <option value="Health">Health Services</option>
                            <option value="Education">Education</option>
                            <option value="Housing">Housing</option>
                        </select>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary">Add User</button>
            </form>
        </div>

        <!-- Users Table -->
        <div class="card" style="margin-top: 1.5rem;">
            <div class="card-header">
                <h3 class="card-title">System Users</h3>
                <div>
                    <input type="text" id="searchUsers" class="form-input" placeholder="Search users..." style="width: 200px;">
                </div>
            </div>
            <div class="table-container">
                <table class="table" id="usersTable">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Role</th>
                            <th>Department</th>
                            <th>Created</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($users && $users->num_rows > 0): ?>
                            <?php while ($user = $users->fetch_assoc()): ?>
                                <tr>
                                    <td><?php echo $user['id']; ?></td>
                                    <td><?php echo htmlspecialchars($user['name']); ?></td>
                                    <td><?php echo htmlspecialchars($user['email']); ?></td>
                                    <td>
                                        <?php 
                                        $role_colors = [
                                            'admin' => '#ef4444',
                                            'technician' => '#3b82f6',
                                            'user' => '#10b981'
                                        ];
                                        $color = isset($role_colors[$user['role']]) ? $role_colors[$user['role']] : '#6b7280';
                                        ?>
                                        <span style="background: <?php echo $color; ?>20; color: <?php echo $color; ?>; padding: 0.25rem 0.75rem; border-radius: 9999px; font-size: 0.75rem; font-weight: 500; text-transform: uppercase;">
                                            <?php echo $user['role']; ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($user['department']); ?></td>
                                    <td><?php echo formatDate($user['created_at']); ?></td>
                                    <td>
                                        <?php if ($user['id'] != $_SESSION['user_id']): ?>
                                            <form method="POST" action="" style="display: inline;">
                                                <input type="hidden" name="action" value="update_role">
                                                <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                                <select name="new_role" class="form-select" style="width: auto; display: inline; margin-right: 0.5rem;">
                                                    <option value="user" <?php echo $user['role'] == 'user' ? 'selected' : ''; ?>>User</option>
                                                    <option value="technician" <?php echo $user['role'] == 'technician' ? 'selected' : ''; ?>>Technician</option>
                                                    <option value="admin" <?php echo $user['role'] == 'admin' ? 'selected' : ''; ?>>Admin</option>
                                                </select>
                                                <button type="submit" class="btn btn-sm btn-secondary">Update</button>
                                            </form>
                                            <form method="POST" action="" style="display: inline; margin-left: 0.5rem;" onsubmit="return confirm('Are you sure you want to delete this user?')">
                                                <input type="hidden" name="action" value="delete_user">
                                                <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                                            </form>
                                        <?php else: ?>
                                            <span style="color: #94a3b8; font-size: 0.875rem;">Current User</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="7" style="text-align: center;">No users found</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script src="../assets/js/script.js"></script>
    <script>
        // Search functionality
        searchTable('usersTable', 'searchUsers');
    </script>
</body>
</html>
