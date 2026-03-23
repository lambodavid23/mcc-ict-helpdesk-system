<?php
require_once '../config/auth_helper.php';
require_once '../config/database.php';

requireRole('user');

$database = new Database();
$conn = $database->getConnection();

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $title = trim($_POST['title']);
    $description = trim($_POST['description']);
    $category = $_POST['category'];
    $priority = $_POST['priority'];
    
    if (empty($title)) {
        $error = 'Ticket title is required';
    } elseif (empty($description)) {
        $error = 'Ticket description is required';
    } elseif (empty($category)) {
        $error = 'Category is required';
    } else {
        if (empty($priority)) {
            switch ($category) {
                case 'network': $priority = 'high'; break;
                case 'login': $priority = 'medium'; break;
                case 'hardware': $priority = 'low'; break;
                default: $priority = 'medium';
            }
        }
        
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
            
            require_once '../system/auto_assign.php';
            autoAssignTechnician($ticket_id, $category);
            
            $success = 'Ticket submitted successfully! Your ticket ID is #' . $ticket_id . '. A technician will be assigned shortly.';
            logActivity('SUBMIT_TICKET', "Submitted ticket: $title");
            $_POST = [];
        } else {
            $error = 'Failed to submit ticket. Please try again.';
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
        .cyber-input {
            width: 100%;
            padding: 0.75rem 1rem;
            padding-left: 2.75rem;
            background: rgba(15, 15, 21, 0.8);
            border: 1px solid #1a1a2e;
            border-radius: 8px;
            color: #e0e0e0;
            font-size: 0.85rem;
            transition: all 0.3s;
        }
        .cyber-input:focus {
            outline: none;
            border-color: #00ff88;
            box-shadow: 0 0 0 2px rgba(0, 255, 136, 0.1);
        }
        .cyber-input::placeholder {
            color: #444;
        }
        .cyber-select {
            width: 100%;
            padding: 0.75rem 1rem;
            padding-left: 2.75rem;
            padding-right: 2.5rem;
            background: rgba(15, 15, 21, 0.8);
            border: 1px solid #1a1a2e;
            border-radius: 8px;
            color: #e0e0e0;
            font-size: 0.85rem;
            transition: all 0.3s;
            appearance: none;
            cursor: pointer;
        }
        .cyber-select:focus {
            outline: none;
            border-color: #00ff88;
            box-shadow: 0 0 0 2px rgba(0, 255, 136, 0.1);
        }
        .cyber-select option {
            background: #0a0a0f;
            color: #e0e0e0;
        }
        .cyber-textarea {
            width: 100%;
            padding: 0.75rem 1rem;
            background: rgba(15, 15, 21, 0.8);
            border: 1px solid #1a1a2e;
            border-radius: 8px;
            color: #e0e0e0;
            font-size: 0.85rem;
            transition: all 0.3s;
            resize: vertical;
            min-height: 120px;
        }
        .cyber-textarea:focus {
            outline: none;
            border-color: #00ff88;
            box-shadow: 0 0 0 2px rgba(0, 255, 136, 0.1);
        }
        .cyber-textarea::placeholder {
            color: #444;
        }
        .cyber-btn {
            background: linear-gradient(135deg, #00ff88, #00cc6a);
            color: #050507;
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            font-size: 0.85rem;
            font-weight: 600;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            border: none;
            cursor: pointer;
        }
        .cyber-btn:hover {
            box-shadow: 0 0 20px rgba(0, 255, 136, 0.3);
            transform: translateY(-1px);
        }
        .cyber-btn-secondary {
            background: transparent;
            border: 1px solid #1a1a2e;
            color: #666;
        }
        .cyber-btn-secondary:hover {
            border-color: #00ff88;
            color: #00ff88;
            box-shadow: none;
        }
        .cyber-btn-danger {
            background: rgba(239, 68, 68, 0.1);
            color: #ef4444;
            border: 1px solid rgba(239, 68, 68, 0.3);
        }
        .cyber-btn-danger:hover {
            background: rgba(239, 68, 68, 0.2);
            box-shadow: 0 0 20px rgba(239, 68, 68, 0.2);
        }
        .cyber-label {
            display: block;
            font-size: 0.75rem;
            font-weight: 600;
            color: #666;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            margin-bottom: 0.5rem;
        }
        .cyber-label span {
            color: #ef4444;
        }
        .input-icon {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: #00ff88;
            pointer-events: none;
        }
        .select-icon {
            position: absolute;
            right: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: #444;
            pointer-events: none;
        }
        .alert-success {
            background: rgba(0, 255, 136, 0.1);
            border: 1px solid rgba(0, 255, 136, 0.3);
            border-radius: 8px;
            padding: 1rem;
            color: #00ff88;
            font-size: 0.85rem;
        }
        .alert-error {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.3);
            border-radius: 8px;
            padding: 1rem;
            color: #ef4444;
            font-size: 0.85rem;
        }
        .info-box {
            background: rgba(15, 15, 21, 0.8);
            border: 1px solid #1a1a2e;
            border-radius: 8px;
            padding: 1rem;
        }
        .info-box h4 {
            color: #00ff88;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 0.5rem;
        }
        .info-box p, .info-box li {
            color: #888;
            font-size: 0.8rem;
            line-height: 1.6;
        }
        .info-box ul {
            margin: 0;
            padding-left: 1.25rem;
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
        .sidebar-item.active {
            border-left: 2px solid #00ff88;
        }
    </style>
</head>
<body class="min-h-screen grid-bg">
    <!-- Scan Line -->
    <div class="fixed top-0 left-0 right-0 h-0.5 bg-gradient-to-r from-transparent via-[#00ff88]/20 to-transparent animate-[scan_8s_linear_infinite] pointer-events-none z-50" style="animation: scan 8s linear infinite;"></div>
    
    <div class="flex min-h-screen">
        <!-- Sidebar -->
        <aside class="w-64 bg-[#0a0a0f]/95 border-r border-[#1a1a2e] p-4 flex flex-col">
            <!-- Logo -->
            <div class="flex items-center gap-3 mb-6 pb-4 border-b border-[#1a1a2e]">
                <img src="../assets/images/mutarelogo.png" alt="MCC" class="w-10 h-10">
                <div>
                    <span class="text-sm font-bold text-white">MCC ICT</span>
                    <p class="text-[10px] text-[#00ff88] uppercase tracking-wider">Helpdesk</p>
                </div>
            </div>
            
            <!-- Navigation -->
            <nav class="flex-1 space-y-1">
                <a href="dashboard.php" class="sidebar-item">
                    <i data-lucide="layout-dashboard" class="w-4 h-4"></i>
                    Dashboard
                </a>
                <a href="submit_ticket.php" class="sidebar-item active">
                    <i data-lucide="plus-circle" class="w-4 h-4"></i>
                    New Ticket
                </a>
                <a href="my_requests.php" class="sidebar-item">
                    <i data-lucide="ticket" class="w-4 h-4"></i>
                    My Tickets
                </a>
            </nav>
            
            <!-- User Info -->
            <div class="pt-4 border-t border-[#1a1a2e]">
                <div class="flex items-center gap-3 mb-3">
                    <div class="w-9 h-9 rounded-lg bg-[#00ff88]/20 border border-[#00ff88]/30 flex items-center justify-center text-[#00ff88] font-bold text-sm">
                        <?php echo strtoupper(substr($_SESSION['user_name'], 0, 1)); ?>
                    </div>
                    <div>
                        <p class="text-xs font-medium text-white"><?php echo htmlspecialchars($_SESSION['user_name']); ?></p>
                        <p class="text-[10px] text-[#666]"><?php echo htmlspecialchars($_SESSION['user_department']); ?></p>
                    </div>
                </div>
                <a href="../auth/logout.php" class="flex items-center gap-2 text-[#666] hover:text-[#ef4444] text-xs transition-colors">
                    <i data-lucide="log-out" class="w-4 h-4"></i>
                    Logout
                </a>
            </div>
        </aside>
        
        <!-- Main Content -->
        <main class="flex-1 p-6">
            <!-- Header -->
            <header class="flex items-center justify-between mb-6">
                <div>
                    <h1 class="text-xl font-bold text-white glow-text">Submit New Ticket</h1>
                    <p class="text-xs text-[#666] mt-0.5">Create a support request</p>
                </div>
                <div class="flex items-center gap-2 text-xs text-[#666]">
                    <div class="pulse-indicator"></div>
                    <span>System Online</span>
                </div>
            </header>
            
            <?php if ($success): ?>
                <div class="alert-success mb-6 flex items-center gap-3">
                    <i data-lucide="check-circle" class="w-5 h-5"></i>
                    <div>
                        <p class="font-semibold"><?php echo $success; ?></p>
                        <div class="flex gap-3 mt-3">
                            <a href="my_requests.php" class="cyber-btn">
                                <i data-lucide="list" class="w-4 h-4"></i>
                                View My Tickets
                            </a>
                            <a href="submit_ticket.php" class="cyber-btn cyber-btn-secondary">
                                <i data-lucide="plus" class="w-4 h-4"></i>
                                Submit Another
                            </a>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
            
            <?php if ($error): ?>
                <div class="alert-error mb-6 flex items-center gap-3">
                    <i data-lucide="alert-circle" class="w-5 h-5"></i>
                    <span><?php echo $error; ?></span>
                </div>
            <?php endif; ?>
            
            <?php if (empty($success)): ?>
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <!-- Form -->
                <div class="lg:col-span-2">
                    <div class="cyber-card p-6">
                        <h2 class="text-base font-semibold text-white mb-6">Ticket Details</h2>
                        
                        <form method="POST" action="">
                            <div class="mb-4">
                                <label class="cyber-label">Ticket Title <span>*</span></label>
                                <div class="relative">
                                    <i data-lucide="type" class="input-icon w-4 h-4"></i>
                                    <input type="text" name="title" required 
                                        class="cyber-input"
                                        placeholder="Brief description of your issue"
                                        value="<?php echo isset($_POST['title']) ? htmlspecialchars($_POST['title']) : ''; ?>">
                                </div>
                            </div>
                            
                            <div class="grid grid-cols-2 gap-4 mb-4">
                                <div>
                                    <label class="cyber-label">Category <span>*</span></label>
                                    <div class="relative">
                                        <i data-lucide="folder" class="input-icon w-4 h-4"></i>
                                        <select name="category" required class="cyber-select" onchange="updatePriority()">
                                            <option value="">Select Category</option>
                                            <option value="network" <?php echo (isset($_POST['category']) && $_POST['category'] == 'network') ? 'selected' : ''; ?>>Network Issues</option>
                                            <option value="hardware" <?php echo (isset($_POST['category']) && $_POST['category'] == 'hardware') ? 'selected' : ''; ?>>Hardware Problems</option>
                                            <option value="software" <?php echo (isset($_POST['category']) && $_POST['category'] == 'software') ? 'selected' : ''; ?>>Software Issues</option>
                                            <option value="login" <?php echo (isset($_POST['category']) && $_POST['category'] == 'login') ? 'selected' : ''; ?>>Login/Account Issues</option>
                                        </select>
                                        <i data-lucide="chevron-down" class="select-icon w-4 h-4"></i>
                                    </div>
                                </div>
                                
                                <div>
                                    <label class="cyber-label">Priority</label>
                                    <div class="relative">
                                        <i data-lucide="flag" class="input-icon w-4 h-4"></i>
                                        <select name="priority" id="priority" class="cyber-select">
                                            <option value="">Auto-assign</option>
                                            <option value="high" <?php echo (isset($_POST['priority']) && $_POST['priority'] == 'high') ? 'selected' : ''; ?>>High - Server/Network Outage</option>
                                            <option value="medium" <?php echo (isset($_POST['priority']) && $_POST['priority'] == 'medium') ? 'selected' : ''; ?>>Medium - Account Issues</option>
                                            <option value="low" <?php echo (isset($_POST['priority']) && $_POST['priority'] == 'low') ? 'selected' : ''; ?>>Low - Minor Issues</option>
                                        </select>
                                        <i data-lucide="chevron-down" class="select-icon w-4 h-4"></i>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="mb-4">
                                <label class="cyber-label">Detailed Description <span>*</span></label>
                                <textarea name="description" required 
                                    class="cyber-textarea"
                                    placeholder="Please provide as much detail as possible about your issue. Include error messages, steps to reproduce, and what you've already tried."
                                    onkeyup="searchKnowledgeBase(this.value)"><?php echo isset($_POST['description']) ? htmlspecialchars($_POST['description']) : ''; ?></textarea>
                                <p class="text-xs text-[#444] mt-2">Tip: Include any error messages, steps to reproduce, and what you've already tried.</p>
                            </div>
                            
                            <div class="flex gap-3">
                                <button type="submit" class="cyber-btn">
                                    <i data-lucide="send" class="w-4 h-4"></i>
                                    Submit Ticket
                                </button>
                                <button type="reset" class="cyber-btn cyber-btn-secondary">
                                    <i data-lucide="x" class="w-4 h-4"></i>
                                    Clear
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
                
                <!-- Sidebar Info -->
                <div class="space-y-4">
                    <div class="info-box">
                        <h4><i data-lucide="user" class="w-3 h-3 inline mr-1"></i> Your Information</h4>
                        <p class="mb-2"><strong>Name:</strong> <?php echo htmlspecialchars($_SESSION['user_name']); ?></p>
                        <p class="mb-2"><strong>Department:</strong> <?php echo htmlspecialchars($_SESSION['user_department']); ?></p>
                        <p><strong>Email:</strong> <?php echo htmlspecialchars($_SESSION['user_email']); ?></p>
                    </div>
                    
                    <div class="info-box">
                        <h4><i data-lucide="lightbulb" class="w-3 h-3 inline mr-1"></i> Quick Tips</h4>
                        <ul>
                            <li>Be specific about your issue</li>
                            <li>Include any error messages</li>
                            <li>Mention what you've tried</li>
                            <li>High priority for system outages</li>
                        </ul>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </main>
    </div>
    
    <script>
        lucide.createIcons();
        
        function updatePriority() {
            const category = document.querySelector('select[name="category"]').value;
            const priority = document.getElementById('priority');
            
            if (category && !priority.value) {
                switch (category) {
                    case 'network': priority.value = 'high'; break;
                    case 'login': priority.value = 'medium'; break;
                    case 'hardware': priority.value = 'low'; break;
                    default: priority.value = 'medium';
                }
            }
        }
    </script>
</body>
</html>
