<?php
/**
 * Submit Ticket
 * Smart ICT Helpdesk System - Mutare City Council
 */

require_once '../config/auth_helper.php';
require_once '../config/database.php';

requireRole('user');

$database = new Database();
$conn = $database->getConnection();

$success = '';
$error = '';
$knowledge_suggestions = [];

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $title = trim($_POST['title']);
    $description = trim($_POST['description']);
    $category = $_POST['category'];
    $priority = $_POST['priority'];
    
    // Validation
    if (empty($title)) {
        $error = 'Ticket title is required';
    } elseif (empty($description)) {
        $error = 'Ticket description is required';
    } elseif (empty($category)) {
        $error = 'Category is required';
    } else {
        // Auto-assign priority based on category or user selection
        if (empty($priority)) {
            switch ($category) {
                case 'network':
                    $priority = 'high';
                    break;
                case 'login':
                    $priority = 'medium';
                    break;
                case 'hardware':
                    $priority = 'low';
                    break;
                default:
                    $priority = 'medium';
            }
        }
        
        // Insert ticket
        $title = $conn->real_escape_string($title);
        $description = $conn->real_escape_string($description);
        $category = $conn->real_escape_string($category);
        $priority = $conn->real_escape_string($priority);
        $department = $conn->real_escape_string($_SESSION['user_department']);
        $created_by = $_SESSION['user_id'];
        
        $insert_query = "INSERT INTO tickets (title, description, department, category, priority, status, created_by) 
                         VALUES ('$title', '$description', '$department', '$category', '$priority', 'open', $created_by)";
        
        if ($conn->query($insert_query)) {
            $ticket_id = $conn->getLastId();
            
            // Auto-assign technician
            require_once '../system/auto_assign.php';
            autoAssignTechnician($ticket_id, $category);
            
            $success = 'Ticket submitted successfully! Your ticket ID is #' . $ticket_id . '. A technician will be assigned shortly.';
            logActivity('SUBMIT_TICKET', "Submitted ticket: $title");
            
            // Clear form
            $_POST = [];
        } else {
            $error = 'Failed to submit ticket. Please try again.';
        }
    }
}

// Search knowledge base for suggestions if description is provided
if (isset($_POST['description']) && !empty(trim($_POST['description']))) {
    $description = trim($_POST['description']);
    $keywords = preg_split('/[\s,\.!?]+/', strtolower($description));
    $keywords = array_filter($keywords, function($keyword) {
        return strlen($keyword) > 3;
    });
    
    if (!empty($keywords)) {
        $keyword_conditions = [];
        foreach ($keywords as $keyword) {
            $keyword_conditions[] = "issue_keyword LIKE '%" . $conn->real_escape_string($keyword) . "%'";
        }
        
        $kb_query = "SELECT * FROM knowledge_base WHERE " . implode(' OR ', $keyword_conditions) . " LIMIT 5";
        $kb_result = $conn->query($kb_query);
        
        if ($kb_result && $kb_result->num_rows > 0) {
            while ($row = $kb_result->fetch_assoc()) {
                $knowledge_suggestions[] = $row;
            }
        }
    }
}

