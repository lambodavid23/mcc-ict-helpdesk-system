<?php
/**
 * Login Page
 * Smart ICT Helpdesk System - Mutare City Council
 */

session_start();
require_once '../config/database.php';

// Check if user is already logged in
if (isset($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit();
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    
    // Validation
    if (empty($email)) {
        $errors[] = 'Email is required';
    }
    if (empty($password)) {
        $errors[] = 'Password is required';
    }
    
    if (empty($errors)) {
        $database = new Database();
        $conn = $database->getConnection();
        
        // Check user credentials
        $email = $conn->real_escape_string($email);
        $query = "SELECT id, name, email, password, role, department FROM users WHERE email = '$email' LIMIT 1";
        $result = $conn->query($query);
        
        if ($result && $result->num_rows == 1) {
            $user = $result->fetch_assoc();
            
            // Verify password
            if (password_verify($password, $user['password'])) {
                // Set session variables
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_name'] = $user['name'];
                $_SESSION['user_email'] = $user['email'];
                $_SESSION['user_role'] = $user['role'];
                $_SESSION['user_department'] = $user['department'];
                
                // Log login activity
                $ip_address = $_SERVER['REMOTE_ADDR'];
                $user_agent = $_SERVER['HTTP_USER_AGENT'];
                $log_query = "INSERT INTO system_logs (user_id, action, description, ip_address, user_agent) 
                             VALUES ('{$user['id']}', 'LOGIN', 'User logged into system', '$ip_address', '$user_agent')";
                $conn->query($log_query);
                
                // Redirect based on role
                switch ($user['role']) {
                    case 'admin':
                        header('Location: ../admin/dashboard.php');
                        break;
                    case 'technician':
                        header('Location: ../technician/dashboard.php');
                        break;
                    case 'user':
                        header('Location: ../user/submit_ticket.php');
                        break;
                    default:
                        header('Location: ../index.php');
                }
                exit();
            } else {
                $errors[] = 'Invalid email or password';
            }
        } else {
            $errors[] = 'Invalid email or password';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - MCC ICT Helpdesk</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body class="flex items-center justify-center min-h-screen">
    <div class="w-full max-w-md p-6">
        <div class="card">
            <!-- Logo Section -->
            <div class="text-center mb-8">
                <div class="logo justify-center mb-4">
                    <img src="../assets/images/mutarelogo.png" alt="Mutare City Council" style="width: 60px; height: 60px;">
                    <div class="logo-text">MCC Helpdesk</div>
                </div>
                <h1 class="text-2xl font-bold text-gray-100">ICT Helpdesk System</h1>
                <p class="text-gray-400 mt-2">Sign in to your account</p>
            </div>

            <!-- Error Messages -->
            <?php if (!empty($errors)): ?>
                <div class="alert alert-error mb-6">
                    <?php foreach ($errors as $error): ?>
                        <div><?php echo htmlspecialchars($error); ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <!-- Login Form -->
            <form method="POST" action="" id="loginForm" onsubmit="return validateForm('loginForm')">
                <div class="form-group">
                    <label for="email" class="form-label">Email Address</label>
                    <input type="email" id="email" name="email" class="form-input" 
                           placeholder="Enter your email" required>
                </div>

                <div class="form-group">
                    <label for="password" class="form-label">Password</label>
                    <input type="password" id="password" name="password" class="form-input" 
                           placeholder="Enter your password" required>
                </div>

                <button type="submit" class="btn btn-primary w-full">
                    Sign In
                </button>
            </form>

            <!-- Demo Credentials -->
            <div class="mt-8 p-4 bg-gray-800 rounded-lg border border-gray-700">
                <h3 class="text-sm font-semibold text-gray-300 mb-3">Demo Credentials:</h3>
                <div class="space-y-2 text-xs">
                    <div class="text-gray-400">
                        <strong>Admin:</strong> admin@mcc.co.zw / password
                    </div>
                    <div class="text-gray-400">
                        <strong>Technician:</strong> john.tech@mcc.co.zw / password
                    </div>
                    <div class="text-gray-400">
                        <strong>User:</strong> user@mcc.co.zw / password
                    </div>
                </div>
            </div>

            <!-- Footer -->
            <div class="text-center mt-6 text-gray-500 text-sm">
                <p>&copy; 2024 Mutare City Council ICT Department</p>
            </div>
        </div>
    </div>

    <script src="../assets/js/script.js"></script>
</body>
</html>
