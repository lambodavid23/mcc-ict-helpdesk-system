<?php
require_once '../config/auth_helper.php';
require_once '../config/database.php';

if (isset($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit();
}

$errors = [];
$valid = false;
$token = isset($_GET['token']) ? $_GET['token'] : (isset($_POST['token']) ? $_POST['token'] : '');
$token = trim($token);

$database = new Database();
$conn = $database->getConnection();

$reset = null;
if (!empty($token)) {
    $token_escaped = $conn->real_escape_string($token);
    $result = $conn->query("SELECT id, user_type, user_id FROM password_resets 
                            WHERE token = '$token_escaped' AND used = 0 AND expires_at > NOW() LIMIT 1");
    if ($result && $result->num_rows == 1) {
        $reset = $result->fetch_assoc();
        $valid = true;
        if ($_SERVER['REQUEST_METHOD'] == 'POST') {
            $new_password = isset($_POST['password']) ? $_POST['password'] : '';
            $confirm_password = isset($_POST['confirm_password']) ? $_POST['confirm_password'] : '';
            if (strlen($new_password) < 8) {
                $errors[] = 'Password must be at least 8 characters';
            } elseif ($new_password !== $confirm_password) {
                $errors[] = 'Passwords do not match';
            } else {
                if (updateUserPassword($reset['user_type'], $reset['user_id'], $new_password)) {
                    $conn->query("UPDATE password_resets SET used = 1 WHERE id = " . (int)$reset['id']);
                    destroyRememberToken($reset['user_type'], $reset['user_id']);
                    $_SESSION['success'] = 'Password reset successful. You can now login with your new password.';
                    header('Location: login.php');
                    exit();
                } else {
                    $errors[] = 'Failed to reset password. Please try again.';
                }
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
    <title>Reset Password - MCC ICT Helpdesk</title>
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
<body class="min-h-screen grid-bg flex items-center justify-center p-2">
    <div class="scan-line"></div>

    <div class="w-full max-w-[380px]">
        <div class="text-center mb-4">
            <img src="../assets/images/mutarelogo.png" alt="MCC" class="w-16 h-16 mx-auto mb-2">
            <span class="text-base font-bold text-[#e0e0e0] tracking-wide">City Of Mutare ICT Helpdesk</span>
        </div>

        <div class="relative bg-[#0a0a0f]/90 border border-[#1a1a2e] rounded-xl p-4">
            <div class="corner-accent corner-tl"></div>
            <div class="corner-accent corner-tr"></div>
            <div class="corner-accent corner-bl"></div>
            <div class="corner-accent corner-br"></div>

            <div class="text-center mb-3">
                <h2 class="text-[#e0e0e0] text-sm font-semibold glow-text tracking-wider">CHOOSE NEW PASSWORD</h2>
            </div>

            <?php if (!empty($errors)): ?>
                <div class="bg-[#1a0505]/80 border border-[#3a1515] rounded-lg px-3 py-2 mb-3 flex items-center gap-2">
                    <i data-lucide="alert-circle" class="w-3.5 h-3.5 text-[#ff4444]"></i>
                    <span class="text-[#ff6666] text-xs"><?php echo htmlspecialchars($errors[0]); ?></span>
                </div>
            <?php endif; ?>

            <?php if ($valid): ?>
                <form method="POST" class="space-y-3">
                    <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
                    <div>
                        <label class="block text-[#555] text-[10px] uppercase tracking-widest mb-1 font-medium">New Password</label>
                        <div class="relative">
                            <i data-lucide="lock" class="absolute left-3 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-[#333]"></i>
                            <input type="password" name="password" required id="password"
                                class="w-full bg-[#0f0f15] border border-[#1a1a2e] rounded-lg pl-9 pr-9 py-2 text-[#e0e0e0] text-xs placeholder-[#444] focus:outline-none input-glow transition-all"
                                placeholder="Min. 8 characters">
                            <button type="button" onclick="togglePwd()" class="absolute right-3 top-1/2 -translate-y-1/2">
                                <i data-lucide="eye" class="w-3.5 h-3.5 text-[#333] hover:text-[#00ff88] transition-colors" id="eyeBtn"></i>
                            </button>
                        </div>
                    </div>

                    <div>
                        <label class="block text-[#555] text-[10px] uppercase tracking-widest mb-1 font-medium">Confirm Password</label>
                        <div class="relative">
                            <i data-lucide="lock" class="absolute left-3 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-[#333]"></i>
                            <input type="password" name="confirm_password" required id="confirmPwd"
                                class="w-full bg-[#0f0f15] border border-[#1a1a2e] rounded-lg pl-9 pr-9 py-2 text-[#e0e0e0] text-xs placeholder-[#444] focus:outline-none input-glow transition-all"
                                placeholder="Confirm password">
                            <button type="button" onclick="toggleConfirm()" class="absolute right-3 top-1/2 -translate-y-1/2">
                                <i data-lucide="eye" class="w-3.5 h-3.5 text-[#333] hover:text-[#00ff88] transition-colors" id="eyeBtn2"></i>
                            </button>
                        </div>
                    </div>

                    <button type="submit" class="btn-cyber w-full bg-[#0f0f15] border border-[#1a1a2e] hover:border-[#00ff88] text-[#00ff88] py-2.5 rounded-lg text-xs font-semibold tracking-wide transition-all hover:bg-[#00ff88]/5 flex items-center justify-center gap-2">
                        <i data-lucide="key-round" class="w-3.5 h-3.5"></i>
                        RESET PASSWORD
                    </button>
                </form>
            <?php else: ?>
                <div class="bg-[#1a0505]/80 border border-[#3a1515] rounded-lg px-3 py-3 flex items-center gap-2">
                    <i data-lucide="alert-triangle" class="w-3.5 h-3.5 text-[#ff4444]"></i>
                    <span class="text-[#ff6666] text-xs">This reset link is invalid or has expired.</span>
                </div>
                <div class="mt-3 pt-2 border-t border-[#1a1a2e] text-center">
                    <a href="forgot_password.php" class="text-[#00ff88] font-semibold hover:underline text-xs">Request a new reset link</a>
                </div>
            <?php endif; ?>
        </div>
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
    </script>
</body>
</html>