<?php
/**
 * Knowledge Base System
 * Smart ICT Helpdesk System - Mutare City Council
 */

require_once '../config/auth_helper.php';
require_once '../config/database.php';

requireLogin();

$database = new Database();
$conn = $database->getConnection();

$success = '';
$error = '';
$search_term = isset($_GET['search']) ? trim($_GET['search']) : '';
$category_filter = isset($_GET['category']) ? $_GET['category'] : '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'];
    
    switch ($action) {
        case 'add_solution':
            // Only admins and technicians can add solutions
            if (!hasRole('admin') && !hasRole('technician')) {
                $error = 'You do not have permission to add solutions';
                break;
            }
            
            $issue_keyword = trim($_POST['issue_keyword']);
            $category = $_POST['category'];
            $solution = trim($_POST['solution']);
            
            if (empty($issue_keyword) || empty($category) || empty($solution)) {
                $error = 'All fields are required';
            } else {
                $issue_keyword = $conn->real_escape_string($issue_keyword);
                $category = $conn->real_escape_string($category);
                $solution = $conn->real_escape_string($solution);
                
                $insert_query = "INSERT INTO knowledge_base (issue_keyword, category, recommended_solution) 
                               VALUES ('$issue_keyword', '$category', '$solution')";
                
                if ($conn->query($insert_query)) {
                    $success = 'Solution added to knowledge base successfully';
                    logActivity('ADD_KNOWLEDGE', "Added knowledge base entry: $issue_keyword");
                } else {
                    $error = 'Failed to add solution';
                }
            }
            break;
            
        case 'update_solution':
            // Only admins can update solutions
            if (!hasRole('admin')) {
                $error = 'You do not have permission to update solutions';
                break;
            }
            
            $kb_id = $_POST['kb_id'];
            $issue_keyword = trim($_POST['issue_keyword']);
            $category = $_POST['category'];
            $solution = trim($_POST['solution']);
            
            if (empty($issue_keyword) || empty($category) || empty($solution)) {
                $error = 'All fields are required';
            } else {
                $issue_keyword = $conn->real_escape_string($issue_keyword);
                $category = $conn->real_escape_string($category);
                $solution = $conn->real_escape_string($solution);
                
                $update_query = "UPDATE knowledge_base 
                               SET issue_keyword = '$issue_keyword', 
                                   category = '$category', 
                                   recommended_solution = '$solution'
                               WHERE id = " . (int)$kb_id;
                
                if ($conn->query($update_query)) {
                    $success = 'Knowledge base entry updated successfully';
                    logActivity('UPDATE_KNOWLEDGE', "Updated knowledge base entry ID: $kb_id");
                } else {
                    $error = 'Failed to update solution';
                }
            }
            break;
            
        case 'delete_solution':
            // Only admins can delete solutions
            if (!hasRole('admin')) {
                $error = 'You do not have permission to delete solutions';
                break;
            }
            
            $kb_id = $_POST['kb_id'];
            
            $delete_query = "DELETE FROM knowledge_base WHERE id = " . (int)$kb_id;
            
            if ($conn->query($delete_query)) {
                $success = 'Knowledge base entry deleted successfully';
                logActivity('DELETE_KNOWLEDGE', "Deleted knowledge base entry ID: $kb_id");
            } else {
                $error = 'Failed to delete solution';
            }
            break;
    }
}

// Build search query
$where_conditions = [];

if (!empty($search_term)) {
    $search = $conn->real_escape_string($search_term);
    $where_conditions[] = "(issue_keyword LIKE '%$search%' OR recommended_solution LIKE '%$search%')";
}

if (!empty($category_filter)) {
    $category = $conn->real_escape_string($category_filter);
    $where_conditions[] = "category = '$category'";
}

$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Get knowledge base entries
$kb_query = "SELECT * FROM knowledge_base $where_clause ORDER BY usage_count DESC, created_at DESC";
$kb_entries = $conn->query($kb_query);

// Get categories for filter
$categories = ['network', 'hardware', 'software', 'login'];

// Get popular solutions
$popular_query = "SELECT * FROM knowledge_base ORDER BY usage_count DESC LIMIT 10";
$popular_solutions = $conn->query($popular_query);

