<?php

// Command line only: on a public server anyone could reset these passwords.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/config/constants.php';
require_once __DIR__ . '/config/database.php';

$testAccounts = [
    ['role' => ROLE_ADMIN,     'first' => 'System',    'last' => 'Administrator', 'email' => 'admin@enrollease.edu.ph',     'password' => 'Admin@123'],
    ['role' => ROLE_REGISTRAR, 'first' => 'Test',       'last' => 'Registrar',    'email' => 'registrar@enrollease.edu.ph', 'password' => 'Registrar@123'],
    ['role' => ROLE_CASHIER,   'first' => 'Test',       'last' => 'Cashier',      'email' => 'cashier@enrollease.edu.ph',   'password' => 'Cashier@123'],
    ['role' => ROLE_TEACHER,   'first' => 'Test',       'last' => 'Teacher',      'email' => 'teacher@enrollease.edu.ph',   'password' => 'Teacher@123'],
    ['role' => ROLE_SCHEDULER,  'first' => 'Test',       'last' => 'Academic',     'email' => 'academic@enrollease.edu.ph',  'password' => 'Academic@123'],
];

$messages = [];

foreach ($testAccounts as $acct) {
    try {
        $hash = password_hash($acct['password'], PASSWORD_DEFAULT);

        $stmt = $pdo->prepare("SELECT user_id FROM users WHERE email = :email");
        $stmt->execute([':email' => $acct['email']]);
        $existing = $stmt->fetch();

        if ($existing) {
            $pdo->prepare(
                "UPDATE users SET password_hash = :hash, is_active = 1, failed_login_attempts = 0, locked_until = NULL
                 WHERE email = :email"
            )->execute([':hash' => $hash, ':email' => $acct['email']]);
            $messages[] = "Reset: {$acct['email']}";
        } else {
            $pdo->prepare(
                "INSERT INTO users (role_id, first_name, last_name, email, password_hash, is_active, email_verified)
                 VALUES (:role, :fn, :ln, :email, :hash, 1, 1)"
            )->execute([
                ':role' => $acct['role'], ':fn' => $acct['first'], ':ln' => $acct['last'],
                ':email' => $acct['email'], ':hash' => $hash,
            ]);
            $messages[] = "Created: {$acct['email']}";
        }
    } catch (Throwable $e) {
        $messages[] = "ERROR for {$acct['email']}: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Test Account Setup</title>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
</head>
<body>
<div class="auth-wrap">
    <div class="auth-card" style="max-width:595px;">
        <h1>Test Accounts Ready</h1>
        <?php foreach ($messages as $m): ?>
            <div class="alert alert-<?= str_starts_with($m, 'ERROR') ? 'error' : 'success' ?>"><?= htmlspecialchars($m) ?></div>
        <?php endforeach; ?>

        <table style="margin:16px 0;">
            <thead><tr><th>Role</th><th>Email</th><th>Password</th></tr></thead>
            <tbody>
            <?php foreach ($testAccounts as $acct): ?>
                <tr>
                    <td><?= htmlspecialchars(ROLE_NAMES[$acct['role']]) ?></td>
                    <td><code><?= htmlspecialchars($acct['email']) ?></code></td>
                    <td><code><?= htmlspecialchars($acct['password']) ?></code></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <div class="alert alert-error">
            <strong>Delete this file now.</strong> Remove <code>setup-test-accounts.php</code> from your project root —
            while it exists, anyone who visits it can reset these three accounts' passwords.
        </div>

        <a href="<?= BASE_URL ?>/auth/login" class="btn btn-primary">Go to login</a>
    </div>
</div>
</body>
</html>
