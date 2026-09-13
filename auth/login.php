<?php
session_start();
require_once '../config/database.php';

if (isset($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit();
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    
    if (empty($email)) {
        $errors[] = 'Email is required';
    }
    if (empty($password)) {
        $errors[] = 'Password is required';
    }
    
    if (empty($errors)) {
        $database = new Database();
        $conn = $database->getConnection();
        
        $email = $conn->real_escape_string($email);
        $login_tables = [
            'admin' => 'admins',
            'technician' => 'technicians',
            'user' => 'users'
        ];
        
        $user = null;
        $role = null;
        foreach ($login_tables as $role_name => $table) {
            $query = "SELECT id, name, email, password, department FROM $table WHERE email = '$email' LIMIT 1";
            $result = $conn->query($query);
            if ($result && $result->num_rows == 1) {
                $user = $result->fetch_assoc();
                $role = $role_name;
                break;
            }
        }
        
        if ($user) {
            if (password_verify($password, $user['password'])) {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_name'] = $user['name'];
                $_SESSION['user_email'] = $user['email'];
                $_SESSION['user_role'] = $role;
                $_SESSION['user_department'] = $user['department'];
                
                $ip_address = $_SERVER['REMOTE_ADDR'];
                $user_agent = $_SERVER['HTTP_USER_AGENT'];
                $log_query = "INSERT INTO system_logs (user_id, user_type, action, description, ip_address, user_agent) 
                             VALUES ('{$user['id']}', '$role', 'LOGIN', 'User logged into system', '$ip_address', '$user_agent')";
                $conn->query($log_query);
                
                switch ($role) {
                    case 'admin':
                        header('Location: ../admin/dashboard.php');
                        break;
                    case 'technician':
                        header('Location: ../technician/dashboard.php');
                        break;
                    case 'user':
                        header('Location: ../user/dashboard.php');
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
        .scan-line {
            position: fixed; top: 0; left: 0; right: 0; height: 2px;
            background: linear-gradient(90deg, transparent, rgba(0, 255, 136, 0.1), transparent);
            animation: scan 8s linear infinite; pointer-events: none;
        }
        @keyframes scan { 0% { top: 0; } 100% { top: 100%; } }
        .input-glow:focus { box-shadow: 0 0 0 1px #00ff88, 0 0 20px rgba(0, 255, 136, 0.1); }
        .btn-cyber { position: relative; overflow: hidden; }
        .btn-cyber::before {
            content: ''; position: absolute; top: 0; left: -100%; width: 100%; height: 100%;
            background: linear-gradient(90deg, transparent, rgba(0, 255, 136, 0.1), transparent);
            transition: left 0.5s;
        }
        .btn-cyber:hover::before { left: 100%; }
        .corner-accent { position: absolute; width: 20px; height: 20px; opacity: 0.5; }
        .corner-tl { top: -1px; left: -1px; border-top: 2px solid #00ff88; border-left: 2px solid #00ff88; }
        .corner-tr { top: -1px; right: -1px; border-top: 2px solid #00ff88; border-right: 2px solid #00ff88; }
        .corner-bl { bottom: -1px; left: -1px; border-bottom: 2px solid #00ff88; border-left: 2px solid #00ff88; }
        .corner-br { bottom: -1px; right: -1px; border-bottom: 2px solid #00ff88; border-right: 2px solid #00ff88; }
    </style>
</head>
<body class="min-h-screen grid-bg flex items-center justify-center p-4">
    <div class="scan-line"></div>
    
    <div class="w-full max-w-[380px]">
        <div class="text-center mb-6">
            <img src="../assets/images/mutarelogo.png" alt="MCC" class="w-20 h-20 mx-auto mb-3">
            <span class="text-xl font-bold text-[#e0e0e0] tracking-wide">City Of Mutare ICT Helpdesk</span>
        </div>
        
        <div class="relative bg-[#0a0a0f]/90 border border-[#1a1a2e] rounded-xl p-6">
            <div class="corner-accent corner-tl"></div>
            <div class="corner-accent corner-tr"></div>
            <div class="corner-accent corner-bl"></div>
            <div class="corner-accent corner-br"></div>
            
            <div class="text-center mb-5">
                <h2 class="text-[#e0e0e0] text-lg font-semibold glow-text tracking-wider">AUTHENTICATION</h2>
                <p class="text-[#555] text-[11px] mt-1 uppercase tracking-widest">Enter credentials</p>
            </div>
            
            <?php if (!empty($errors)): ?>
                <div class="bg-[#1a0505]/80 border border-[#3a1515] rounded-lg px-4 py-3 mb-5 flex items-center gap-3">
                    <i data-lucide="alert-circle" class="w-4 h-4 text-[#ff4444]"></i>
                    <span class="text-[#ff6666] text-xs"><?php echo htmlspecialchars($errors[0]); ?></span>
                </div>
            <?php endif; ?>
            
            <form method="POST" class="space-y-4">
                <div>
                    <label class="block text-[#555] text-[10px] uppercase tracking-widest mb-2 font-medium">Email</label>
                    <div class="relative">
                        <i data-lucide="mail" class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-[#333]"></i>
                        <input type="email" name="email" required
                            class="w-full bg-[#0f0f15] border border-[#1a1a2e] rounded-lg pl-10 pr-4 py-2.5 text-[#e0e0e0] text-sm placeholder-[#444] focus:outline-none input-glow transition-all"
                            placeholder="user@mutarecity.co.zw">
                    </div>
                </div>
                
                <div>
                    <label class="block text-[#555] text-[10px] uppercase tracking-widest mb-2 font-medium">Password</label>
                    <div class="relative">
                        <i data-lucide="lock" class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-[#333]"></i>
                        <input type="password" name="password" required id="password"
                            class="w-full bg-[#0f0f15] border border-[#1a1a2e] rounded-lg pl-10 pr-10 py-2.5 text-[#e0e0e0] text-sm placeholder-[#444] focus:outline-none input-glow transition-all"
                            placeholder="Enter password">
                        <button type="button" onclick="togglePwd()" class="absolute right-3 top-1/2 -translate-y-1/2">
                            <i data-lucide="eye" class="w-4 h-4 text-[#333] hover:text-[#00ff88] transition-colors" id="eyeBtn"></i>
                        </button>
                    </div>
                </div>
                
                <div class="flex items-center justify-between">
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" class="w-3.5 h-3.5 accent-[#00ff88] rounded">
                        <span class="text-[#555] text-xs">Remember</span>
                    </label>
                    <a href="#" class="text-[#00ff88] text-xs hover:underline">Forgot password?</a>
                </div>
                
                <button type="submit" class="btn-cyber w-full bg-[#0f0f15] border border-[#1a1a2e] hover:border-[#00ff88] text-[#00ff88] py-2.5 rounded-lg text-sm font-semibold tracking-wide transition-all hover:bg-[#00ff88]/5 flex items-center justify-center gap-2 mt-2">
                    <i data-lucide="log-in" class="w-4 h-4"></i>
                    ACCESS SYSTEM
                </button>
            </form>
            
            <div class="mt-5 pt-4 border-t border-[#1a1a2e] text-center">
                <p class="text-[#555] text-xs">
                    No account? <a href="register.php" class="text-[#00ff88] font-semibold hover:underline">Request access</a>
                </p>
            </div>
        </div>
        
        <p class="text-center text-[#2a2a2a] text-[10px] mt-3 tracking-wider">© 2026 Mutare City Council ICT</p>
    </div>
    
    <script>
        lucide.createIcons();
        function togglePwd() {
            const pwd = document.getElementById('password');
            const btn = document.getElementById('eyeBtn');
            if (pwd.type === 'password') { pwd.type = 'text'; btn.setAttribute('data-lucide', 'eye-off'); }
            else { pwd.type = 'password'; btn.setAttribute('data-lucide', 'eye'); }
            lucide.createIcons();
        }
    </script>
</body>
</html>
