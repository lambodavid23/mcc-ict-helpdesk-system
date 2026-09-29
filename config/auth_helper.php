<?php
/**
 * Authentication Helper Functions
 * Smart ICT Helpdesk System - Mutare City Council
 */

// Start session if not already started
// headers_sent() guards the whole block: auth_helper.php is normally the first
// include, but if a page ever emits output first, session_start() would emit
// warnings straight into the response and corrupt a JSON payload.
if (session_status() == PHP_SESSION_NONE && !headers_sent()) {
    // Harden the session cookie before the session is created, so the flags
    // apply to the id this request goes on to use. It previously inherited
    // php.ini defaults, and XAMPP ships session.cookie_httponly off.
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
          || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $https || getenv('MCC_COOKIE_SECURE') === '1',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    // Reject session ids the server never issued, so a planted id cannot be
    // adopted and then inherited after login.
    ini_set('session.use_strict_mode', '1');
    session_start();
} elseif (session_status() == PHP_SESSION_NONE) {
    // Output already started. Start the session without the cookie hardening
    // rather than emitting warnings into the response body.
    session_start();
}

// Baseline response headers. Referrer-Policy matters here in particular:
// password reset links carry their token in the query string, and without this
// the token leaks to any third party the user clicks through to.
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
}

restoreRememberedUser();

/**
 * Maximum failed logins allowed per IP inside the throttle window.
 */
const LOGIN_MAX_FAILURES = 5;

/**
 * Seconds a locked-out IP must wait before trying again.
 */
const LOGIN_LOCKOUT_SECONDS = 900;

/**
 * Whether this IP is still inside its failed-login lockout.
 *
 * There was no throttle at all, which combined with the seeded default
 * passwords made every account in the system indefinitely brute-forceable.
 */
function loginAllowed($ip, $conn) {
    if ($ip === '' || !loginThrottleReady($conn)) {
        return true;
    }
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS attempts FROM login_attempts
         WHERE ip_address = ? AND attempted_at > (NOW() - INTERVAL ? SECOND)"
    );
    $window = LOGIN_LOCKOUT_SECONDS;
    $stmt->bind_param('si', $ip, $window);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();
    return !$row || (int)$row['attempts'] < LOGIN_MAX_FAILURES;
}

/**
 * Record a failed login for this IP.
 */
function recordLoginFailure($ip, $conn) {
    if ($ip === '' || !loginThrottleReady($conn)) {
        return;
    }
    $stmt = $conn->prepare(
        "INSERT INTO login_attempts (ip_address, attempted_at) VALUES (?, NOW())"
    );
    $stmt->bind_param('s', $ip);
    $stmt->execute();
    $stmt->close();
}

/**
 * Clear the failure count for this IP after a successful login.
 */
function clearLoginFailures($ip, $conn) {
    if ($ip === '' || !loginThrottleReady($conn)) {
        return;
    }
    $stmt = $conn->prepare("DELETE FROM login_attempts WHERE ip_address = ?");
    $stmt->bind_param('s', $ip);
    $stmt->execute();
    $stmt->close();
}

/**
 * The throttle table is created by a migration and may not be present yet on
 * an older database. Failing open keeps login working; it never turns a
 * database error into a locked-out user.
 */
function loginThrottleReady($conn) {
    static $ready = null;
    if ($ready === null) {
        $ready = (bool)$conn->query(
            "SHOW TABLES LIKE 'login_attempts'"
        )->num_rows;
    }
    return $ready;
}

/**
 * Hash a bearer token for storage.
 *
 * Reset and remember-me tokens are bearer credentials: whoever holds one can
 * take over the account. Storing the raw value meant a read of the database
 * (a backup, a stray dump, or the SQL injection that used to sit in the login
 * path) handed over every account in the system. Only the digest is stored,
 * so a database read yields nothing usable.
 */
function hashBearerToken($token) {
    return hash('sha256', $token);
}

/**
 * Password stored by the SQL installs in place of a real one.
 *
 * Not a valid bcrypt hash, so password_verify() rejects every guess against
 * it. The accounts exist so the assignment rules and knowledge base have rows
 * to reference, but nobody can sign in until a password is set with
 * tools/set_password.php. This replaces the previously shipped default
 * password, which was public in a public repository.
 */
define('LOCKED_PASSWORD_SENTINEL', '!locked');

/**
 * Client IP, safe to bind into a prepared statement.
 */
function clientIp() {
    return isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
}

/**
 * Client User-Agent, safe to bind into a prepared statement.
 *
 * Truncated to the system_logs column width and stripped of control
 * characters: the header is attacker-controlled, and unescaped
 * interpolation of it into an INSERT let a crafted header inject SQL.
 */
function clientUserAgent() {
    $ua = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
    $ua = preg_replace('/[\x00-\x1F\x7F]/', '', $ua);
    return substr($ua, 0, 255);
}

