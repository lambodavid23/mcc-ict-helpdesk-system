<?php
/**
 * Logout Script
 * Smart ICT Helpdesk System - Mutare City Council
 */

session_start();

// Clear remember-me token
if (isset($_SESSION['user_id']) && isset($_SESSION['user_role'])) {
    require_once '../config/auth_helper.php';
    destroyRememberToken($_SESSION['user_role'], $_SESSION['user_id']);
}

// Log logout activity if user is logged in
if (isset($_SESSION['user_id'])) {
    require_once '../config/auth_helper.php';
    require_once '../config/database.php';
    $database = new Database();
    $conn = $database->getConnection();
    
    $user_id = $_SESSION['user_id'];

    $log_stmt = $conn->prepare(
        "INSERT INTO system_logs
            (user_id, action, description, ip_address, user_agent)
         VALUES (?, 'LOGOUT', 'User logged out from system', ?, ?)"
    );
    $log_stmt->bind_param('iss', $user_id, clientIp(), clientUserAgent());
    $log_stmt->execute();
    $log_stmt->close();
}

// Destroy all session data
session_destroy();

// Clear session cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Redirect to login page
header('Location: login.php');
exit();
?>
