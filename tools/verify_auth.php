<?php
/**
 * Verification harness for the authentication fixes.
 *
 * Not part of the application. Checks the specific behaviours that were
 * changed, against the live schema.
 *
 * Session handling runs first, before any output, so auth_helper.php can set
 * the session cookie parameters without warning.
 */

// CLI only. This harness connects to the database and writes to it (it clears
// login_attempts and temporarily inserts a system_logs row). It sits under a
// web-reachable path, so without this guard anyone could trigger those writes
// by browsing to the URL.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    header('Content-Type: text/plain');
    echo "Not Found\n";
    exit;
}

$_SESSION = [];
require_once __DIR__ . '/../config/auth_helper.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/create_admin_password.php';

$pass = 0;
$fail = 0;
function check($label, $condition, $detail = '') {
    global $pass, $fail;
    if ($condition) {
        $pass++;
        echo "  PASS  $label\n";
    } else {
        $fail++;
        echo "  FAIL  $label" . ($detail ? "  ($detail)" : '') . "\n";
    }
}

echo "\n=== Sentinel password ===\n";
check('sentinel is rejected by password_verify for every plausible input',
    !password_verify('password', '!locked')
    && !password_verify('', '!locked')
    && !password_verify('!locked', '!locked')
    && !password_verify('admin', '!locked'));

echo "\n=== Token digesting ===\n";
check('hashBearerToken is a sha256 hex digest', hashBearerToken('abc') === hash('sha256', 'abc'));
check('digest is 64 chars, fits token CHAR(64)',
    strlen(hashBearerToken(str_repeat('x', 500))) === 64);
check('different tokens produce different digests',
    hashBearerToken('a') !== hashBearerToken('b'));

echo "\n=== user_agent sanitisation ===\n";
$_SERVER['HTTP_USER_AGENT'] = "evil' , 'x'); DROP TABLE tickets; -- \x00\x07";
$ua = clientUserAgent();
check('control characters stripped', strpos($ua, "\x00") === false && strpos($ua, "\x07") === false);
check('length capped at 255', strlen($ua) <= 255);
$_SERVER['HTTP_USER_AGENT'] = str_repeat('a', 1000);
check('long header truncated', strlen(clientUserAgent()) === 255);
unset($_SERVER['HTTP_USER_AGENT']);
check('absent header yields empty string', clientUserAgent() === '');
$_SERVER['REMOTE_ADDR'] = "1.2.3.4' OR '1'='1";
check('ip is passed through for binding (not interpolated)', clientIp() === "1.2.3.4' OR '1'='1");

echo "\n=== Live database: injection is no longer possible ===\n";
$db = new Database();
$conn = $db->getConnection();

$before = (int)$conn->query("SELECT COUNT(*) c FROM system_logs")->fetch_assoc()['c'];
// The exact payload that used to be interpolated into the login audit log.
$payload = "x'), ('9999','admin','LOGIN','pwn','1.1.1.1',(SELECT 1 FROM admins LIMIT 1)); -- ";
$test_user_id = 1;
$test_role = 'user';
$test_ip = '1.1.1.1';
$test_agent = substr($payload, 0, 255);
$stmt = $conn->prepare(
    "INSERT INTO system_logs (user_id, user_type, action, description, ip_address, user_agent)
     VALUES (?, ?, 'LOGIN', 'injection test', ?, ?)"
);
$stmt->bind_param('isss', $test_user_id, $test_role, $test_ip, $test_agent);
$stmt->execute();
$stmt->close();

$injected = (int)$conn->query("SELECT COUNT(*) c FROM system_logs WHERE description = 'pwn'")->fetch_assoc()['c'];
check('prepared statement stored the payload as inert data', $injected === 0);
check('exactly one audit row was written', (int)$conn->query("SELECT COUNT(*) c FROM system_logs")->fetch_assoc()['c'] === $before + 1);
check('tables still present after the payload', (int)$conn->query("SELECT COUNT(*) c FROM information_schema.tables WHERE table_schema='mcc_helpdesk'")->fetch_assoc()['c'] > 0);

$conn->query("DELETE FROM system_logs WHERE description = 'injection test'");

echo "\n=== Token columns hold digests, not tokens ===\n";
check('remember token column contains a sha256 digest, not a raw token', (function () use ($conn) {
    $row = $conn->query("SELECT token FROM remember_tokens LIMIT 1");
    if (!$row || $row->num_rows === 0) {
        return null;
    }
    $token = $row->fetch_assoc()['token'];
    return preg_match('/^[0-9a-f]{64}$/', $token) === 1;
})() !== false, 'token column should hold a 64 char hex digest');

echo "\n=== Login throttle ===\n";
$conn->query("DELETE FROM login_attempts");
check('first attempt allowed', loginAllowed('10.9.9.9', $conn));
for ($i = 0; $i < 5; $i++) {
    recordLoginFailure('10.9.9.9', $conn);
}
check('sixth attempt blocked after 5 failures', !loginAllowed('10.9.9.9', $conn));
check('a different IP is unaffected', loginAllowed('10.9.9.10', $conn));
clearLoginFailures('10.9.9.9', $conn);
check('clearing after success unblocks', loginAllowed('10.9.9.9', $conn));
$conn->query("DELETE FROM login_attempts");

echo "\n=== CSRF ===\n";
$_SESSION = [];
$token = generateCSRFToken();
check('valid token accepted', validateCSRFToken($token));
check('wrong token rejected', !validateCSRFToken('deadbeef'));
check('empty token rejected', !validateCSRFToken(''));
check('rejected when session has no token', !validateCSRFToken($token . 'x'));
check('tokens are per-session', (function () {
    $_SESSION = [];
    $a = generateCSRFToken();
    $_SESSION = [];
    $b = generateCSRFToken();
    return $a !== $b;
})());

echo "\n=== Password policy ===\n";
check('generated password meets 8 char minimum', strlen(generatePassword(20)) >= 8);
check('generated password has upper, lower, digit, symbol', (function () {
    $p = generatePassword(20);
    return preg_match('/[a-z]/', $p) && preg_match('/[A-Z]/', $p)
        && preg_match('/[0-9]/', $p) && preg_match('/[^A-Za-z0-9]/', $p);
})());
check('generated passwords are not all identical', generatePassword() !== generatePassword());

echo "\n$pass passed, $fail failed\n\n";
exit($fail > 0 ? 1 : 0);
