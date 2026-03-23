<?php
/**
 * Main Entry Point
 * Smart ICT Helpdesk System - Mutare City Council
 */

session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: auth/login.php');
    exit();
}

// Redirect based on user role
switch ($_SESSION['user_role']) {
    case 'admin':
        header('Location: admin/dashboard.php');
        break;
    case 'technician':
        header('Location: technician/dashboard.php');
        break;
    case 'user':
        header('Location: user/dashboard.php');
        break;
    default:
        header('Location: auth/login.php');
        break;
}
exit();
?>
