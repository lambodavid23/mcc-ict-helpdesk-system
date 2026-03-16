<?php
/**
 * Manage Technicians
 * Smart ICT Helpdesk System - Mutare City Council
 */

require_once '../config/auth_helper.php';
require_once '../config/database.php';

requireRole('admin');

$database = new Database();
$conn = $database->getConnection();

$success = '';
$error = '';

// Handle technician actions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add_technician':
                $name = trim($_POST['name']);
                $specialization = $_POST['specialization'];
                $phone = trim($_POST['phone']);
                $email = trim($_POST['email']);
                
                // Validation
                if (empty($name) || empty($specialization)) {
                    $error = 'Name and specialization are required';
                } else {
                    // Add technician
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
                
                // Validation
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
                
                // Check if technician has active tickets
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

// Get all technicians with workload info
$technicians_query = "SELECT t.*, 
                             COUNT(CASE WHEN tk.status IN ('open', 'in_progress') THEN 1 END) as active_tickets,
                             COUNT(CASE WHEN tk.status = 'resolved' THEN 1 END) as resolved_tickets
                      FROM technicians t
                      LEFT JOIN tickets tk ON t.id = tk.assigned_to
                      GROUP BY t.id
                      ORDER BY t.name";
$technicians = $conn->query($technicians_query);

logActivity('VIEW_MANAGE_TECHNICIANS', 'Admin viewed technician management page');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Technicians - MCC ICT Helpdesk</title>
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
            <div class="header-title">Manage Technicians</div>
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

        <!-- Add Technician Form -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Add New Technician</h3>
            </div>
            <form method="POST" action="" id="addTechnicianForm" onsubmit="return validateForm('addTechnicianForm')">
                <input type="hidden" name="action" value="add_technician">
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1rem;">
                    <div class="form-group">
                        <label for="name" class="form-label">Full Name</label>
                        <input type="text" id="name" name="name" class="form-input" required>
                    </div>
                    <div class="form-group">
                        <label for="specialization" class="form-label">Specialization</label>
                        <select id="specialization" name="specialization" class="form-select" required>
                            <option value="">Select Specialization</option>
                            <option value="network">Network</option>
                            <option value="hardware">Hardware</option>
                            <option value="software">Software</option>
                            <option value="general">General</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="phone" class="form-label">Phone Number</label>
                        <input type="tel" id="phone" name="phone" class="form-input" placeholder="+263...">
                    </div>
                    <div class="form-group">
                        <label for="email" class="form-label">Email Address</label>
                        <input type="email" id="email" name="email" class="form-input" placeholder="technician@mcc.co.zw">
                    </div>
                </div>
                <button type="submit" class="btn btn-primary">Add Technician</button>
            </form>
        </div>

        <!-- Technicians Table -->
        <div class="card" style="margin-top: 1.5rem;">
            <div class="card-header">
                <h3 class="card-title">Technicians</h3>
                <div>
                    <input type="text" id="searchTechnicians" class="form-input" placeholder="Search technicians..." style="width: 200px;">
                </div>
            </div>
            <div class="table-container">
                <table class="table" id="techniciansTable">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Name</th>
                            <th>Specialization</th>
                            <th>Status</th>
                            <th>Workload</th>
                            <th>Active Tickets</th>
                            <th>Resolved</th>
                            <th>Contact</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($technicians && $technicians->num_rows > 0): ?>
                            <?php while ($tech = $technicians->fetch_assoc()): ?>
                                <tr>
                                    <td><?php echo $tech['id']; ?></td>
                                    <td><?php echo htmlspecialchars($tech['name']); ?></td>
                                    <td><?php echo ucfirst($tech['specialization']); ?></td>
                                    <td><?php echo getStatusBadge($tech['status']); ?></td>
                                    <td>
                                        <div style="display: flex; align-items: center; gap: 0.5rem;">
                                            <div style="flex: 1; background: #0f172a; border-radius: 4px; height: 8px; overflow: hidden;">
                                                <div style="background: <?php echo $tech['current_workload'] > 5 ? '#ef4444' : ($tech['current_workload'] > 2 ? '#fbbf24' : '#10b981'); ?>; height: 100%; width: <?php echo min($tech['current_workload'] * 10, 100); ?>%;"></div>
                                            </div>
                                            <span style="font-size: 0.875rem;"><?php echo $tech['current_workload']; ?></span>
                                        </div>
                                    </td>
                                    <td><?php echo $tech['active_tickets']; ?></td>
                                    <td><?php echo $tech['resolved_tickets']; ?></td>
                                    <td>
                                        <?php if ($tech['phone']): ?>
                                            <div style="font-size: 0.875rem;"><?php echo htmlspecialchars($tech['phone']); ?></div>
                                        <?php endif; ?>
                                        <?php if ($tech['email']): ?>
                                            <div style="font-size: 0.875rem; color: #3b82f6;"><?php echo htmlspecialchars($tech['email']); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <button onclick="editTechnician(<?php echo $tech['id']; ?>, '<?php echo htmlspecialchars($tech['name']); ?>', '<?php echo $tech['specialization']; ?>', '<?php echo $tech['status']; ?>', '<?php echo htmlspecialchars($tech['phone']); ?>', '<?php echo htmlspecialchars($tech['email']); ?>')" class="btn btn-sm btn-secondary">Edit</button>
                                        <form method="POST" action="" style="display: inline; margin-left: 0.5rem;" onsubmit="return confirm('Are you sure you want to delete this technician?')">
                                            <input type="hidden" name="action" value="delete_technician">
                                            <input type="hidden" name="tech_id" value="<?php echo $tech['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                                        </form>
                                        <form method="POST" action="" style="display: inline; margin-left: 0.5rem;" onsubmit="return confirm('Are you sure you want to reset workload?')">
                                            <input type="hidden" name="action" value="reset_workload">
                                            <input type="hidden" name="tech_id" value="<?php echo $tech['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-secondary">Reset</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="9" style="text-align: center;">No technicians found</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Edit Technician Modal -->
    <div id="editModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
        <div class="card" style="width: 90%; max-width: 500px; max-height: 90vh; overflow-y: auto;">
            <div class="card-header">
                <h3 class="card-title">Edit Technician</h3>
                <button onclick="closeEditModal()" style="background: none; border: none; color: #94a3b8; font-size: 1.5rem; cursor: pointer;">&times;</button>
            </div>
            <form method="POST" action="" id="editTechnicianForm">
                <input type="hidden" name="action" value="update_technician">
                <input type="hidden" id="edit_tech_id" name="tech_id">
                <div class="form-group">
                    <label for="edit_name" class="form-label">Full Name</label>
                    <input type="text" id="edit_name" name="name" class="form-input" required>
                </div>
                <div class="form-group">
                    <label for="edit_specialization" class="form-label">Specialization</label>
                    <select id="edit_specialization" name="specialization" class="form-select" required>
                        <option value="network">Network</option>
                        <option value="hardware">Hardware</option>
                        <option value="software">Software</option>
                        <option value="general">General</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="edit_status" class="form-label">Status</label>
                    <select id="edit_status" name="status" class="form-select" required>
                        <option value="available">Available</option>
                        <option value="busy">Busy</option>
                        <option value="offline">Offline</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="edit_phone" class="form-label">Phone Number</label>
                    <input type="tel" id="edit_phone" name="phone" class="form-input">
                </div>
                <div class="form-group">
                    <label for="edit_email" class="form-label">Email Address</label>
                    <input type="email" id="edit_email" name="email" class="form-input">
                </div>
                <div style="display: flex; gap: 1rem;">
                    <button type="submit" class="btn btn-primary">Update Technician</button>
                    <button type="button" onclick="closeEditModal()" class="btn btn-secondary">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <script src="../assets/js/script.js"></script>
    <script>
        // Search functionality
        searchTable('techniciansTable', 'searchTechnicians');

        // Edit technician functions
        function editTechnician(id, name, specialization, status, phone, email) {
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

        // Close modal when clicking outside
        document.getElementById('editModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeEditModal();
            }
        });
    </script>
</body>
</html>
