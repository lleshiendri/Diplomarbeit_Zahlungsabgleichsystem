<?php
/**
 * Logout handler: destroys the session and redirects to login.
 * Safe to include or call from any page; no output before redirect.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Clear all session data
$_SESSION = [];

// Remove session cookie so the session is not restored
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'] ?? false,
        $params['httponly'] ?? false
    );
}

session_destroy();

header('Location: login/login.php');
exit;
