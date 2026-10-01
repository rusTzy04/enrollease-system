<?php

if (session_status() === PHP_SESSION_NONE) {
    // Harden session cookie behavior
    session_set_cookie_params([
        'lifetime' => 0,             
        'path' => '/',
        'domain' => '',
        'secure' => isset($_SERVER['HTTPS']),  
        'httponly' => true,            
        'samesite' => 'Lax',           
    ]);
    session_name('ENROLLSESSID');       
    session_start();
}

// Idle timeout
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > SESSION_LIFETIME)) {
    $_SESSION = [];
    session_destroy();
    header('Location: ' . BASE_URL . '/auth/login?timeout=1');
    exit;
}
$_SESSION['last_activity'] = time();

// Regenerate session ID periodically to prevent session fixation
if (!isset($_SESSION['created'])) {
    $_SESSION['created'] = time();
} elseif (time() - $_SESSION['created'] > 300) {
    session_regenerate_id(true);
    $_SESSION['created'] = time();
}
