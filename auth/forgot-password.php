<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/mailer.php';

$sent = false;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $email = trim($_POST['email'] ?? '');

    if (!isValidEmail($email)) {
        $error = 'Please enter a valid email address.';
    } else {
        $stmt = $pdo->prepare("SELECT user_id FROM users WHERE email = :email");
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch();

        if ($user) {
            $token = bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', time() + 3600); // 1 hour
            $pdo->prepare("UPDATE users SET reset_token = :t, reset_token_expires = :e WHERE user_id = :id")
                ->execute([':t' => hash('sha256', $token), ':e' => $expires, ':id' => $user['user_id']]);

            $scheme = isset($_SERVER['HTTPS']) ? 'https' : 'http';
            $resetLink = "{$scheme}://{$_SERVER['HTTP_HOST']}" . BASE_URL . "/auth/reset-password?token={$token}&id={$user['user_id']}";
            $nameStmt = $pdo->prepare("SELECT first_name FROM users WHERE user_id = :id");
            $nameStmt->execute([':id' => $user['user_id']]);
            $firstName = $nameStmt->fetchColumn() ?: 'there';
            sendPasswordResetEmail($pdo, $email, $firstName, $resetLink);
            logActivity($pdo, $user['user_id'], 'password_reset_requested', null);
        }
        // Always show the same message whether or not the email exists (prevents account/email enumeration)
        $sent = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password · <?= e(SITE_NAME) ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
</head>

<body>
    <div class="auth-wrap">
        <div class="auth-card">
            <h1>Reset your password</h1>
            <div class="auth-sub">Enter your email and we'll send you a reset link.</div>

            <?php if ($sent): ?>
                <div class="alert alert-success">If an account exists for that email, a reset link has been sent.</div>
            <?php elseif ($error): ?>
                <div class="alert alert-error"><?= e($error) ?></div>
            <?php endif; ?>

            <form method="POST" novalidate autocomplete="off">
                <?= csrfField() ?>
                <div class="field">
                    <label for="email">Email address</label>
                    <input type="email" id="email" name="email" required autofocus>
                </div>
                <button type="submit" class="btn btn-primary">Send reset link</button>
            </form>
            <div class="auth-foot"><a href="<?= BASE_URL ?>/auth/login">Back to log in</a></div>
        </div>
    </div>
</body>

</html>