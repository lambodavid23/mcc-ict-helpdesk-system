<?php
/**
 * Create an administrator account, generating a strong random password.
 *
 * The SQL installs create their accounts with the sentinel password '!locked',
 * which no input can match, so there is no way in until a real password is
 * set. This is the supported way to get one.
 *
 *   php tools/create_admin.php
 *   php tools/create_admin.php --name="Tendai Moyo" --email=admin@mcc.co.zw
 *
 * The password is printed once and never stored in readable form. Command-line
 * only: it is not reachable over the web.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain');
    echo "403 Forbidden: create_admin.php is a command-line tool.\n";
    exit;
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/create_admin_password.php';

// Parsed by hand rather than with getopt(): PHP's getopt() stops at the first
// non-option argument, so `create_admin.php --name="A B" --email=x@y` would
// silently drop everything after the first bare word.
$options = ['name' => null, 'email' => null, 'password' => null, 'department' => null];
$aliases = ['name' => 'name', 'n' => 'name',
            'email' => 'email', 'e' => 'email',
            'password' => 'password', 'p' => 'password',
            'department' => 'department', 'd' => 'department'];
for ($i = 1; $i < count($argv); $i++) {
    $arg = $argv[$i];
    if (strpos($arg, '--') === 0 && strpos($arg, '=') !== false) {
        list($flag, $value) = explode('=', substr($arg, 2), 2);
    } elseif (strpos($arg, '--') === 0) {
        $flag = substr($arg, 2);
        $value = $argv[$i + 1] ?? null;
        $i++;
    } else {
        continue;
    }
    if (isset($aliases[$flag])) {
        $options[$aliases[$flag]] = $value;
    }
}
$name = $options['name'] ?? 'System Administrator';
$email = $options['email'] ?? 'admin@mcc.co.zw';
$department = $options['department'] ?? 'ICT';
$password = $options['password'] ?? generatePassword();

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Not a valid email address: $email\n");
    exit(1);
}
if (isset($options['password']) && strlen($password) < 12) {
    fwrite(STDERR, "Refusing a password shorter than 12 characters.\n");
    exit(1);
}

$db = new Database();
$conn = $db->getConnection();
if ($conn->connect_error) {
    fwrite(STDERR, "Database connection failed: " . $conn->connect_error . "\n");
    exit(1);
}

$stmt = $conn->prepare("SELECT id FROM admins WHERE email = ?");
$stmt->bind_param('s', $email);
$stmt->execute();
$exists = $stmt->get_result()->num_rows > 0;
$stmt->close();

$hash = password_hash($password, PASSWORD_DEFAULT);

if ($exists) {
    $stmt = $conn->prepare("UPDATE admins SET password = ? WHERE email = ?");
    $stmt->bind_param('ss', $hash, $email);
    $stmt->execute();
    $stmt->close();
    $action = 'Password updated for existing admin';
} else {
    $stmt = $conn->prepare(
        "INSERT INTO admins (name, email, password, department) VALUES (?, ?, ?, ?)"
    );
    $stmt->bind_param('ssss', $name, $email, $hash, $department);
    $stmt->execute();
    $stmt->close();
    $action = 'Admin created';
}

echo "\n$action\n";
echo str_repeat('-', 46) . "\n";
echo "Email:    $email\n";
echo "Password: $password\n";
echo str_repeat('-', 46) . "\n";
echo "This password is shown once and is not recoverable. Sign in and\n";
echo "change it from the admin panel if you need to.\n\n";
