<?php
require_once __DIR__ . '/includes/auth.php';
requireLogin();

$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE user_id = :id");
    $stmt->execute([':id' => $_SESSION['user_id']]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($currentPassword, $user['password_hash'])) {
        $errors[] = 'Your current password is incorrect.';
    } elseif (!isStrongPassword($newPassword)) {
        $errors[] = 'New password must be at least 8 characters and include a letter and a number.';
    } elseif ($newPassword !== $confirmPassword) {
        $errors[] = 'New password and confirmation do not match.';
    } elseif (password_verify($newPassword, $user['password_hash'])) {
        $errors[] = 'Your new password must be different from your current password.';
    } else {
        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
        $pdo->prepare("UPDATE users SET password_hash = :h WHERE user_id = :id")
            ->execute([':h' => $newHash, ':id' => $_SESSION['user_id']]);
        logActivity($pdo, $_SESSION['user_id'], 'password_changed_self', null);
        $success = true;
    }
}

$pageTitle = 'Account Settings';
require_once __DIR__ . '/includes/header.php';
?>

<div class="card" style="max-width:480px;">
    <h2>Change Password</h2>

    <?php foreach ($errors as $err): ?>
        <div class="alert alert-error"><?= e($err) ?></div><?php endforeach; ?>
    <?php if ($success): ?>
        <div class="alert alert-success">Your password has been updated.</div><?php endif; ?>

    <form method="POST" novalidate autocomplete="off">
        <?= csrfField() ?>
        <div class="field">
            <label for="current_password">Current password</label>
            <input type="password" id="current_password" name="current_password" required
                autocomplete="current-password">
        </div>
        <div class="field">
            <label for="new_password">New password</label>
            <input type="password" id="new_password" name="new_password" required autocomplete="new-password">
            <div class="hint">At least 8 characters, with a letter and a number.</div>
        </div>
        <div class="field">
            <label for="confirm_password">Confirm new password</label>
            <input type="password" id="confirm_password" name="confirm_password" required autocomplete="new-password">
        </div>
        <button type="submit" class="btn btn-primary" style="width:auto; padding:11px 24px;">Update Password</button>
    </form>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>