<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole([ROLE_ADMIN]);

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'add_fee') {
        $name = trim($_POST['fee_name'] ?? '');
        $type = $_POST['fee_type'] ?? 'Fixed';
        $amount = (float) ($_POST['amount'] ?? 0);

        if ($name === '')
            $errors[] = 'Fee name is required.';
        if ($amount < 0)
            $errors[] = 'Amount cannot be negative.';
        if (!in_array($type, ['Fixed', 'Per Unit'], true))
            $errors[] = 'Invalid fee type.';

        if (empty($errors)) {
            $pdo->prepare("INSERT INTO fee_structure (fee_name, fee_type, amount) VALUES (:n, :t, :a)")
                ->execute([':n' => $name, ':t' => $type, ':a' => $amount]);
            logActivity($pdo, $_SESSION['user_id'], 'fee_added', $name);
            setFlash('success', 'Fee added.');
        }
    }

    if ($action === 'toggle_fee') {
        $pdo->prepare("UPDATE fee_structure SET is_active = NOT is_active WHERE fee_id = :id")
            ->execute([':id' => (int) $_POST['fee_id']]);
    }

    if (empty($errors))
        redirect('/admin/settings');
}

$fees = $pdo->query("SELECT * FROM fee_structure ORDER BY fee_id DESC")->fetchAll();

$pageTitle = 'System Settings';
require_once __DIR__ . '/../includes/header.php';
?>

<?php foreach ($errors as $err): ?>
    <div class="alert alert-error"><?= e($err) ?></div><?php endforeach; ?>

<div class="card">
    <h4>Add Fee</h4>
    <form method="POST" class="grid grid-4" style="align-items:end;">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="add_fee">
        <div class="field"><label>Fee Name</label><input type="text" name="fee_name" required></div>
        <div class="field"><label>Type</label><select name="fee_type">
                <option>Fixed</option>
                <option>Per Unit</option>
            </select></div>
        <div class="field"><label>Amount (&#8369;)</label><input type="number" step="0.01" min="0" name="amount"
                required></div>
        <div class="field"><label style="visibility:hidden;">&nbsp;</label>
            <button class="btn btn-primary" type="submit">Add Fee</button>
        </div>
    </form>
</div>

<div class="card" style="margin-top:20px;">
    <h4>Fee Structure</h4>
    <table>
        <thead>
            <tr>
                <th>Fee</th>
                <th>Type</th>
                <th>Amount</th>
                <th>Status</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($fees as $f): ?>
                <tr>
                    <td><?= e($f['fee_name']) ?></td>
                    <td><?= e($f['fee_type']) ?></td>
                    <td>&#8369;<?= number_format($f['amount'], 2) ?></td>
                    <td><span
                            class="badge badge-<?= $f['is_active'] ? 'success' : 'neutral' ?>"><?= $f['is_active'] ? 'Active' : 'Inactive' ?></span>
                    </td>
                    <td>
                        <form method="POST"><?= csrfField() ?>
                            <input type="hidden" name="action" value="toggle_fee">
                            <input type="hidden" name="fee_id" value="<?= (int) $f['fee_id'] ?>">
                            <button
                                class="btn btn-outline btn-sm"><?= $f['is_active'] ? 'Deactivate' : 'Activate' ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>