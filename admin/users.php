<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole([ROLE_ADMIN]);

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'create_staff') {
        $firstName = trim($_POST['first_name'] ?? '');
        $lastName = trim($_POST['last_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $roleId = (int) $_POST['role_id'];
        $tempPassword = bin2hex(random_bytes(6)); // random temp password, shown once

        if ($firstName === '' || $lastName === '')
            $errors[] = 'Name is required.';
        if (!isValidEmail($email))
            $errors[] = 'Valid email is required.';
        if (!in_array($roleId, [ROLE_ADMIN, ROLE_SCHEDULER, ROLE_REGISTRAR, ROLE_CASHIER, ROLE_TEACHER], true)) {
            $errors[] = 'Invalid role selected.';
        }

        if (empty($errors)) {
            $check = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = :e");
            $check->execute([':e' => $email]);
            if ((int) $check->fetchColumn() > 0) {
                $errors[] = 'That email is already registered.';
            } else {
                $hash = password_hash($tempPassword, PASSWORD_DEFAULT);
                $pdo->prepare(
                    "INSERT INTO users (role_id, first_name, last_name, email, password_hash, is_active, email_verified)
                     VALUES (:r, :fn, :ln, :email, :hash, 1, 1)"
                )->execute([':r' => $roleId, ':fn' => $firstName, ':ln' => $lastName, ':email' => $email, ':hash' => $hash]);

                logActivity($pdo, $_SESSION['user_id'], 'staff_account_created', $email);

                // Shown once, in a persistent (non-autohiding) box after redirect — see below.
                $_SESSION['new_credentials'] = ['email' => $email, 'password' => $tempPassword];
                setFlash('success', "Account created for {$email}.");
            }
        }
    }

    if ($action === 'toggle_active') {
        $targetId = (int) $_POST['user_id'];
        if ($targetId !== (int) $_SESSION['user_id']) { // can't deactivate self
            $pdo->prepare("UPDATE users SET is_active = NOT is_active WHERE user_id = :id")->execute([':id' => $targetId]);
            logActivity($pdo, $_SESSION['user_id'], 'account_status_toggled', "User #{$targetId}");
        }
    }

    if ($action === 'reset_password') {
        $targetId = (int) $_POST['user_id'];
        $stmt = $pdo->prepare("SELECT email FROM users WHERE user_id = :id");
        $stmt->execute([':id' => $targetId]);
        $targetEmail = $stmt->fetchColumn();

        if ($targetEmail) {
            $newTempPassword = bin2hex(random_bytes(6));
            $hash = password_hash($newTempPassword, PASSWORD_DEFAULT);
            $pdo->prepare("UPDATE users SET password_hash = :h, failed_login_attempts = 0, locked_until = NULL WHERE user_id = :id")
                ->execute([':h' => $hash, ':id' => $targetId]);

            logActivity($pdo, $_SESSION['user_id'], 'staff_password_reset', "User #{$targetId} ({$targetEmail})");
            $_SESSION['new_credentials'] = ['email' => $targetEmail, 'password' => $newTempPassword];
            setFlash('success', "Password reset for {$targetEmail}.");
        }
    }

    if (empty($errors))
        redirect('/admin/users');
}

// One-time credential display: read then immediately clear from session so a page refresh never shows it again.
$newCredentials = $_SESSION['new_credentials'] ?? null;
unset($_SESSION['new_credentials']);

$search = trim($_GET['q'] ?? '');
$where = '';
$params = [];
if ($search !== '') {
    $where = "WHERE u.first_name LIKE :q1 OR u.last_name LIKE :q2 OR u.email LIKE :q3 OR u.student_id LIKE :q4";
    $like = "%{$search}%";
    $params[':q1'] = $like;
    $params[':q2'] = $like;
    $params[':q3'] = $like;
    $params[':q4'] = $like;
}
$stmt = $pdo->prepare(
    "SELECT u.*, r.role_name FROM users u JOIN roles r ON r.role_id = u.role_id {$where} ORDER BY u.created_at DESC LIMIT 100"
);
$stmt->execute($params);
$users = $stmt->fetchAll();

$pageTitle = 'User Accounts';
require_once __DIR__ . '/../includes/header.php';
?>

<?php foreach ($errors as $err): ?>
    <div class="alert alert-error"><?= e($err) ?></div><?php endforeach; ?>

<?php if ($newCredentials): ?>
    <div class="card" style="border: 2px solid var(--brass); background: var(--brass-tint); margin-bottom:20px;">
        <h4 style="margin-bottom:6px;">Save these credentials now</h4>
        <p class="helper-text" style="margin-bottom:14px;">This password is shown only once and cannot be retrieved later —
            copy it and share it securely with the account owner. If it's lost, use "Reset Password" below to generate a new
            one.</p>
        <div style="display:flex; gap:24px; flex-wrap:wrap; align-items:center;">
            <div><strong>Email:</strong> <code id="cred-email"><?= e($newCredentials['email']) ?></code></div>
            <div><strong>Temporary Password:</strong> <code id="cred-password"
                    style="font-size:1.05rem;"><?= e($newCredentials['password']) ?></code></div>
            <button type="button" class="btn btn-brass btn-sm" onclick="copyCredentials()">Copy both</button>
        </div>
    </div>
    <script>
        function copyCredentials() {
            const email = document.getElementById('cred-email').textContent;
            const password = document.getElementById('cred-password').textContent;
            navigator.clipboard.writeText(`Email: ${email}\nTemporary Password: ${password}`)
                .then(() => appToast('Copied to clipboard.', 'success'))
                .catch(() => appToast('Could not copy automatically — please select and copy the text manually.', 'error'));
        }
    </script>
<?php endif; ?>

<div class="card">
    <h4>Create Staff Account</h4>
    <form method="POST" class="grid grid-4" style="align-items:end;">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="create_staff">
        <div class="field"><label>First Name</label><input type="text" name="first_name" required></div>
        <div class="field"><label>Last Name</label><input type="text" name="last_name" required></div>
        <div class="field"><label>Email</label><input type="email" name="email" required></div>
        <div class="field"><label>Role</label>
            <select name="role_id" required>
                <option value="<?= ROLE_ADMIN ?>">Admin</option>
                <option value="<?= ROLE_SCHEDULER ?>">Academic Scheduler</option>
                <option value="<?= ROLE_REGISTRAR ?>">Registrar</option>
                <option value="<?= ROLE_CASHIER ?>">Cashier</option>
                <option value="<?= ROLE_TEACHER ?>">Teacher</option>
            </select>
        </div>
        <div><button class="btn btn-primary" type="submit">Create Account</button></div>
    </form>
</div>

<form method="GET" class="toolbar" style="margin-top:20px;">
    <input type="text" name="q" placeholder="Search users..." value="<?= e($search) ?>">
    <button type="submit" class="btn btn-outline btn-sm">Search</button>
</form>

<div class="card">
    <table>
        <thead>
            <tr>
                <th>Name</th>
                <th>Email</th>
                <th>Role</th>
                <th>Status</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($users as $u): ?>
                <tr>
                    <td><?= e($u['first_name'] . ' ' . $u['last_name']) ?><?= $u['student_id'] ? ' (' . e($u['student_id']) . ')' : '' ?>
                    </td>
                    <td><?= e($u['email']) ?></td>
                    <td><?= e(str_replace('_', ' ', $u['role_name'])) ?></td>
                    <td><span
                            class="badge badge-<?= $u['is_active'] ? 'success' : 'danger' ?>"><?= $u['is_active'] ? 'Active' : 'Deactivated' ?></span>
                    </td>
                    <td style="display:flex; gap:6px;">
                        <?php if ((int) $u['user_id'] !== (int) $_SESSION['user_id']): ?>
                            <form method="POST"><?= csrfField() ?>
                                <input type="hidden" name="action" value="toggle_active">
                                <input type="hidden" name="user_id" value="<?= (int) $u['user_id'] ?>">
                                <button class="btn btn-outline btn-sm"
                                    data-confirm="<?= $u['is_active'] ? 'Deactivate' : 'Reactivate' ?> this account?"><?= $u['is_active'] ? 'Deactivate' : 'Reactivate' ?></button>
                            </form>
                        <?php endif; ?>
                        <form method="POST"><?= csrfField() ?>
                            <input type="hidden" name="action" value="reset_password">
                            <input type="hidden" name="user_id" value="<?= (int) $u['user_id'] ?>">
                            <button class="btn btn-outline btn-sm"
                                data-confirm="Generate a new temporary password for <?= e($u['first_name']) ?>? Their current password will stop working immediately.">Reset
                                Password</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>