/**
 * Check if user is logged in
 */
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

/**
 * Check if current user is a pending user with temporary access
 */
function isPendingUser() {
    return isset($_SESSION['user_status']) && $_SESSION['user_status'] === 'pending';
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
 * Restore a login session from a remember-me cookie
 */
function restoreRememberedUser() {
    if (isset($_SESSION['user_id'])) {
        return;
    }
    if (!isset($_COOKIE['remember_token']) || empty($_COOKIE['remember_token'])) {
        return;
    }
    require_once __DIR__ . '/database.php';
    $database = new Database();
    $conn = $database->getConnection();
    if ($conn->connect_error) {
        return;
    }
    $token_hash = hashBearerToken($_COOKIE['remember_token']);
    $stmt = $conn->prepare(
        "SELECT id, user_type, user_id FROM remember_tokens
         WHERE token = ? AND expires_at > NOW() LIMIT 1"
    );
    $stmt->bind_param('s', $token_hash);
    $stmt->execute();
    $token_result = $stmt->get_result();
    if (!$token_result || $token_result->num_rows == 0) {
        $stmt->close();
        clearRememberCookie();
        return;
    }
    $token_row = $token_result->fetch_assoc();
    $stmt->close();
    $table = $token_row['user_type'] . 's';
    $status_field = $token_row['user_type'] == 'user' ? ', status, pending_expires_at' : '';
    $user_query = "SELECT id, name, email, department$status_field FROM $table WHERE id = " . (int)$token_row['user_id'] . " LIMIT 1";
    $user_result = $conn->query($user_query);
    if (!$user_result || $user_result->num_rows == 0) {
        $conn->query("DELETE FROM remember_tokens WHERE id = " . (int)$token_row['id']);
        clearRememberCookie();
        return;
    }
    $user = $user_result->fetch_assoc();
    if ($token_row['user_type'] == 'user' && isset($user['status']) && $user['status'] != 'active') {
        if ($user['status'] == 'pending' && isset($user['pending_expires_at']) && strtotime($user['pending_expires_at']) >= time()) {
            $_SESSION['user_status'] = 'pending';
        } else {
            if ($user['status'] == 'pending') {
                $conn->query("UPDATE users SET status = 'rejected' WHERE id = " . (int)$user['id']);
            }
            $conn->query("DELETE FROM remember_tokens WHERE id = " . (int)$token_row['id']);
            clearRememberCookie();
            return;
        }
    }
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user_name'] = $user['name'];
    $_SESSION['user_email'] = $user['email'];
    $_SESSION['user_role'] = $token_row['user_type'];
    $_SESSION['user_department'] = $user['department'];
    // Rotate on use: the presented token is burned and replaced, so a copy
    // captured from the cookie jar only works until its first use.
    issueRememberToken($token_row['user_type'], $token_row['user_id']);
}

/**
 * Cookie options for the remember-me token.
 *
 * Secure is off only because this is served over plain HTTP on XAMPP, where
 * a Secure cookie is silently dropped and "remember me" would stop working.
 * Set MCC_COOKIE_SECURE=1 once the app is behind HTTPS.
 */
function rememberCookieOptions() {
    $secure = getenv('MCC_COOKIE_SECURE') === '1';
    return [
        'expires'  => 0,
        'path'     => '/',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ];
}

function clearRememberCookie() {
    setcookie('remember_token', '', time() - 3600, rememberCookieOptions());
}

/**
 * Issue a remember-me cookie token for a user
 */
function issueRememberToken($role, $userId) {
    require_once __DIR__ . '/database.php';
    $database = new Database();
    $conn = $database->getConnection();
    if ($conn->connect_error) {
        return;
    }
    $token = bin2hex(random_bytes(32));
    $expires = date('Y-m-d H:i:s', time() + 30 * 24 * 3600);
    // Burn any prior token for this account first, so rotation on use and
    // re-login cannot leave old tokens live in the table.
    $del = $conn->prepare("DELETE FROM remember_tokens WHERE user_type = ? AND user_id = ?");
    $del->bind_param('si', $role, $userId);
    $del->execute();
    $del->close();
    $stmt = $conn->prepare(
        "INSERT INTO remember_tokens (user_type, user_id, token, expires_at)
         VALUES (?, ?, ?, ?)"
    );
    $stmt->bind_param('siss', $role, $userId, hashBearerToken($token), $expires);
    $stmt->execute();
    $stmt->close();
    $opts = rememberCookieOptions();
    // Pass the options ARRAY, not the positional form. The positional overload
    // has no samesite argument, so calling it this way silently discarded the
    // SameSite=Lax that rememberCookieOptions() had already computed.
    setcookie('remember_token', $token, time() + 30 * 24 * 3600, $opts);
}

/**
 * Clear the remember-me cookie and token for a user
 */
function destroyRememberToken($role, $userId) {
    clearRememberCookie();
    if ($role !== null && $userId !== null) {
        require_once __DIR__ . '/database.php';
        $database = new Database();
        $conn = $database->getConnection();
        if (!$conn->connect_error) {
            $stmt = $conn->prepare("DELETE FROM remember_tokens WHERE user_type = ? AND user_id = ?");
            $stmt->bind_param('si', $role, $userId);
            $stmt->execute();
            $stmt->close();
        }
    }
}

/**
 * Find a user across all account tables by email
 */
function findUserByEmail($email) {
    require_once __DIR__ . '/database.php';
    $database = new Database();
    $conn = $database->getConnection();
    if ($conn->connect_error) {
        return null;
    }
    // Only id/name/email/department, which exist on all three tables. A `status`
    // column used to be selected here, but admins has no such column, so the
    // query raised ER_BAD_FIELD_ERROR for every admin and the function fell
    // through to null - which silently broke admin password reset, because
    // forgot_password.php reports success either way.
    // password is deliberately not selected: the reset flow never needs the
    // hash, and fetching it only widens exposure.
    $tables = ['user' => 'users', 'technician' => 'technicians', 'admin' => 'admins'];
    foreach ($tables as $type => $table) {
        $stmt = $conn->prepare(
            "SELECT id, name, email, department FROM {$table} WHERE email = ? LIMIT 1"
        );
        if (!$stmt) {
            continue;
        }
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();
        if ($row) {
            $row['user_type'] = $type;
            return $row;
        }
    }
    return null;
}

/**
 * Update a user's password in the correct account table
 */
function updateUserPassword($userType, $userId, $newPassword) {
    require_once __DIR__ . '/database.php';
    $database = new Database();
    $conn = $database->getConnection();
    if ($conn->connect_error) {
        return false;
    }
    $tables = ['user' => 'users', 'technician' => 'technicians', 'admin' => 'admins'];
    if (!isset($tables[$userType])) {
        return false;
    }
    $hashed = password_hash($newPassword, PASSWORD_DEFAULT);
    $uid = (int)$userId;
    $stmt = $conn->prepare("UPDATE {$tables[$userType]} SET password = ? WHERE id = ?");
    $stmt->bind_param('si', $hashed, $uid);
    return $stmt->execute();
}

/**
 * Log user activity
 */
function logActivity($action, $description = '') {
    if (!isLoggedIn()) {
        return;
    }

    require_once 'database.php';

    // Reused across calls: logActivity() runs on most write actions, and
    // opening a fresh connection each time is pure overhead.
    static $conn = null;
    if ($conn === null) {
        $database = new Database();
        $conn = $database->getConnection();
    }

    $user_id    = $_SESSION['user_id'];
    $user_type  = isset($_SESSION['user_role']) ? $_SESSION['user_role'] : 'user';
    $ip_address = clientIp();
    $user_agent = clientUserAgent();

    $stmt = $conn->prepare(
        "INSERT INTO system_logs
            (user_id, user_type, action, description, ip_address, user_agent)
         VALUES (?, ?, ?, ?, ?, ?)"
    );
    if ($stmt) {
        $stmt->bind_param('isssss', $user_id, $user_type, $action, $description, $ip_address, $user_agent);
        $stmt->execute();
        $stmt->close();
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
 * Whether a Host header names this machine rather than an attacker.
 *
 * Only loopback and RFC1918 literals qualify. A bare hostname is NOT enough:
 * "evil.com" is a syntactically valid plain hostname, so accepting any of
 * them would let a request forge the domain that reset emails link to.
 */
function isTrustedLocalHost($host) {
    if ($host === '' || $host === null) {
        return false;
    }
    // Drop any :port suffix, keeping IPv6 brackets intact.
    $name = strtolower($host);
    if (preg_match('/^(\[[0-9a-f:]+\]|[^:]+)(:\d+)?$/', $name, $m)) {
        $name = $m[1];
    }
    if ($name === 'localhost' || $name === '[::1]' || $name === '::1') {
        return true;
    }
    if (!filter_var(trim($name, '[]'), FILTER_VALIDATE_IP)) {
        return false;
    }
    return filter_var(
        trim($name, '[]'),
        FILTER_VALIDATE_IP,
        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
    ) === false;
}

/**
 * Absolute base URL of the application, for links in emails.
 *
 * Built from MCC_BASE_URL when set. The Host header is never trusted for a
 * public hostname, because it is supplied by the client: a reset or ticket
 * link built from it can be pointed at an attacker's domain and phish whoever
 * receives it. It is consulted only to recover the local address on XAMPP
 * (localhost / 127.0.0.0/8 / RFC1918), where no public domain exists yet.
 *
 * @return string Absolute base URL, or '' when it cannot be determined safely.
 */
function appBaseUrl() {
    $configured = getenv('MCC_BASE_URL');
    if ($configured) {
        return rtrim($configured, '/');
    }
    $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
    if (isTrustedLocalHost($host)) {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
        // Strip the page name, keeping only the application root.
        $dir = rtrim(dirname($dir), '/');
        return $scheme . '://' . $host . $dir;
    }
    return '';
}

/**
 * Send the password reset link by email.
 *
 * The link is deliberately not shown on the page. Rendering it meant the
 * bearer token was written into browser history, the Apache access log and
 * the Referer header of any link followed from that page.
 */
function sendPasswordResetEmail($to, $name, $link) {
    $body = '<!DOCTYPE html><html><head><style>'
        . 'body{font-family:Arial,sans-serif;background:#050507;color:#e0e0e0;padding:20px;}'
        . '.container{max-width:600px;margin:0 auto;background:#0a0a0f;border:1px solid #1a1a2e;border-radius:12px;padding:30px;}'
        . '.header{border-bottom:2px solid #00ff88;padding-bottom:20px;margin-bottom:20px;}'
        . '.logo{color:#00ff88;font-size:24px;font-weight:bold;}'
        . '.title{color:#fff;font-size:18px;margin:20px 0;}'
        . '.message{color:#ccc;line-height:1.6;}'
        . '.button{display:inline-block;background:linear-gradient(135deg,#00ff88,#00cc6a);color:#050507;'
        . 'padding:12px 24px;border-radius:6px;text-decoration:none;font-weight:bold;margin-top:20px;}'
        . '.footer{margin-top:30px;padding-top:20px;border-top:1px solid #1a1a2e;color:#666;font-size:12px;}'
        . '</style></head><body><div class="container">'
        . '<div class="header"><span class="logo">MCC ICT HELPDESK</span></div>'
        . '<div class="title">Password reset requested</div>'
        . '<div class="message">'
        . '<p>Hello ' . htmlspecialchars($name) . ',</p>'
        . '<p>A password reset was requested for your MCC ICT Helpdesk account. '
        . 'Use the button below to choose a new password. The link expires in 24 hours '
        . 'and can only be used once.</p>'
        . '<p>If you did not request this, you can ignore this email; your password '
        . 'will not change.</p></div>'
        . '<a href="' . htmlspecialchars($link) . '" class="button">Reset my password</a>'
        . '<div class="footer"><p>This is an automated message from the MCC ICT Helpdesk System.</p>'
        . '<p>Please do not reply directly to this email.</p></div>'
        . '</div></body></html>';

    $headers = [
        'From: MCC ICT Helpdesk <noreply@mcc.co.zw>',
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
        'X-Mailer: PHP/' . phpversion(),
    ];
    // Suppressed and logged rather than emitted: a mail() warning is only
    // raised when there is an account to mail, so letting it reach the
    // response would tell a caller whether the address exists and undo the
    // uniform reply that the forgot-password form depends on.
    $sent = @mail($to, 'Reset your MCC ICT Helpdesk password', $body, implode("\r\n", $headers));
    if (!$sent) {
        error_log('MCC Helpdesk: password reset email could not be sent to ' . $to);
    }
    return $sent;
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
        'zap' => '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide-icon ' . $class . '"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>',
        'clock' => '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide-icon ' . $class . '"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>'
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
            ['title' => 'Attendance', 'url' => 'admin/attendance.php', 'icon' => 'clock'],
            ['title' => 'Reports', 'url' => 'admin/reports.php', 'icon' => 'trending-up'],
            ['title' => 'Knowledge Base', 'url' => 'system/knowledge_base.php', 'icon' => 'book-open']
        ],
        'technician' => [
            ['title' => 'Dashboard', 'url' => 'technician/dashboard.php', 'icon' => 'bar-chart-3'],
            ['title' => 'Ticket Queue', 'url' => 'technician/technician_queue.php', 'icon' => 'ticket'],
            ['title' => 'My History', 'url' => 'technician/technician_history.php', 'icon' => 'clipboard-list'],
            ['title' => 'Attendance', 'url' => 'technician/attendance.php', 'icon' => 'clock'],
            ['title' => 'Knowledge Base', 'url' => 'system/knowledge_base.php', 'icon' => 'book-open']
        ],
        'user' => [
            ['title' => 'Dashboard', 'url' => 'user/dashboard.php', 'icon' => 'bar-chart-3'],
            ['title' => 'Submit Ticket', 'url' => 'user/submit_ticket.php', 'icon' => 'plus'],
            ['title' => 'My Requests', 'url' => 'user/my_requests.php', 'icon' => 'clipboard-list'],
            ['title' => 'Knowledge Base', 'url' => 'system/knowledge_base.php', 'icon' => 'book-open']
        ]
    ];
    
    return isset($menus[$role]) ? $menus[$role] : [];
}
?>
