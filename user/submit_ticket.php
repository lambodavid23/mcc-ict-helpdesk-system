<?php
require_once '../config/auth_helper.php';
require_once '../config/database.php';
require_once '../config/NotificationService.php';
require_once '../config/AutoAssignmentService.php';

requireRole('user');

$database = new Database();
$conn = $database->getConnection();
$notifications = new NotificationService();
$auto_assign = new AutoAssignmentService();

$success = '';
$error = '';

$is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

function detectCategory($text) {
    $text = strtolower($text);
    $keywords = [
        'network'   => ['wifi', 'wi-fi', 'internet', 'network', 'connection', 'ethernet', 'lan', 'vpn', 'router', 'modem', 'connect', 'cable', 'wireless'],
        'hardware'  => ['printer', 'keyboard', 'mouse', 'monitor', 'screen', 'battery', 'hard drive', 'hard disk', 'ram', 'boot', 'crash', 'slow', 'overheat', 'power', 'dead phone', 'laptop', 'desktop', 'speaker', 'camera', 'charger', 'port', 'usb'],
        'software'  => ['software', 'install', 'application', 'program', 'excel', 'word', 'outlook', 'emails', 'e-mail', 'email', 'update', 'windows', 'office', 'license', 'virus', 'error', 'freeze', 'freezing', 'download', 'files', 'document', 'system', 'app'],
        'login'     => ['login', 'log in', 'password', 'account locked', 'username', 'logon', 'can\'t login', 'cannot login', 'forgot', 'reset password', 'locked out', 'access'],
    ];
    $best = 'software';
    $bestScore = 0;
    foreach ($keywords as $cat => $words) {
        $score = 0;
        foreach ($words as $kw) {
            if (strpos($text, $kw) !== false) $score++;
        }
        if ($score > $bestScore) { $best = $cat; $bestScore = $score; }
    }
    return $best;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $title = trim($_POST['title']);
    $description = trim($_POST['description']);
    $category = detectCategory($title . ' ' . $description);
    $priority = $_POST['priority'];
    
    if (empty($title)) {
        $error = 'Ticket title is required';
    } elseif (empty($description)) {
        $error = 'Ticket description is required';
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
            $ticket_id = $database->getLastId();
            
            if (!empty($_FILES['attachment']['name'])) {
                $file = $_FILES['attachment'];
                $allowed = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx', 'txt', 'zip'];
                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                
                if (in_array($ext, $allowed) && $file['size'] <= 5 * 1024 * 1024) {
                    $upload_dir = '../uploads/tickets/' . $ticket_id . '/';
                    if (!is_dir($upload_dir)) {
                        mkdir($upload_dir, 0755, true);
                    }
                    $filename = time() . '_' . basename($file['name']);
                    $filepath = $upload_dir . $filename;
                    
                    if (move_uploaded_file($file['tmp_name'], $filepath)) {
                        $filename_esc = $conn->real_escape_string($file['name']);
                        $filepath_esc = $conn->real_escape_string($filepath);
                        $filesize = $file['size'];
                        $mime = $conn->real_escape_string($file['type']);
                        
                        $stmt = $conn->prepare("INSERT INTO ticket_attachments (ticket_id, uploaded_by, filename, filepath, filesize, mime_type) VALUES (?, ?, ?, ?, ?, ?)");
                        $stmt->bind_param("iissis", $ticket_id, $created_by, $filename_esc, $filepath_esc, $filesize, $mime);
                        $stmt->execute();
                        $stmt->close();
                    }
                }
            }
            
            $success = 'Ticket submitted successfully! Your ticket ID is #' . $ticket_id . '. You will receive an email notification when a technician is assigned.';
            
            if ($is_ajax) {
                ignore_user_abort(true);
                set_time_limit(0);
                if (ob_get_level()) ob_clean();
                header('Content-Type: application/json');
                header('Connection: close');
                $json = json_encode(['status' => 'success', 'message' => $success, 'ticket_id' => $ticket_id]);
                header('Content-Length: ' . strlen($json));
                echo $json;
                ob_flush();
                flush();
                
                $auto_assign->autoAssignTicket($ticket_id);
                $notifications->notifyTicketCreated($ticket_id, $created_by);
                logActivity('SUBMIT_TICKET', "Submitted ticket: $title");
                exit;
            }
            
            $auto_assign->autoAssignTicket($ticket_id);
            $notifications->notifyTicketCreated($ticket_id, $created_by);
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
        .toast-container {
            position: fixed;
            top: 1rem;
            right: 1rem;
            z-index: 9999;
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }
        .toast {
            padding: 1rem 1.25rem;
            border-radius: 10px;
            font-size: 0.85rem;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            min-width: 320px;
            max-width: 420px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.5);
            transform: translateX(120%);
            opacity: 0;
            transition: transform 0.4s cubic-bezier(0.16, 1, 0.3, 1), opacity 0.4s ease;
            border: 1px solid transparent;
        }
        .toast.show {
            transform: translateX(0);
            opacity: 1;
        }
        .toast-success {
            background: rgba(0, 255, 136, 0.12);
            border-color: rgba(0, 255, 136, 0.35);
            color: #00ff88;
        }
        .toast-error {
            background: rgba(239, 68, 68, 0.12);
            border-color: rgba(239, 68, 68, 0.35);
            color: #ef4444;
        }
        .toast-info {
            background: rgba(59, 130, 246, 0.12);
            border-color: rgba(59, 130, 246, 0.35);
            color: #60a5fa;
        }
        .toast-close {
            margin-left: auto;
            cursor: pointer;
            opacity: 0.5;
            transition: opacity 0.2s;
            background: none;
            border: none;
            color: inherit;
            padding: 0;
            display: flex;
        }
        .toast-close:hover {
            opacity: 1;
        }
        .toast-icon {
            flex-shrink: 0;
        }
    </style>
</head>
<body class="min-h-screen grid-bg">
    <div id="toastContainer" class="toast-container"></div>
    
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
                    <p class="text-[10px] text-[#00ff88] uppercase tracking-wider">User Panel</p>
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
            
            <?php if ($error): ?>
                <div class="alert-error mb-6 flex items-center gap-3">
                    <i data-lucide="alert-circle" class="w-5 h-5"></i>
                    <span><?php echo $error; ?></span>
                </div>
            <?php endif; ?>
            
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <!-- Form -->
                <div class="lg:col-span-2">
                    <div class="cyber-card p-6">
                        <h2 class="text-base font-semibold text-white mb-6">Ticket Details</h2>
                        
                        <form method="POST" action="" enctype="multipart/form-data" id="ticketForm">
                            <div class="mb-4">
                                <label class="cyber-label">Problem / Issue Title <span>*</span></label>
                                <div class="relative">
                                    <i data-lucide="type" class="input-icon w-4 h-4"></i>
                                    <input type="text" name="title" required 
                                        class="cyber-input"
                                        placeholder="Brief description of your issue"
                                        value="<?php echo isset($_POST['title']) ? htmlspecialchars($_POST['title']) : ''; ?>">
                                </div>
                            </div>
                            
                            <div class="grid grid-cols-2 gap-4 mb-4">
                                <div class="col-span-2 sm:col-span-1">
                                    <label class="cyber-label">Priority</label>
                                    <div class="relative">
                                        <i data-lucide="flag" class="input-icon w-4 h-4"></i>
                                        <select name="priority" id="priority" class="cyber-select">
                                            <option value="">Auto-assign</option>
                                            <option value="high" <?php echo (isset($_POST['priority']) && $_POST['priority'] == 'high') ? 'selected' : ''; ?>>High - Very Urgent</option>
                                            <option value="medium" <?php echo (isset($_POST['priority']) && $_POST['priority'] == 'medium') ? 'selected' : ''; ?>>Medium - Needs Attention</option>
                                            <option value="low" <?php echo (isset($_POST['priority']) && $_POST['priority'] == 'low') ? 'selected' : ''; ?>>Low - Minor Issues</option>
                                        </select>
                                        <i data-lucide="chevron-down" class="select-icon w-4 h-4"></i>
                                    </div>
                                    <p class="text-xs text-[#444] mt-1">Leave on "Auto-assign" and the system will set the urgency for you.</p>
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
                            
                            <div class="mb-4">
<label class="cyber-label">Upload a Picture of the Error (Optional)</label>
<input type="file" name="attachment" accept=".jpg,.jpeg,.png,.gif,.pdf,.doc,.docx,.txt,.zip" class="text-sm text-[#ccc] file:mr-3 file:py-2 file:px-4 file:rounded-lg file:border-0 file:bg-[#00ff88]/20 file:text-[#00ff88] file:font-semibold file:cursor-pointer hover:file:bg-[#00ff88]/30">
<p class="text-xs text-[#444] mt-1">Max 5MB. A screenshot or photo of the error message is most helpful.</p>
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
        </main>
    </div>
    
    <script>
        lucide.createIcons();
        
        function showToast(message, type) {
            const container = document.getElementById('toastContainer');
            const icons = { success: 'check-circle', error: 'alert-circle', info: 'info' };
            
            const toast = document.createElement('div');
            toast.className = 'toast toast-' + type;
            toast.innerHTML = '<i data-lucide="' + (icons[type] || 'info') + '" class="w-5 h-5 toast-icon"></i>'
                + '<span>' + message + '</span>'
                + '<button class="toast-close" onclick="this.parentElement.remove()"><i data-lucide="x" class="w-4 h-4"></i></button>';
            
            container.appendChild(toast);
            lucide.createIcons({ root: toast });
            
            requestAnimationFrame(() => {
                toast.classList.add('show');
            });
            
            setTimeout(() => {
                toast.classList.remove('show');
                setTimeout(() => toast.remove(), 400);
            }, 5000);
        }
        
        document.getElementById('ticketForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const form = this;
            const btn = form.querySelector('button[type="submit"]');
            const originalHTML = btn.innerHTML;
            
            btn.innerHTML = '<svg class="animate-spin h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path></svg> Submitting...';
            btn.disabled = true;
            
            const formData = new FormData(form);
            
            fetch('', {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: formData
            })
            .then(function(res) { return res.json(); })
            .then(function(data) {
                if (data.status === 'success') {
                    showToast(data.message, 'success');
                    form.reset();
                    lucide.createIcons();
                } else {
                    showToast(data.message, 'error');
                }
            })
            .catch(function() {
                showToast('Network error. Please try again.', 'error');
            })
            .finally(function() {
                btn.innerHTML = originalHTML;
                btn.disabled = false;
            });
        });
    </script>
</body>
</html>
