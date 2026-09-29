<?php
require_once '../config/auth_helper.php';
require_once '../config/database.php';

if (isset($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit();
}

$errors = [];
$reset_link = '';
// Outcome of the last submission, deliberately independent of whether a token
// was actually created. The template keys off this, not off $reset_link, so
// a known and an unknown address render the identical page. Keying off
// $reset_link would just invert the oracle: "sent" for real accounts, the
// form again for unknown ones.
$reset_sent = false;

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        $errors[] = 'Invalid or expired form token. Please reload the page and try again.';
    }
    $email = trim($_POST['email'] ?? '');

    if (empty($email)) {
        $errors[] = 'Email is required';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Invalid email format';
    } else {
        $user = findUserByEmail($email);
        if ($user) {
            $token = bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', time() + 24 * 3600);
            $database = new Database();
            $conn = $database->getConnection();
            $stmt = $conn->prepare(
                "DELETE FROM password_resets WHERE user_type = ? AND user_id = ?"
            );
            $stmt->bind_param('si', $user['user_type'], $user['id']);
            $stmt->execute();
            $stmt->close();
            $token_digest = hashBearerToken($token);
            $stmt = $conn->prepare(
                "INSERT INTO password_resets (user_type, user_id, token, expires_at)
                 VALUES (?, ?, ?, ?)"
            );
            // bind_param takes its arguments by reference, so the digest has
            // to be a variable rather than a call result.
            $stmt->bind_param('siss', $user['user_type'], $user['id'], $token_digest, $expires);
            $stmt->execute();
            $stmt->close();
            $reset_link = appBaseUrl() . "/auth/reset_password.php?token=" . $token;
            sendPasswordResetEmail($user['email'], $user['name'], $reset_link);
        }
        // Nothing is revealed about whether the address matched, and the token
        // is never rendered: it would land in browser history, the access log
        // and the Referer header of any link followed from the page.
        $reset_sent = true;
        $errors = [];
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - MCC ICT Helpdesk</title>
    <link rel="stylesheet" href="../assets/css/tailwind.css">
    <link rel="stylesheet" href="../assets/fonts/fonts.css">
    <script src="../assets/js/lucide.js"></script>
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
        .link-box { word-break: break-all; }
    </style>
</head>
<body class="min-h-screen grid-bg flex items-center justify-center p-2">
    <div class="scan-line"></div>

    <div class="w-full max-w-[380px]">
        <div class="text-center mb-4">
            <img src="../assets/images/mutarelogo.png" alt="MCC" class="w-16 h-16 mx-auto mb-2">
            <span class="text-base font-bold text-[#e0e0e0] tracking-wide">City Of Mutare ICT Helpdesk</span>
        </div>

        <?php if ($reset_sent): ?>
            <div class="relative bg-[#0a0a0f]/90 border border-[#1a1a2e] rounded-xl p-8 text-center">
                <div class="corner-accent corner-tl"></div>
                <div class="corner-accent corner-tr"></div>
                <div class="corner-accent corner-bl"></div>
                <div class="corner-accent corner-br"></div>

                <div class="w-14 h-14 bg-[#00ff88]/10 border border-[#00ff88]/30 rounded-full flex items-center justify-center mx-auto mb-4">
                    <i data-lucide="mail-check" class="w-6 h-6 text-[#00ff88]"></i>
                </div>
                <h3 class="text-[#e0e0e0] text-base font-semibold mb-2 glow-text">CHECK YOUR EMAIL</h3>
                <p class="text-[#666] text-sm">If that address has an account, a reset link is on its way. The link expires in 24 hours.</p>
                <a href="login.php" class="inline-flex items-center gap-2 bg-[#0f0f15] border border-[#00ff88] text-[#00ff88] px-5 py-2 rounded-lg text-sm hover:bg-[#00ff88]/10 transition-all mt-5">
                    <i data-lucide="log-in" class="w-4 h-4"></i>
                    BACK TO LOGIN
                </a>
            </div>
        <?php else: ?>

        <div class="relative bg-[#0a0a0f]/90 border border-[#1a1a2e] rounded-xl p-4">
            <div class="corner-accent corner-tl"></div>
            <div class="corner-accent corner-tr"></div>
            <div class="corner-accent corner-bl"></div>
            <div class="corner-accent corner-br"></div>

            <div class="text-center mb-3">
                <h2 class="text-[#e0e0e0] text-sm font-semibold glow-text tracking-wider">RESET PASSWORD</h2>
                <p class="text-[#555] text-[10px] mt-1 uppercase tracking-widest">Enter your account email</p>
            </div>

            <?php if (!empty($errors)): ?>
                <div class="bg-[#1a0505]/80 border border-[#3a1515] rounded-lg px-3 py-2 mb-3 flex items-center gap-2">
                    <i data-lucide="alert-circle" class="w-3.5 h-3.5 text-[#ff4444]"></i>
                    <span class="text-[#ff6666] text-xs"><?php echo htmlspecialchars($errors[0]); ?></span>
                </div>
            <?php endif; ?>

            <form method="POST" class="space-y-3">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generateCSRFToken()); ?>">
                <div>
                    <label class="block text-[#555] text-[10px] uppercase tracking-widest mb-1 font-medium">Email</label>
                    <div class="relative">
                        <i data-lucide="mail" class="absolute left-3 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-[#333]"></i>
                        <input type="email" name="email" required value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>"
                            class="w-full bg-[#0f0f15] border border-[#1a1a2e] rounded-lg pl-9 pr-3 py-2 text-[#e0e0e0] text-xs placeholder-[#444] focus:outline-none input-glow transition-all"
                            placeholder="user@mutarecity.co.zw">
                    </div>
                </div>

                <button type="submit" class="btn-cyber w-full bg-[#0f0f15] border border-[#1a1a2e] hover:border-[#00ff88] text-[#00ff88] py-2.5 rounded-lg text-xs font-semibold tracking-wide transition-all hover:bg-[#00ff88]/5 flex items-center justify-center gap-2">
                    <i data-lucide="key-round" class="w-3.5 h-3.5"></i>
                    GENERATE RESET LINK
                </button>
            </form>

            <div class="mt-3 pt-2 border-t border-[#1a1a2e] text-center">
                <p class="text-[#555] text-xs">
                    Remembered it? <a href="login.php" class="text-[#00ff88] font-semibold hover:underline">Sign in</a>
                </p>
            </div>
        </div>
        <?php endif; ?>
        <p class="text-center text-[#2a2a2a] text-[10px] mt-4">© 2026 Mutare City Council ICT</p>
    </div>

    <script>if (window.lucide) lucide.createIcons();</script>
</body>
</html>