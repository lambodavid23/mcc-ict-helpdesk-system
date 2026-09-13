<?php
session_start();
require_once '../config/database.php';
require_once '../config/github_auth.php';

if (!isset($_GET['code']) || !isset($_GET['state'])) {
    $_SESSION['error'] = 'GitHub authentication failed: Missing parameters.';
    header('Location: login.php');
    exit();
}

if (!isset($_SESSION['oauth_state']) || $_SESSION['oauth_state'] !== $_GET['state']) {
    $_SESSION['error'] = 'GitHub authentication failed: Invalid state parameter.';
    header('Location: login.php');
    exit();
}
unset($_SESSION['oauth_state']);

$code = $_GET['code'];

$ch = curl_init(GITHUB_TOKEN_URL);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
    'client_id' => GITHUB_CLIENT_ID,
    'client_secret' => GITHUB_CLIENT_SECRET,
    'code' => $code,
    'redirect_uri' => GITHUB_REDIRECT_URI
]));
$response = curl_exec($ch);
curl_close($ch);

$tokenData = json_decode($response, true);

if (!isset($tokenData['access_token'])) {
    $_SESSION['error'] = 'GitHub authentication failed: Could not obtain access token.';
    header('Location: login.php');
    exit();
}

$accessToken = $tokenData['access_token'];

$ch = curl_init(GITHUB_USER_API);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: token ' . $accessToken,
    'User-Agent: MCC-Helpdesk'
]);
$userResponse = curl_exec($ch);
curl_close($ch);

$githubUser = json_decode($userResponse, true);

if (!isset($githubUser['id'])) {
    $_SESSION['error'] = 'GitHub authentication failed: Could not retrieve user info.';
    header('Location: login.php');
    exit();
}

$ch = curl_init('https://api.github.com/user/emails');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: token ' . $accessToken,
    'User-Agent: MCC-Helpdesk'
]);
$emailsResponse = curl_exec($ch);
curl_close($ch);

$emails = json_decode($emailsResponse, true);
$primaryEmail = null;
foreach ($emails as $email) {
    if ($email['primary'] && $email['verified']) {
        $primaryEmail = $email['email'];
        break;
    }
}

if (!$primaryEmail) {
    $_SESSION['error'] = 'GitHub authentication failed: No verified email found.';
    header('Location: login.php');
    exit();
}

$database = new Database();
$conn = $database->getConnection();

$github_id = $githubUser['id'];
$email = $conn->real_escape_string($primaryEmail);
$name = $conn->real_escape_string($githubUser['name'] ?? $githubUser['login']);
$avatar = $conn->real_escape_string($githubUser['avatar_url'] ?? '');

$login_tables = [
    'admin' => 'admins',
    'technician' => 'technicians',
    'user' => 'users'
];

$user = null;
$role = null;

foreach ($login_tables as $role_name => $table) {
    $query = "SELECT id, name, email, department FROM $table WHERE github_id = '$github_id' LIMIT 1";
    $result = $conn->query($query);
    if ($result && $result->num_rows == 1) {
        $user = $result->fetch_assoc();
        $role = $role_name;
        break;
    }
}

if (!$user) {
    foreach ($login_tables as $role_name => $table) {
        $query = "SELECT id, name, email, department FROM $table WHERE email = '$email' LIMIT 1";
        $result = $conn->query($query);
        if ($result && $result->num_rows == 1) {
            $user = $result->fetch_assoc();
            $role = $role_name;

            $updateQuery = "UPDATE $table SET github_id = '$github_id' WHERE id = {$user['id']}";
            $conn->query($updateQuery);
            break;
        }
    }
}

if (!$user) {
    $department = 'ICT';
    $table = $login_tables['user'];

    $insertQuery = "INSERT INTO $table (name, email, password, department, github_id, avatar) 
                    VALUES ('$name', '$email', '', '$department', '$github_id', '$avatar')";
    $conn->query($insertQuery);

    $user_id = $conn->insert_id;
    $user = [
        'id' => $user_id,
        'name' => $name,
        'email' => $primaryEmail,
        'department' => $department
    ];
    $role = 'user';
}

$_SESSION['user_id'] = $user['id'];
$_SESSION['user_name'] = $user['name'];
$_SESSION['user_email'] = $user['email'];
$_SESSION['user_role'] = $role;
$_SESSION['user_department'] = $user['department'];
$_SESSION['auth_provider'] = 'github';

$ip_address = $_SERVER['REMOTE_ADDR'];
$user_agent = $_SERVER['HTTP_USER_AGENT'];
$logQuery = "INSERT INTO system_logs (user_id, user_type, action, description, ip_address, user_agent) 
             VALUES ({$user['id']}, '$role', 'LOGIN', 'User logged in via GitHub OAuth', '$ip_address', '$user_agent')";
$conn->query($logQuery);

switch ($role) {
    case 'admin':
        header('Location: ../admin/dashboard.php');
        break;
    case 'technician':
        header('Location: ../technician/dashboard.php');
        break;
    case 'user':
    default:
        header('Location: ../user/dashboard.php');
        break;
}
exit();
?>