logActivity('VIEW_KNOWLEDGE_BASE', 'User viewed knowledge base');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Knowledge Base - MCC ICT Helpdesk</title>
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
            <?php 
            $role = $_SESSION['user_role'];
            $menu = getNavigationMenu($role); 
            ?>
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
            <div class="header-title">Knowledge Base</div>
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

        <!-- Search and Filter -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Search Knowledge Base</h3>
                <?php if (hasRole('admin') || hasRole('technician')): ?>
                    <button onclick="showAddForm()" class="btn btn-primary">+ Add Solution</button>
                <?php endif; ?>
            </div>
            <form method="GET" action="">
                <div style="display: grid; grid-template-columns: 1fr auto auto; gap: 1rem; align-items: end;">
                    <div class="form-group">
                        <label for="search" class="form-label">Search Solutions</label>
                        <input type="text" id="search" name="search" class="form-input" 
                               placeholder="Search for issues or solutions..." value="<?php echo htmlspecialchars($search_term); ?>">
                    </div>
                    <div class="form-group">
                        <label for="category" class="form-label">Category</label>
                        <select id="category" name="category" class="form-select">
                            <option value="">All Categories</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo $cat; ?>" <?php echo $category_filter == $cat ? 'selected' : ''; ?>>
                                    <?php echo ucfirst($cat); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary">Search</button>
                </div>
            </form>
        </div>

        <!-- Popular Solutions -->
        <?php if (empty($search_term) && empty($category_filter)): ?>
        <div class="card" style="margin-top: 1.5rem;">
            <div class="card-header">
                <h3 class="card-title">🔥 Popular Solutions</h3>
                <span style="color: #94a3b8; font-size: 0.875rem;">Most frequently accessed solutions</span>
            </div>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 1rem;">
                <?php if ($popular_solutions && $popular_solutions->num_rows > 0): ?>
                    <?php while ($solution = $popular_solutions->fetch_assoc()): ?>
                        <div style="background: #0f172a; padding: 1rem; border-radius: 8px; border: 1px solid #334155; cursor: pointer;" 
                             onclick="viewSolution(<?php echo $solution['id']; ?>)">
                            <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 0.5rem;">
                                <h4 style="margin: 0; color: #f1f5f9;"><?php echo htmlspecialchars($solution['issue_keyword']); ?></h4>
                                <span style="background: #3b82f6; color: white; padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.75rem;">
                                    <?php echo $solution['usage_count']; ?> uses
                                </span>
                            </div>
                            <div style="font-size: 0.875rem; color: #94a3b8; margin-bottom: 0.5rem;">
                                Category: <?php echo ucfirst($solution['category']); ?>
                            </div>
                            <div style="font-size: 0.875rem; color: #e2e8f0;">
                                <?php echo htmlspecialchars(substr($solution['recommended_solution'], 0, 100)) . '...'; ?>
                            </div>
                        </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div style="grid-column: 1 / -1; text-align: center; color: #94a3b8; padding: 2rem;">
                        No popular solutions available yet
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Search Results -->
        <div class="card" style="margin-top: 1.5rem;">
            <div class="card-header">
                <h3 class="card-title">
                    <?php if (!empty($search_term) || !empty($category_filter)): ?>
                        Search Results
                    <?php else: ?>
                        All Solutions
                    <?php endif; ?>
                </h3>
                <span style="color: #94a3b8;">
                    <?php echo $kb_entries ? $kb_entries->num_rows : 0; ?> solutions found
                </span>
            </div>
            
            <?php if ($kb_entries && $kb_entries->num_rows > 0): ?>
                <div class="table-container">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Issue Keyword</th>
                                <th>Category</th>
                                <th>Solution</th>
                                <th>Usage</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($solution = $kb_entries->fetch_assoc()): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($solution['issue_keyword']); ?></strong>
                                        <br>
                                        <small style="color: #94a3b8;">Added <?php echo timeAgo($solution['created_at']); ?></small>
                                    </td>
                                    <td>
                                        <span style="background: #1e293b; color: #3b82f6; padding: 0.25rem 0.75rem; border-radius: 9999px; font-size: 0.75rem; text-transform: capitalize;">
                                            <?php echo $solution['category']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div style="max-width: 300px;">
                                            <?php echo nl2br(htmlspecialchars($solution['recommended_solution'])); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div style="text-align: center;">
                                            <div style="font-size: 1.25rem; font-weight: 600;"><?php echo $solution['usage_count']; ?></div>
                                            <div style="font-size: 0.75rem; color: #94a3b8;">times used</div>
                                        </div>
                                    </td>
                                    <td>
                                        <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                                            <button onclick="viewSolution(<?php echo $solution['id']; ?>)" class="btn btn-sm btn-secondary">View</button>
                                            <?php if (hasRole('admin')): ?>
                                                <button onclick="editSolution(<?php echo $solution['id']; ?>)" class="btn btn-sm btn-secondary">Edit</button>
                                                <form method="POST" action="" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this solution?')">
                                                    <input type="hidden" name="action" value="delete_solution">
                                                    <input type="hidden" name="kb_id" value="<?php echo $solution['id']; ?>">
                                                    <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div style="text-align: center; padding: 2rem; color: #94a3b8;">
                    <div style="font-size: 2rem; margin-bottom: 0.5rem;">📚</div>
                    <div>No solutions found</div>
                    <div style="font-size: 0.875rem; margin-top: 0.5rem;">
                        <?php if (!empty($search_term) || !empty($category_filter)): ?>
                            Try adjusting your search terms or 
                            <a href="knowledge_base.php" style="color: #3b82f6;">clear all filters</a>
                        <?php else: ?>
                            <?php if (hasRole('admin') || hasRole('technician')): ?>
                                <a href="#" onclick="showAddForm()" style="color: #3b82f6;">Add the first solution</a>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Add/Edit Solution Modal -->
    <div id="solutionModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
        <div class="card" style="width: 90%; max-width: 600px; max-height: 90vh; overflow-y: auto;">
            <div class="card-header">
                <h3 class="card-title" id="modalTitle">Add Solution</h3>
                <button onclick="closeModal()" style="background: none; border: none; color: #94a3b8; font-size: 1.5rem; cursor: pointer;">&times;</button>
            </div>
            <form method="POST" action="" id="solutionForm">
                <input type="hidden" name="action" id="formAction" value="add_solution">
                <input type="hidden" id="edit_kb_id" name="kb_id">
                
                <div class="form-group">
                    <label for="issue_keyword" class="form-label">Issue Keyword *</label>
                    <input type="text" id="issue_keyword" name="issue_keyword" class="form-input" 
                           placeholder="e.g., password reset, network connection, printer offline" required>
                </div>
                
                <div class="form-group">
                    <label for="category" class="form-label">Category *</label>
                    <select id="category" name="category" class="form-select" required>
                        <option value="">Select Category</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?php echo $cat; ?>"><?php echo ucfirst($cat); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="solution" class="form-label">Recommended Solution *</label>
                    <textarea id="solution" name="solution" class="form-textarea" rows="8" 
                              placeholder="Provide step-by-step solution instructions..." required></textarea>
                </div>
                
                <div style="display: flex; gap: 1rem;">
                    <button type="submit" class="btn btn-primary">Save Solution</button>
                    <button type="button" onclick="closeModal()" class="btn btn-secondary">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- View Solution Modal -->
    <div id="viewModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
        <div class="card" style="width: 90%; max-width: 700px; max-height: 90vh; overflow-y: auto;">
            <div class="card-header">
                <h3 class="card-title">Solution Details</h3>
                <button onclick="closeViewModal()" style="background: none; border: none; color: #94a3b8; font-size: 1.5rem; cursor: pointer;">&times;</button>
            </div>
            <div id="viewContent"></div>
        </div>
    </div>

    <script src="../assets/js/script.js"></script>
    <script>
        // Modal functions
        function showAddForm() {
            document.getElementById('modalTitle').textContent = 'Add Solution';
            document.getElementById('formAction').value = 'add_solution';
            document.getElementById('edit_kb_id').value = '';
            document.getElementById('solutionForm').reset();
            document.getElementById('solutionModal').style.display = 'flex';
        }

        function editSolution(kbId) {
            // In a real implementation, this would fetch the solution data via AJAX
            document.getElementById('modalTitle').textContent = 'Edit Solution';
            document.getElementById('formAction').value = 'update_solution';
            document.getElementById('edit_kb_id').value = kbId;
            
            // Simulate loading data (in real app, this would be an AJAX call)
            document.getElementById('issue_keyword').value = 'Sample Issue';
            document.getElementById('category').value = 'network';
            document.getElementById('solution').value = 'Sample solution text...';
            
            document.getElementById('solutionModal').style.display = 'flex';
        }

        function viewSolution(kbId) {
            // In a real implementation, this would fetch the solution data via AJAX
            const viewContent = `
                <div style="padding: 1rem;">
                    <div style="margin-bottom: 1rem;">
                        <h4 style="color: #3b82f6; margin-bottom: 0.5rem;">Network Connection Issues</h4>
                        <span style="background: #1e293b; color: #3b82f6; padding: 0.25rem 0.75rem; border-radius: 9999px; font-size: 0.75rem;">
                            network
                        </span>
                    </div>
                    <div style="background: #0f172a; padding: 1rem; border-radius: 8px; margin-bottom: 1rem;">
                        <h5 style="margin-bottom: 0.5rem;">Solution Steps:</h5>
                        <ol style="margin: 0; padding-left: 1.5rem;">
                            <li>Check if network cable is properly connected</li>
                            <li>Restart your computer</li>
                            <li>Try connecting to a different network port</li>
                            <li>Contact IT if issue persists</li>
                        </ol>
                    </div>
                    <div style="text-align: center; color: #94a3b8; font-size: 0.875rem;">
                        This solution has been helpful for 15 users
                    </div>
                </div>
            `;
            
            document.getElementById('viewContent').innerHTML = viewContent;
            document.getElementById('viewModal').style.display = 'flex';
            
            // Increment usage count (in real implementation, this would be an AJAX call)
            console.log('Incrementing usage count for solution ID:', kbId);
        }

        function closeModal() {
            document.getElementById('solutionModal').style.display = 'none';
            document.getElementById('solutionForm').reset();
        }

        function closeViewModal() {
            document.getElementById('viewModal').style.display = 'none';
        }

        // Close modals when clicking outside
        document.getElementById('solutionModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeModal();
            }
        });

        document.getElementById('viewModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeViewModal();
            }
        });

        // Auto-suggest keywords as user types
        document.getElementById('issue_keyword').addEventListener('input', function() {
            const value = this.value.toLowerCase();
            if (value.length > 2) {
                // In a real implementation, this would show suggestions from existing keywords
                console.log('Searching for keywords containing:', value);
            }
        });
    </script>
</body>
</html>
