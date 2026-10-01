<?php
require_once __DIR__ . '/../includes/auth.php';

if (isLoggedIn()) {
    redirect(dashboardUrlFor($_SESSION['role_name']));
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email === '' || $password === '') {
        $errors[] = 'Please enter both email and password.';
    } elseif (!isValidEmail($email)) {
        $errors[] = 'Please enter a valid email address.';
    } else {
        $result = attemptLogin($pdo, $email, $password);
        if ($result['success']) {
            redirect(dashboardUrlFor($result['role_name']));
        } else {
            $errors[] = $result['message'];
        }
    }
}

$timeout = isset($_GET['timeout']);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Log in · <?= e(SITE_NAME) ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
</head>

<body>
    <?php require_once __DIR__ . '/../includes/public-nav.php'; ?>
    <div class="auth-wrap">
        <div class="auth-card">
            <h1>Welcome back</h1>
            <div class="auth-sub">Log in to continue to your dashboard.</div>

            <?php if ($timeout): ?>
                <div class="alert alert-info">Your session expired for security. Please log in again.</div>
            <?php endif; ?>
            <?php foreach ($errors as $err): ?>
                <div class="alert alert-error"><?= e($err) ?></div>
            <?php endforeach; ?>

            <form method="POST" novalidate autocomplete="off">
                <?= csrfField() ?>
                <div class="field">
                    <label for="email">Email address</label>
                    <input type="email" id="email" name="email" required autofocus
                        value="<?= e($_POST['email'] ?? '') ?>">
                </div>
                <div class="field">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" required>
                </div>
                <button type="submit" class="btn btn-primary">Log in</button>
            </form>
            <div class="auth-foot">
                <a href="<?= BASE_URL ?>/auth/forgot-password">Forgot your password?</a><br><br>
                New student? Accounts are issued by the Registrar after your walk-in enrollment is complete.
                <a href="<?= BASE_URL ?>/apply">Apply for enrollment</a> to get started.
            </div>
        </div>
    </div>
    <?php require_once __DIR__ . '/../includes/public-footer.php'; ?>
</body>

</html>