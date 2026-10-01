<?php
require_once __DIR__ . '/../includes/auth.php';

$token = $_GET['token'] ?? $_POST['token'] ?? '';
$userId = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$errors = [];
$done = false;

$stmt = $pdo->prepare("SELECT user_id, reset_token, reset_token_expires FROM users WHERE user_id = :id");
$stmt->execute([':id' => $userId]);
$user = $stmt->fetch();

$validToken = $user
    && $user['reset_token']
    && hash_equals($user['reset_token'], hash('sha256', $token))
    && strtotime($user['reset_token_expires']) > time();

if (!$validToken) {
    $errors[] = 'This reset link is invalid or has expired. Please request a new one.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if (!isStrongPassword($password)) {
        $errors[] = 'Password must be at least 8 characters and include a letter and a number.';
    } elseif ($password !== $confirm) {
        $errors[] = 'Passwords do not match.';
    } else {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $pdo->prepare("UPDATE users SET password_hash = :h, reset_token = NULL, reset_token_expires = NULL, failed_login_attempts = 0, locked_until = NULL WHERE user_id = :id")
            ->execute([':h' => $hash, ':id' => $userId]);
        logActivity($pdo, $userId, 'password_reset_completed', null);
        $done = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Set New Password · <?= e(SITE_NAME) ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
</head>

<body>
    <div class="auth-wrap">
        <div class="auth-card">
            <div class="auth-brand">
                <div class="mark">E</div>
                <div class="name"><?= e(SITE_NAME) ?></div>
            </div>
            <h1>Set a new password</h1>

            <?php foreach ($errors as $err): ?>
                <div class="alert alert-error"><?= e($err) ?></div><?php endforeach; ?>

            <?php if ($done): ?>
                <div class="alert alert-success">Your password has been updated.</div>
                <a class="btn btn-primary" href="<?= BASE_URL ?>/auth/login">Go to login</a>
            <?php elseif ($validToken): ?>
                <form method="POST" novalidate autocomplete="off">
                    <?= csrfField() ?>
                    <input type="hidden" name="token" value="<?= e($token) ?>">
                    <input type="hidden" name="id" value="<?= (int) $userId ?>">
                    <div class="field">
                        <label for="password">New password</label>
                        <input type="password" id="password" name="password" required>
                    </div>
                    <div class="field">
                        <label for="confirm_password">Confirm new password</label>
                        <input type="password" id="confirm_password" name="confirm_password" required>
                    </div>
                    <button type="submit" class="btn btn-primary">Update password</button>
                </form>
            <?php else: ?>
                <a class="btn btn-outline" href="<?= BASE_URL ?>/auth/forgot-password">Request a new link</a>
            <?php endif; ?>
        </div>
    </div>
</body>

</html>