<?php
// auth/logout.php — clear session + remember token
declare(strict_types=1);
require_once __DIR__ . '/../config/db.php';
$rememberCookieName = 'remember_me';
session_start();

try {
    if (!empty($_COOKIE[$rememberCookieName]) && ($pdo instanceof PDO)) {
        $cookie = $_COOKIE[$rememberCookieName];
        if (strpos($cookie, ':') !== false) {
            list($selector, $validator) = explode(':', $cookie, 2);
            $del = $pdo->prepare("DELETE FROM user_remember_tokens WHERE selector = ?");
            $del->execute([$selector]);
        }
        setcookie($rememberCookieName, '', time() - 3600, '/');
    }
} catch (Throwable $ex) {
    error_log('Logout cleanup error: ' . $ex->getMessage());
}

// destroy session
$_SESSION = [];
if (ini_get("session.use_cookies")) {
    setcookie(session_name(), '', time() - 42000, '/');
}
session_destroy();

// redirect to frontpage or login
header('Location: /');
exit;
