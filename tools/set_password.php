<?php
/**
 * Set or reset the password of any account, in any of the three role tables.
 *
 * Used to unlock the accounts the SQL installs create with the '!locked'
 * sentinel, and to recover access when the only admin password is lost.
 *
 *   php tools/set_password.php admin@mcc.co.zw
 *   php tools/set_password.php someone@mcc.co.zw --password="correct horse"
 *
 * With no --password a strong one is generated and printed. Command-line only.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain');
    echo "403 Forbidden: set_password.php is a command-line tool.\n";
    exit;
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/create_admin_password.php';

// Parsed by hand rather than with getopt(): PHP's getopt() stops at the first
// non-option argument, so `set_password.php user@x --password=...` silently
// dropped the password and wrote a generated one instead.
$email = '';
$password = null;
for ($i = 1; $i < count($argv); $i++) {
    $arg = $argv[$i];
    if (strpos($arg, '--password=') === 0) {
        $password = substr($arg, strlen('--password='));
    } elseif ($arg === '--password' && isset($argv[$i + 1])) {
        $password = $argv[++$i];
    } elseif (strpos($arg, '--') !== 0) {
        $email = $arg;
    }
}

if ($email === '') {
    fwrite(STDERR, "Usage: php tools/set_password.php <email> [--password=\"...\"]\n");
    exit(1);
}

$db = new Database();
$conn = $db->getConnection();
if ($conn->connect_error) {
    fwrite(STDERR, "Database connection failed: " . $conn->connect_error . "\n");
    exit(1);
}

$password = $password ?? generatePassword(20);
if (strlen($password) < 8) {
    fwrite(STDERR, "Refusing a password shorter than 8 characters.\n");
    exit(1);
}

$hash = password_hash($password, PASSWORD_DEFAULT);
$done = false;

foreach (['admins' => 'admin', 'technicians' => 'technician', 'users' => 'user'] as $table => $role) {
    $stmt = $conn->prepare("SELECT id, name FROM $table WHERE email = ?");
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        continue;
    }

    $stmt = $conn->prepare("UPDATE $table SET password = ? WHERE id = ?");
    $stmt->bind_param('si', $hash, $row['id']);
    $stmt->execute();
    $stmt->close();

    // A password change must not leave the old session alive: burn any
    // outstanding reset link and remember-me cookie for this account.
    $stmt = $conn->prepare("DELETE FROM password_resets WHERE user_type = ? AND user_id = ?");
    $stmt->bind_param('si', $role, $row['id']);
    $stmt->execute();
    $stmt->close();

    $stmt = $conn->prepare("DELETE FROM remember_tokens WHERE user_type = ? AND user_id = ?");
    $stmt->bind_param('si', $role, $row['id']);
    $stmt->execute();
    $stmt->close();

    echo "\nPassword set for {$row['name']} <$email> ($role)\n";
    echo str_repeat('-', 46) . "\n";
    echo "Password: $password\n";
    echo str_repeat('-', 46) . "\n";
    echo "Shown once and not recoverable. Any remember-me session for this\n";
    echo "account has been signed out.\n\n";
    $done = true;
    break;
}

if (!$done) {
    fwrite(STDERR, "No account found with that email address: $email\n");
    exit(1);
}
