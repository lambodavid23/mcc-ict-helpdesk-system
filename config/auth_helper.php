<?php
/**
 * Authentication Helper Functions
 * Smart ICT Helpdesk System - Mutare City Council
 */

// Start session if not already started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

/**
 * Check if user is logged in
 */
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

/**
 * Check if user has specific role
 */
function hasRole($role) {
    return isset($_SESSION['user_role']) && $_SESSION['user_role'] === $role;
}

/**
 * Require user to be logged in
 */
function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: ../auth/login.php');
        exit();
    }
}

/**
 * Require specific role to access page
 */
function requireRole($role) {
    requireLogin();
    if (!hasRole($role)) {
        $_SESSION['error'] = 'Access denied. You do not have permission to access this page.';
        header('Location: ../index.php');
        exit();
    }
}

/**
 * Get current user information
 */
function getCurrentUser() {
    if (isLoggedIn()) {
        return [
            'id' => $_SESSION['user_id'],
            'name' => $_SESSION['user_name'],
            'email' => $_SESSION['user_email'],
            'role' => $_SESSION['user_role'],
            'department' => $_SESSION['user_department']
        ];
    }
    return null;
}

/**
 * Log user activity
 */
function logActivity($action, $description = '') {
    if (isLoggedIn()) {
        require_once 'database.php';
        $database = new Database();
        $conn = $database->getConnection();
        
        $user_id = $_SESSION['user_id'];
        $ip_address = $_SERVER['REMOTE_ADDR'];
        $user_agent = $_SERVER['HTTP_USER_AGENT'];
        $description = $conn->real_escape_string($description);
        
        $log_query = "INSERT INTO system_logs (user_id, action, description, ip_address, user_agent) 
                     VALUES ('$user_id', '$action', '$description', '$ip_address', '$user_agent')";
        $conn->query($log_query);
    }
}

/**
 * Display success message
 */
function displaySuccess() {
    if (isset($_SESSION['success'])) {
        $message = $_SESSION['success'];
        unset($_SESSION['success']);
        return "<div class='alert alert-success fade-in'>$message</div>";
    }
    return '';
}

/**
 * Display error message
 */
function displayError() {
    if (isset($_SESSION['error'])) {
        $message = $_SESSION['error'];
        unset($_SESSION['error']);
        return "<div class='alert alert-error fade-in'>$message</div>";
    }
    return '';
}

/**
 * Set success message
 */
function setSuccess($message) {
    $_SESSION['success'] = $message;
}

/**
 * Set error message
 */
function setError($message) {
    $_SESSION['error'] = $message;
}

/**
 * Get user role display name
 */
function getRoleDisplayName($role) {
    $roles = [
        'admin' => 'Administrator',
        'technician' => 'Technician',
        'user' => 'User'
    ];
    return isset($roles[$role]) ? $roles[$role] : ucfirst($role);
}

/**
 * Get status badge HTML
 */
function getStatusBadge($status) {
    $badges = [
        'open' => '<span class="badge badge-open">Open</span>',
        'in_progress' => '<span class="badge badge-in-progress">In Progress</span>',
        'resolved' => '<span class="badge badge-resolved">Resolved</span>',
        'closed' => '<span class="badge badge-resolved">Closed</span>',
        'available' => '<span class="badge badge-resolved">Available</span>',
        'busy' => '<span class="badge badge-in-progress">Busy</span>',
        'offline' => '<span class="badge badge-open">Offline</span>'
    ];
    return isset($badges[$status]) ? $badges[$status] : '<span class="badge badge-open">' . ucfirst($status) . '</span>';
}

/**
 * Get priority badge HTML
 */
function getPriorityBadge($priority) {
    $badges = [
        'high' => '<span class="badge badge-high">High</span>',
        'medium' => '<span class="badge badge-medium">Medium</span>',
        'low' => '<span class="badge badge-low">Low</span>'
    ];
    return isset($badges[$priority]) ? $badges[$priority] : '<span class="badge badge-medium">' . ucfirst($priority) . '</span>';
}

/**
 * Format date for display
 */
function formatDate($date) {
    if (empty($date)) return 'N/A';
    $timestamp = strtotime($date);
    return date('M j, Y H:i', $timestamp);
}

/**
 * Calculate time ago
 */
function timeAgo($datetime) {
    $time = strtotime($datetime);
    $now = time();
    $diff = $now - $time;
    
    if ($diff < 60) {
        return 'Just now';
    } elseif ($diff < 3600) {
        return floor($diff / 60) . ' minutes ago';
    } elseif ($diff < 86400) {
        return floor($diff / 3600) . ' hours ago';
    } elseif ($diff < 604800) {
        return floor($diff / 86400) . ' days ago';
    } else {
        return formatDate($datetime);
    }
}

/**
 * Sanitize input
 */
function sanitize($input) {
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

/**
 * Generate CSRF token
 */
function generateCSRFToken() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Validate CSRF token
 */
function validateCSRFToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Pagination helper
 */
function paginate($total_items, $items_per_page = 10, $current_page = 1) {
    $total_pages = ceil($total_items / $items_per_page);
    $offset = ($current_page - 1) * $items_per_page;
    
    return [
        'total_items' => $total_items,
        'items_per_page' => $items_per_page,
        'current_page' => $current_page,
        'total_pages' => $total_pages,
        'offset' => $offset,
        'has_next' => $current_page < $total_pages,
        'has_prev' => $current_page > 1
    ];
}

/**
 * Get navigation menu based on user role
 */
function getNavigationMenu($role) {
    $menus = [
        'admin' => [
            ['title' => 'Dashboard', 'url' => 'admin/dashboard.php', 'icon' => '📊'],
            ['title' => 'Manage Users', 'url' => 'admin/manage_users.php', 'icon' => '👥'],
            ['title' => 'Manage Technicians', 'url' => 'admin/manage_technicians.php', 'icon' => '🔧'],
            ['title' => 'All Tickets', 'url' => 'admin/all_tickets.php', 'icon' => '🎫'],
            ['title' => 'Reports', 'url' => 'admin/reports.php', 'icon' => '📈'],
            ['title' => 'Knowledge Base', 'url' => 'system/knowledge_base.php', 'icon' => '📚']
        ],
        'technician' => [
            ['title' => 'Dashboard', 'url' => 'technician/dashboard.php', 'icon' => '📊'],
            ['title' => 'My Tickets', 'url' => 'technician/my_tickets.php', 'icon' => '🎫'],
            ['title' => 'Update Ticket', 'url' => 'technician/update_ticket.php', 'icon' => '✏️'],
            ['title' => 'Knowledge Base', 'url' => 'system/knowledge_base.php', 'icon' => '📚']
        ],
        'user' => [
            ['title' => 'Submit Ticket', 'url' => 'user/submit_ticket.php', 'icon' => '➕'],
            ['title' => 'My Requests', 'url' => 'user/my_requests.php', 'icon' => '📋'],
            ['title' => 'Knowledge Base', 'url' => 'system/knowledge_base.php', 'icon' => '📚']
        ]
    ];
    
    return isset($menus[$role]) ? $menus[$role] : [];
}
?>
