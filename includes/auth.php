<?php
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/functions.php';

/** Is a user currently logged in? */
function isLoggedIn(): bool
{
    return isset($_SESSION['user_id']);
}

/** Require login, or redirect to login page (call at top of protected pages) */
function requireLogin(): void
{
    if (!isLoggedIn()) {
        redirect('/auth/login');
    }
}

/** Require one of the given role IDs; otherwise show 403 */
function requireRole(array $allowedRoleIds): void
{
    requireLogin();
    if (!in_array($_SESSION['role_id'], $allowedRoleIds, true)) {
        http_response_code(403);
        die('<h2>403 Forbidden</h2><p>You do not have permission to view this page.</p>');
    }
}

/** Generate/validate CSRF tokens for every form (defends against Cross-Site Request Forgery) */
function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrfField(): string
{
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrfToken()) . '">';
}

function verifyCsrf(): void
{
    $token = $_POST['csrf_token'] ?? '';
    if (empty($token) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        http_response_code(419);
        die('Your session expired or the request could not be verified. Please go back and try again.');
    }
}

function attemptLogin(PDO $pdo, string $email, string $password): array
{
    $stmt = $pdo->prepare(
        "SELECT user_id, role_id, first_name, last_name, email, password_hash,
                is_active, failed_login_attempts, locked_until
         FROM users WHERE email = :email LIMIT 1"
    );
    $stmt->execute([':email' => $email]);
    $user = $stmt->fetch();

    if (!$user) {
        logActivity($pdo, null, 'login_failed', "Unknown email: {$email}");
        return ['success' => false, 'message' => 'Invalid email or password.'];
    }

    if ($user['locked_until'] && strtotime($user['locked_until']) > time()) {
        return ['success' => false, 'message' => 'Account temporarily locked due to failed attempts. Try again later.'];
    }

    if (!$user['is_active']) {
        return ['success' => false, 'message' => 'This account has been deactivated. Contact the administrator.'];
    }

    if (!password_verify($password, $user['password_hash'])) {
        $attempts = $user['failed_login_attempts'] + 1;
        $lockUntil = null;
        if ($attempts >= MAX_LOGIN_ATTEMPTS) {
            $lockUntil = date('Y-m-d H:i:s', time() + LOCKOUT_MINUTES * 60);
        }
        $upd = $pdo->prepare("UPDATE users SET failed_login_attempts = :a, locked_until = :l WHERE user_id = :id");
        $upd->execute([':a' => $attempts, ':l' => $lockUntil, ':id' => $user['user_id']]);

        logActivity($pdo, $user['user_id'], 'login_failed', 'Incorrect password');
        return ['success' => false, 'message' => 'Invalid email or password.'];
    }


    $pdo->prepare("UPDATE users SET failed_login_attempts = 0, locked_until = NULL WHERE user_id = :id")
        ->execute([':id' => $user['user_id']]);

    if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
        $newHash = password_hash($password, PASSWORD_DEFAULT);
        $pdo->prepare("UPDATE users SET password_hash = :h WHERE user_id = :id")
            ->execute([':h' => $newHash, ':id' => $user['user_id']]);
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = $user['user_id'];
    $_SESSION['role_id'] = (int) $user['role_id'];
    $_SESSION['role_name'] = ROLE_NAMES[$user['role_id']] ?? 'unknown';
    $_SESSION['first_name'] = $user['first_name'];
    $_SESSION['last_name'] = $user['last_name'];
    $_SESSION['email'] = $user['email'];

    logActivity($pdo, $user['user_id'], 'login_success', null);

    return ['success' => true, 'role_name' => $_SESSION['role_name']];
}

function logout(): void
{
    global $pdo;
    if (isLoggedIn()) {
        logActivity($pdo, $_SESSION['user_id'], 'logout', null);
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}

function dashboardUrlFor(string $roleName): string
{
    $map = [
        'admin' => '/admin/dashboard',
        'academic_scheduler' => '/scheduler/dashboard',
        'registrar' => '/registrar/dashboard',
        'cashier' => '/cashier/dashboard',
        'teacher' => '/teacher/dashboard',
        'student' => '/student/dashboard',
    ];
    return $map[$roleName] ?? '/auth/login';
}
