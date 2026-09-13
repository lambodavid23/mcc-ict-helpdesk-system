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
        $user_type = isset($_SESSION['user_role']) ? $_SESSION['user_role'] : 'user';
        $ip_address = $_SERVER['REMOTE_ADDR'];
        $user_agent = $_SERVER['HTTP_USER_AGENT'];
        $description = $conn->real_escape_string($description);
        
        $log_query = "INSERT INTO system_logs (user_id, user_type, action, description, ip_address, user_agent) 
                     VALUES ('$user_id', '$user_type', '$action', '$description', '$ip_address', '$user_agent')";
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
 * Get Lucide icon HTML
 */
function getLucideIcon($iconName, $size = 16, $class = '') {
    $icons = [
        'bar-chart-3' => '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide-icon ' . $class . '"><path d="M3 3v18h18"/><path d="m19 9-5 5-4-4-3 3"/></svg>',
        'users' => '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide-icon ' . $class . '"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="m22 21-3-3 3-3"/><path d="M16 8l3 3"/></svg>',
        'wrench' => '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide-icon ' . $class . '"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>',
        'ticket' => '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide-icon ' . $class . '"><path d="M2 9a3 3 0 0 1 0 6v2a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-2a3 3 0 0 1 0-6V7a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2Z"/><path d="M13 5v7"/><path d="M13 12v7"/></svg>',
        'trending-up' => '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide-icon ' . $class . '"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg>',
        'book-open' => '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide-icon ' . $class . '"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg>',
        'plus' => '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide-icon ' . $class . '"><path d="M5 12h14"/><path d="M12 5v14"/></svg>',
        'clipboard-list' => '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide-icon ' . $class . '"><rect x="8" y="2" width="8" height="4" rx="1" ry="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="M12 11h4"/><path d="M12 16h4"/><path d="M8 11h.01"/><path d="M8 16h.01"/></svg>',
        'edit' => '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide-icon ' . $class . '"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>',
        'menu' => '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide-icon ' . $class . '"><line x1="4" x2="20" y1="12" y2="12"/><line x1="4" x2="20" y1="6" y2="6"/><line x1="4" x2="20" y1="18" y2="18"/></svg>',
        'log-out' => '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide-icon ' . $class . '"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" x2="9" y1="12" y2="12"/></svg>',
        'lightbulb' => '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide-icon ' . $class . '"><path d="M15 14c.2-1 .7-1.8 1.5-2.5 1-.9 1.5-2.2 1.5-3.5A6 6 0 0 0 6 8c0 1 .2 2.1.7 3"/><path d="M9 18h6"/><path d="m10 22 4-6"/><path d="m14 22-4-6"/></svg>',
        'zap' => '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide-icon ' . $class . '"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>'
    ];
    
    return isset($icons[$iconName]) ? $icons[$iconName] : '';
}

/**
 * Get navigation menu based on user role
 */
function getNavigationMenu($role) {
    $menus = [
        'admin' => [
            ['title' => 'Dashboard', 'url' => 'admin/dashboard.php', 'icon' => 'bar-chart-3'],
            ['title' => 'Manage Users', 'url' => 'admin/manage_users.php', 'icon' => 'users'],
            ['title' => 'Manage Technicians', 'url' => 'admin/manage_technicians.php', 'icon' => 'wrench'],
            ['title' => 'All Tickets', 'url' => 'admin/all_tickets.php', 'icon' => 'ticket'],
            ['title' => 'Reports', 'url' => 'admin/reports.php', 'icon' => 'trending-up'],
            ['title' => 'Knowledge Base', 'url' => 'system/knowledge_base.php', 'icon' => 'book-open']
        ],
        'technician' => [
            ['title' => 'Dashboard', 'url' => 'technician/dashboard.php', 'icon' => 'bar-chart-3'],
            ['title' => 'My Tickets', 'url' => 'technician/my_tickets.php', 'icon' => 'ticket'],
            ['title' => 'Update Ticket', 'url' => 'technician/update_ticket.php', 'icon' => 'edit'],
            ['title' => 'Knowledge Base', 'url' => 'system/knowledge_base.php', 'icon' => 'book-open']
        ],
        'user' => [
            ['title' => 'Submit Ticket', 'url' => 'user/submit_ticket.php', 'icon' => 'plus'],
            ['title' => 'My Requests', 'url' => 'user/my_requests.php', 'icon' => 'clipboard-list'],
            ['title' => 'Knowledge Base', 'url' => 'system/knowledge_base.php', 'icon' => 'book-open']
        ]
    ];
    
    return isset($menus[$role]) ? $menus[$role] : [];
}
?>
