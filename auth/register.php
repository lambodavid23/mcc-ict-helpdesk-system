<?php
session_start();
require_once '../config/database.php';

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
    
    if (empty($name)) { $errors[] = 'Full name is required'; }
    if (empty($email)) { $errors[] = 'Email is required'; }
    elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) { $errors[] = 'Invalid email format'; }
    if (empty($password)) { $errors[] = 'Password is required'; }
    elseif (strlen($password) < 8) { $errors[] = 'Password must be at least 8 characters'; }
    if ($password !== $confirm_password) { $errors[] = 'Passwords do not match'; }
    if (empty($department)) { $errors[] = 'Department is required'; }
    
    if (empty($errors)) {
        $database = new Database();
        $conn = $database->getConnection();
        
        $email_escaped = $conn->real_escape_string($email);
        $check_query = "SELECT id FROM users WHERE email = '$email_escaped' LIMIT 1
                        UNION ALL
                        SELECT id FROM admins WHERE email = '$email_escaped' LIMIT 1
                        UNION ALL
                        SELECT id FROM technicians WHERE email = '$email_escaped' LIMIT 1";
        $check_result = $conn->query($check_query);
        
        if ($check_result && $check_result->num_rows > 0) {
            $errors[] = 'Email already registered';
        } else {
            $name_escaped = $conn->real_escape_string($name);
            $department_escaped = $conn->real_escape_string($department);
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            
            $insert_query = "INSERT INTO users (name, email, password, department) 
                           VALUES ('$name_escaped', '$email_escaped', '$hashed_password', '$department_escaped')";
            
            if ($conn->query($insert_query)) {
                $success = 'Account created. You may now login.';
            } else {
                $errors[] = 'Registration failed. Try again.';
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
        .strength-bar { height: 2px; background: #1a1a2e; flex: 1; }
    </style>
</head>
<body class="min-h-screen grid-bg flex items-center justify-center p-2">
    <div class="scan-line"></div>
    
    <div class="w-full max-w-[380px]">
        <div class="text-center mb-4">
            <img src="../assets/images/mutarelogo.png" alt="MCC" class="w-16 h-16 mx-auto mb-2">
            <span class="text-base font-bold text-[#e0e0e0] tracking-wide">City Of Mutare ICT Helpdesk</span>
        </div>
        
        <?php if (!empty($success)): ?>
            <div class="relative bg-[#0a0a0f]/90 border border-[#1a1a2e] rounded-xl p-8 text-center">
                <div class="corner-accent corner-tl"></div>
                <div class="corner-accent corner-tr"></div>
                <div class="corner-accent corner-bl"></div>
                <div class="corner-accent corner-br"></div>
                
                <div class="w-14 h-14 bg-[#00ff88]/10 border border-[#00ff88]/30 rounded-full flex items-center justify-center mx-auto mb-4">
                    <i data-lucide="check" class="w-6 h-6 text-[#00ff88]"></i>
                </div>
                <h3 class="text-[#e0e0e0] text-base font-semibold mb-2 glow-text">REGISTRATION COMPLETE</h3>
                <p class="text-[#666] text-sm mb-5"><?php echo htmlspecialchars($success); ?></p>
                <a href="login.php" class="inline-flex items-center gap-2 bg-[#0f0f15] border border-[#00ff88] text-[#00ff88] px-5 py-2 rounded-lg text-sm hover:bg-[#00ff88]/10 transition-all">
                    <i data-lucide="log-in" class="w-4 h-4"></i>
                    PROCEED TO LOGIN
                </a>
            </div>
        <?php else: ?>
        
        <div class="relative bg-[#0a0a0f]/90 border border-[#1a1a2e] rounded-xl p-4">
            <div class="corner-accent corner-tl"></div>
            <div class="corner-accent corner-tr"></div>
            <div class="corner-accent corner-bl"></div>
            <div class="corner-accent corner-br"></div>
            
            <div class="text-center mb-3">
                <h2 class="text-[#e0e0e0] text-sm font-semibold glow-text tracking-wider">CREATE ACCOUNT</h2>
            </div>
            
            <?php if (!empty($errors)): ?>
                <div class="bg-[#1a0505]/80 border border-[#3a1515] rounded-lg px-3 py-2 mb-3 flex items-center gap-2">
                    <i data-lucide="alert-circle" class="w-3.5 h-3.5 text-[#ff4444]"></i>
                    <span class="text-[#ff6666] text-xs"><?php echo htmlspecialchars($errors[0]); ?></span>
                </div>
            <?php endif; ?>
            
            <form method="POST" class="space-y-2.5">
                <div>
                    <label class="block text-[#555] text-[10px] uppercase tracking-widest mb-1 font-medium">Full Name</label>
                    <div class="relative">
                        <i data-lucide="user" class="absolute left-3 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-[#333]"></i>
                        <input type="text" name="name" required value="<?php echo isset($_POST['name']) ? htmlspecialchars($_POST['name']) : ''; ?>"
                            class="w-full bg-[#0f0f15] border border-[#1a1a2e] rounded-lg pl-9 pr-3 py-1.5 text-[#e0e0e0] text-xs placeholder-[#444] focus:outline-none input-glow transition-all"
                            placeholder="John Doe">
                    </div>
                </div>
                
                <div>
                    <label class="block text-[#555] text-[10px] uppercase tracking-widest mb-1 font-medium">Email</label>
                    <div class="relative">
                        <i data-lucide="mail" class="absolute left-3 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-[#333]"></i>
                        <input type="email" name="email" required value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>"
                            class="w-full bg-[#0f0f15] border border-[#1a1a2e] rounded-lg pl-9 pr-3 py-1.5 text-[#e0e0e0] text-xs placeholder-[#444] focus:outline-none input-glow transition-all"
                            placeholder="user@mutarecity.co.zw">
                    </div>
                </div>
                
                <div>
                    <label class="block text-[#555] text-[10px] uppercase tracking-widest mb-1 font-medium">Department</label>
                    <div class="relative">
                        <i data-lucide="building-2" class="absolute left-3 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-[#333]"></i>
                        <select name="department" required
                            class="w-full bg-[#0f0f15] border border-[#1a1a2e] rounded-lg pl-9 pr-8 py-1.5 text-[#e0e0e0] text-xs focus:outline-none input-glow transition-all appearance-none cursor-pointer">
                            <option value="" class="text-[#444]">Select</option>
                            <option value="Finance" <?php echo (isset($_POST['department']) && $_POST['department'] == 'Finance') ? 'selected' : ''; ?>>Finance</option>
                            <option value="HR" <?php echo (isset($_POST['department']) && $_POST['department'] == 'HR') ? 'selected' : ''; ?>>Human Resources</option>
                            <option value="Administration" <?php echo (isset($_POST['department']) && $_POST['department'] == 'Administration') ? 'selected' : ''; ?>>Administration</option>
                            <option value="Engineering" <?php echo (isset($_POST['department']) && $_POST['department'] == 'Engineering') ? 'selected' : ''; ?>>Engineering</option>
                            <option value="Health" <?php echo (isset($_POST['department']) && $_POST['department'] == 'Health') ? 'selected' : ''; ?>>Health Services</option>
                            <option value="Education" <?php echo (isset($_POST['department']) && $_POST['department'] == 'Education') ? 'selected' : ''; ?>>Education</option>
                            <option value="Housing" <?php echo (isset($_POST['department']) && $_POST['department'] == 'Housing') ? 'selected' : ''; ?>>Housing</option>
                            <option value="Other" <?php echo (isset($_POST['department']) && $_POST['department'] == 'Other') ? 'selected' : ''; ?>>Other</option>
                        </select>
                        <i data-lucide="chevron-down" class="absolute right-3 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-[#333]"></i>
                    </div>
                </div>
                
                <div>
                    <label class="block text-[#555] text-[10px] uppercase tracking-widest mb-1 font-medium">Password</label>
                    <div class="relative">
                        <i data-lucide="lock" class="absolute left-3 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-[#333]"></i>
                        <input type="password" name="password" required id="password"
                            class="w-full bg-[#0f0f15] border border-[#1a1a2e] rounded-lg pl-9 pr-9 py-1.5 text-[#e0e0e0] text-xs placeholder-[#444] focus:outline-none input-glow transition-all"
                            placeholder="Min. 8 characters" onkeyup="checkStrength(this.value)">
                        <button type="button" onclick="togglePwd()" class="absolute right-2.5 top-1/2 -translate-y-1/2">
                            <i data-lucide="eye" class="w-3.5 h-3.5 text-[#333] hover:text-[#00ff88] transition-colors" id="eyeBtn"></i>
                        </button>
                    </div>
                    <div class="flex gap-1 mt-1">
                        <div class="strength-bar" id="bar1"></div>
                        <div class="strength-bar" id="bar2"></div>
                        <div class="strength-bar" id="bar3"></div>
                    </div>
                </div>
                
                <div>
                    <label class="block text-[#555] text-[10px] uppercase tracking-widest mb-1 font-medium">Confirm Password</label>
                    <div class="relative">
                        <i data-lucide="lock" class="absolute left-3 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-[#333]"></i>
                        <input type="password" name="confirm_password" required id="confirmPwd"
                            class="w-full bg-[#0f0f15] border border-[#1a1a2e] rounded-lg pl-9 pr-9 py-1.5 text-[#e0e0e0] text-xs placeholder-[#444] focus:outline-none input-glow transition-all"
                            placeholder="Confirm password">
                        <button type="button" onclick="toggleConfirm()" class="absolute right-2.5 top-1/2 -translate-y-1/2">
                            <i data-lucide="eye" class="w-3.5 h-3.5 text-[#333] hover:text-[#00ff88] transition-colors" id="eyeBtn2"></i>
                        </button>
                    </div>
                </div>
                
                <button type="submit" class="btn-cyber w-full bg-[#0f0f15] border border-[#1a1a2e] hover:border-[#00ff88] text-[#00ff88] py-2 rounded-lg text-xs font-semibold tracking-wide transition-all hover:bg-[#00ff88]/5 flex items-center justify-center gap-2 mt-1">
                    <i data-lucide="user-plus" class="w-3.5 h-3.5"></i>
                    CREATE ACCOUNT
                </button>
            </form>
            
            <div class="mt-3 pt-2 border-t border-[#1a1a2e] text-center">
                <p class="text-[#555] text-xs">
                    Have account? <a href="login.php" class="text-[#00ff88] font-semibold hover:underline">Sign in</a>
                </p>
            </div>
        </div>
        <?php endif; ?>
        <p class="text-center text-[#2a2a2a] text-[10px] mt-4">© 2026 Mutare City Council ICT</p>
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
        
        function toggleConfirm() {
            const pwd = document.getElementById('confirmPwd');
            const btn = document.getElementById('eyeBtn2');
            if (pwd.type === 'password') { pwd.type = 'text'; btn.setAttribute('data-lucide', 'eye-off'); }
            else { pwd.type = 'password'; btn.setAttribute('data-lucide', 'eye'); }
            lucide.createIcons();
        }
        
        function checkStrength(pwd) {
            const b1 = document.getElementById('bar1');
            const b2 = document.getElementById('bar2');
            const b3 = document.getElementById('bar3');
            
            let strength = 0;
            if (pwd.length >= 8) strength++;
            if (/[A-Z]/.test(pwd) && /[a-z]/.test(pwd)) strength++;
            if (/[0-9]/.test(pwd) || /[^A-Za-z0-9]/.test(pwd)) strength++;
            
            b1.style.background = strength >= 1 ? '#00ff88' : '#1a1a2e';
            b2.style.background = strength >= 2 ? '#00ff88' : '#1a1a2e';
            b3.style.background = strength >= 3 ? '#00ff88' : '#1a1a2e';
        }
    </script>
</body>
</html>