logActivity('VIEW_SUBMIT_TICKET', 'User viewed ticket submission page');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Submit Ticket - MCC ICT Helpdesk</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
    <!-- Mobile Menu Toggle -->
    <button class="mobile-menu-toggle"><?php echo getLucideIcon('menu', 20); ?></button>

    <!-- Sidebar -->
    <div class="sidebar">
        <div class="sidebar-header">
            <div class="logo">
                <img src="../assets/images/mutarelogo.png" alt="MCC Logo">
                <div class="logo-text">MCC Helpdesk</div>
            </div>
        </div>
        <nav class="nav-menu">
            <?php $menu = getNavigationMenu('user'); ?>
            <?php foreach ($menu as $item): ?>
                <a href="<?php echo $item['url']; ?>" class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == basename($item['url']) ? 'active' : ''; ?>">
                    <?php echo getLucideIcon($item['icon'], 16); ?> <?php echo $item['title']; ?>
                </a>
            <?php endforeach; ?>
            <a href="../auth/logout.php" class="nav-item" style="margin-top: auto; border-top: 1px solid #334155;">
                <?php echo getLucideIcon('log-out', 16); ?> Logout
            </a>
        </nav>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Header -->
        <div class="header">
            <div class="header-title">Submit New Ticket</div>
            <div class="user-info">
                <span>Welcome, <?php echo htmlspecialchars($_SESSION['user_name']); ?></span>
                <div class="user-avatar"><?php echo strtoupper(substr($_SESSION['user_name'], 0, 1)); ?></div>
            </div>
        </div>

        <?php echo displaySuccess(); ?>
        <?php echo displayError(); ?>
        
        <?php if ($success): ?>
            <div class="alert alert-success fade-in">
                <?php echo $success; ?>
                <div style="margin-top: 1rem;">
                    <a href="my_requests.php" class="btn btn-primary">View My Tickets</a>
                    <a href="submit_ticket.php" class="btn btn-secondary">Submit Another Ticket</a>
                </div>
            </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="alert alert-error fade-in"><?php echo $error; ?></div>
        <?php endif; ?>

        <?php if (empty($success)): ?>
        <!-- Ticket Submission Form -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Create New Support Ticket</h3>
            </div>
            <form method="POST" action="" id="ticketForm" onsubmit="return validateForm('ticketForm')">
                <div class="form-group">
                    <label for="title" class="form-label">Ticket Title *</label>
                    <input type="text" id="title" name="title" class="form-input" 
                           placeholder="Brief description of your issue" required
                           value="<?php echo isset($_POST['title']) ? htmlspecialchars($_POST['title']) : ''; ?>">
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                    <div class="form-group">
                        <label for="category" class="form-label">Category *</label>
                        <select id="category" name="category" class="form-select" required onchange="updatePriority()">
                            <option value="">Select Category</option>
                            <option value="network" <?php echo (isset($_POST['category']) && $_POST['category'] == 'network') ? 'selected' : ''; ?>>Network Issues</option>
                            <option value="hardware" <?php echo (isset($_POST['category']) && $_POST['category'] == 'hardware') ? 'selected' : ''; ?>>Hardware Problems</option>
                            <option value="software" <?php echo (isset($_POST['category']) && $_POST['category'] == 'software') ? 'selected' : ''; ?>>Software Issues</option>
                            <option value="login" <?php echo (isset($_POST['category']) && $_POST['category'] == 'login') ? 'selected' : ''; ?>>Login/Account Issues</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="priority" class="form-label">Priority</label>
                        <select id="priority" name="priority" class="form-select">
                            <option value="">Auto-assign (Recommended)</option>
                            <option value="high" <?php echo (isset($_POST['priority']) && $_POST['priority'] == 'high') ? 'selected' : ''; ?>>High - Server/Network Outage</option>
                            <option value="medium" <?php echo (isset($_POST['priority']) && $_POST['priority'] == 'medium') ? 'selected' : ''; ?>>Medium - Account/Login Issues</option>
                            <option value="low" <?php echo (isset($_POST['priority']) && $_POST['priority'] == 'low') ? 'selected' : ''; ?>>Low - Printer/Minor Issues</option>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label for="description" class="form-label">Detailed Description *</label>
                    <textarea id="description" name="description" class="form-textarea" rows="6" 
                              placeholder="Please provide as much detail as possible about your issue..." required
                              onkeyup="searchKnowledgeBase(this.value)"><?php echo isset($_POST['description']) ? htmlspecialchars($_POST['description']) : ''; ?></textarea>
                    <div style="font-size: 0.875rem; color: #94a3b8; margin-top: 0.25rem;">
                        Include any error messages, steps to reproduce the issue, and what you've already tried.
                    </div>
                </div>

                <!-- Knowledge Base Suggestions -->
                <div id="knowledgeSuggestions" style="display: none;">
                    <div class="alert alert-info">
                        <strong><?php echo getLucideIcon('lightbulb', 16); ?> Suggested Solutions from Knowledge Base:</strong>
                        <div id="suggestionsList" style="margin-top: 0.5rem;"></div>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-top: 1rem;">
                    <div style="background: #0f172a; padding: 1rem; border-radius: 8px; border: 1px solid #334155;">
                        <h4 style="margin-bottom: 0.5rem; color: #3b82f6;"><?php echo getLucideIcon('clipboard-list', 16); ?> Your Information</h4>
                        <div style="font-size: 0.875rem; color: #94a3b8;">
                            <strong>Name:</strong> <?php echo htmlspecialchars($_SESSION['user_name']); ?><br>
                            <strong>Department:</strong> <?php echo htmlspecialchars($_SESSION['user_department']); ?><br>
                            <strong>Email:</strong> <?php echo htmlspecialchars($_SESSION['user_email']); ?>
                        </div>
                    </div>
                    <div style="background: #0f172a; padding: 1rem; border-radius: 8px; border: 1px solid #334155;">
                        <h4 style="margin-bottom: 0.5rem; color: #10b981;"><?php echo getLucideIcon('zap', 16); ?> Quick Tips</h4>
                        <ul style="font-size: 0.875rem; color: #94a3b8; margin: 0; padding-left: 1.5rem;">
                            <li>Be specific about your issue</li>
                            <li>Include error messages</li>
                            <li>Mention what you've tried</li>
                            <li>High priority for system outages</li>
                        </ul>
                    </div>
                </div>

                <div style="margin-top: 1.5rem;">
                    <button type="submit" class="btn btn-primary">Submit Ticket</button>
                    <button type="reset" class="btn btn-secondary">Clear Form</button>
                </div>
            </form>
        </div>
        <?php endif; ?>

        <!-- Recent Tickets Summary -->
        <?php if (empty($success)): ?>
        <div class="card" style="margin-top: 1.5rem;">
            <div class="card-header">
                <h3 class="card-title">Your Recent Tickets</h3>
                <a href="my_requests.php" class="btn btn-sm btn-secondary">View All</a>
            </div>
            <div class="table-container">
                <table class="table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Title</th>
                            <th>Status</th>
                            <th>Priority</th>
                            <th>Created</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $recent_query = "SELECT id, title, status, priority, created_at 
                                       FROM tickets 
                                       WHERE created_by = " . $_SESSION['user_id'] . " 
                                       ORDER BY created_at DESC 
                                       LIMIT 5";
                        $recent_result = $conn->query($recent_query);
                        
                        if ($recent_result && $recent_result->num_rows > 0):
                            while ($ticket = $recent_result->fetch_assoc()):
                        ?>
                            <tr>
                                <td>#<?php echo $ticket['id']; ?></td>
                                <td><?php echo htmlspecialchars($ticket['title']); ?></td>
                                <td><?php echo getStatusBadge($ticket['status']); ?></td>
                                <td><?php echo getPriorityBadge($ticket['priority']); ?></td>
                                <td><?php echo timeAgo($ticket['created_at']); ?></td>
                            </tr>
                        <?php 
                            endwhile; 
                        else:
                        ?>
                            <tr>
                                <td colspan="5" style="text-align: center;">No tickets submitted yet</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <script src="../assets/js/script.js"></script>
    <script>
        // Auto-update priority based on category
        function updatePriority() {
            const category = document.getElementById('category').value;
            const priority = document.getElementById('priority');
            
            if (category && !priority.value) {
                switch (category) {
                    case 'network':
                        priority.value = 'high';
                        break;
                    case 'login':
                        priority.value = 'medium';
                        break;
                    case 'hardware':
                        priority.value = 'low';
                        break;
                    default:
                        priority.value = 'medium';
                }
            }
        }

        // Search knowledge base
        function searchKnowledgeBase(description) {
            if (description.length < 10) {
                document.getElementById('knowledgeSuggestions').style.display = 'none';
                return;
            }

            // Simulate knowledge base search (in real implementation, this would be an AJAX call)
            const keywords = description.toLowerCase().split(/\s+/);
            const suggestions = [
                { keyword: 'password', solution: 'Try resetting your password using the password reset link or contact IT for assistance.' },
                { keyword: 'network', solution: 'Check your network cable connection and restart your computer. If issues persist, contact the network team.' },
                { keyword: 'printer', solution: 'Ensure the printer is turned on, connected to the network, and has paper and ink. Try restarting the printer.' },
                { keyword: 'login', solution: 'Verify your username and password. Check if Caps Lock is on. Try clearing your browser cache.' },
                { keyword: 'software', solution: 'Try restarting the application. Check if you have the latest version. Reinstall if necessary.' }
            ];

            const matchedSuggestions = suggestions.filter(s => 
                keywords.some(keyword => s.keyword.includes(keyword))
            );

            if (matchedSuggestions.length > 0) {
                const suggestionsList = document.getElementById('suggestionsList');
                suggestionsList.innerHTML = matchedSuggestions.map(s => 
                    `<div style="margin-bottom: 0.5rem; padding: 0.5rem; background: #1e293b; border-radius: 4px;">
                        ${s.solution}
                    </div>`
                ).join('');
                document.getElementById('knowledgeSuggestions').style.display = 'block';
            } else {
                document.getElementById('knowledgeSuggestions').style.display = 'none';
            }
        }

        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            updatePriority();
        });
    </script>
</body>
</html>
