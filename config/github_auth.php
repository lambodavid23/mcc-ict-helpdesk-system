<?php
/**
 * GitHub OAuth Configuration
 * Smart ICT Helpdesk System - Mutare City Council
 * 
 * Register your OAuth App at: https://github.com/settings/applications/new
 * Set Authorization callback URL to: http://localhost/mcc-ict-helpdesk-system/auth/github_callback.php
 */

define('GITHUB_CLIENT_ID', 'YOUR_GITHUB_CLIENT_ID');
define('GITHUB_CLIENT_SECRET', 'YOUR_GITHUB_CLIENT_SECRET');
define('GITHUB_REDIRECT_URI', 'http://localhost/mcc-ict-helpdesk-system/auth/github_callback.php');
define('GITHUB_AUTH_URL', 'https://github.com/login/oauth/authorize');
define('GITHUB_TOKEN_URL', 'https://github.com/login/oauth/access_token');
define('GITHUB_USER_API', 'https://api.github.com/user');
define('GITHUB_SCOPE', 'read:user user:email');
?>