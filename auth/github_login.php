<?php
session_start();
require_once '../config/github_auth.php';

$_SESSION['oauth_state'] = bin2hex(random_bytes(16));

$params = [
    'client_id' => GITHUB_CLIENT_ID,
    'redirect_uri' => GITHUB_REDIRECT_URI,
    'scope' => GITHUB_SCOPE,
    'state' => $_SESSION['oauth_state'],
    'allow_signup' => 'true'
];

header('Location: ' . GITHUB_AUTH_URL . '?' . http_build_query($params));
exit();
?>