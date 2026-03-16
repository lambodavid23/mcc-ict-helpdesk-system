<?php
/**
 * Registration Page
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
$success = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    $department = trim($_POST['department']);
    $role = 'user'; // Default role for new registrations
    
    // Validation
    if (empty($name)) {
        $errors[] = 'Full name is required';
    }
    if (empty($email)) {
        $errors[] = 'Email is required';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Invalid email format';
    }
    if (empty($password)) {
        $errors[] = 'Password is required';
    } elseif (strlen($password) < 6) {
        $errors[] = 'Password must be at least 6 characters long';
    }
    if ($password !== $confirm_password) {
        $errors[] = 'Passwords do not match';
    }
    if (empty($department)) {
        $errors[] = 'Department is required';
    }
    
    if (empty($errors)) {
        $database = new Database();
        $conn = $database->getConnection();
        
        // Check if email already exists
        $email = $conn->real_escape_string($email);
        $check_query = "SELECT id FROM users WHERE email = '$email' LIMIT 1";
        $check_result = $conn->query($check_query);
        
        if ($check_result && $check_result->num_rows > 0) {
            $errors[] = 'Email address already exists';
        } else {
            // Insert new user
            $name = $conn->real_escape_string($name);
            $department = $conn->real_escape_string($department);
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            
            $insert_query = "INSERT INTO users (name, email, password, role, department) 
                           VALUES ('$name', '$email', '$hashed_password', '$role', '$department')";
            
            if ($conn->query($insert_query)) {
                $success = 'Registration successful! You can now login.';
                // Clear form fields
                $_POST = [];
            } else {
                $errors[] = 'Registration failed. Please try again.';
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - MCC ICT Helpdesk</title>
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
                <h1 class="text-2xl font-bold text-gray-100">Create Account</h1>
                <p class="text-gray-400 mt-2">Register for ICT Helpdesk access</p>
            </div>

            <!-- Success Message -->
            <?php if (!empty($success)): ?>
                <div class="alert alert-success mb-6">
                    <?php echo htmlspecialchars($success); ?>
                    <div class="mt-3">
                        <a href="login.php" class="btn btn-primary btn-sm">Go to Login</a>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Error Messages -->
            <?php if (!empty($errors)): ?>
                <div class="alert alert-error mb-6">
                    <?php foreach ($errors as $error): ?>
                        <div><?php echo htmlspecialchars($error); ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if (empty($success)): ?>
            <!-- Registration Form -->
            <form method="POST" action="" id="registerForm" onsubmit="return validateForm('registerForm')">
                <div class="form-group">
                    <label for="name" class="form-label">Full Name</label>
                    <input type="text" id="name" name="name" class="form-input" 
                           placeholder="Enter your full name" required
                           value="<?php echo isset($_POST['name']) ? htmlspecialchars($_POST['name']) : ''; ?>">
                </div>

                <div class="form-group">
                    <label for="email" class="form-label">Email Address</label>
                    <input type="email" id="email" name="email" class="form-input" 
                           placeholder="Enter your email" required
                           value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
                </div>

                <div class="form-group">
                    <label for="department" class="form-label">Department</label>
                    <select id="department" name="department" class="form-select" required>
                        <option value="">Select Department</option>
                        <option value="Finance" <?php echo (isset($_POST['department']) && $_POST['department'] == 'Finance') ? 'selected' : ''; ?>>Finance</option>
                        <option value="HR" <?php echo (isset($_POST['department']) && $_POST['department'] == 'HR') ? 'selected' : ''; ?>>Human Resources</option>
                        <option value="Administration" <?php echo (isset($_POST['department']) && $_POST['department'] == 'Administration') ? 'selected' : ''; ?>>Administration</option>
                        <option value="Engineering" <?php echo (isset($_POST['department']) && $_POST['department'] == 'Engineering') ? 'selected' : ''; ?>>Engineering</option>
                        <option value="Health" <?php echo (isset($_POST['department']) && $_POST['department'] == 'Health') ? 'selected' : ''; ?>>Health Services</option>
                        <option value="Education" <?php echo (isset($_POST['department']) && $_POST['department'] == 'Education') ? 'selected' : ''; ?>>Education</option>
                        <option value="Housing" <?php echo (isset($_POST['department']) && $_POST['department'] == 'Housing') ? 'selected' : ''; ?>>Housing</option>
                        <option value="Other" <?php echo (isset($_POST['department']) && $_POST['department'] == 'Other') ? 'selected' : ''; ?>>Other</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="password" class="form-label">Password</label>
                    <input type="password" id="password" name="password" class="form-input" 
                           placeholder="Enter your password" required
                           onkeyup="showPasswordStrength(this.value, 'passwordStrength')">
                    <div id="passwordStrength" class="mt-1 text-sm"></div>
                </div>

                <div class="form-group">
                    <label for="confirm_password" class="form-label">Confirm Password</label>
                    <input type="password" id="confirm_password" name="confirm_password" class="form-input" 
                           placeholder="Confirm your password" required>
                </div>

                <button type="submit" class="btn btn-primary w-full">
                    Create Account
                </button>
            </form>

            <!-- Login Link -->
            <div class="text-center mt-6">
                <p class="text-gray-400">
                    Already have an account? 
                    <a href="login.php" class="text-blue-400 hover:text-blue-300">Sign in</a>
                </p>
            </div>
            <?php endif; ?>

            <!-- Footer -->
            <div class="text-center mt-6 text-gray-500 text-sm">
                <p>&copy; 2024 Mutare City Council ICT Department</p>
            </div>
        </div>
    </div>

    <script src="../assets/js/script.js"></script>
</body>
</html>
