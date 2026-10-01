<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole([ROLE_ADMIN, ROLE_REGISTRAR]);

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'add_type') {
        $name = trim($_POST['requirement_name'] ?? '');
        $isRequired = isset($_POST['is_required']) ? 1 : 0;

        if ($name === '') {
            $errors[] = 'Requirement name is required.';
        } else {
            $pdo->prepare("INSERT INTO requirement_types (requirement_name, is_required) VALUES (:n, :r)")
                ->execute([':n' => $name, ':r' => $isRequired]);
            logActivity($pdo, $_SESSION['user_id'], 'requirement_type_added', $name);
            setFlash('success', 'Requirement added. It will now be included in the checklist for every NEW enrollment submission (existing submitted applications are not changed retroactively).');
        }
    }

    if ($action === 'delete_type') {
        // Only allow deleting types that no enrollment has used yet, to avoid orphaning records
        $id = (int) $_POST['requirement_type_id'];
        $inUse = $pdo->prepare("SELECT COUNT(*) FROM enrollment_requirements WHERE requirement_type_id = :id");
        $inUse->execute([':id' => $id]);
        if ((int) $inUse->fetchColumn() > 0) {
            $errors[] = 'This requirement is already in use by student applications and cannot be deleted. You can leave it and just avoid using it for new checklists, or ask an admin to review directly in the database.';
        } else {
            $pdo->prepare("DELETE FROM requirement_types WHERE requirement_type_id = :id")->execute([':id' => $id]);
            setFlash('success', 'Requirement removed.');
        }
    }

    if (empty($errors))
        redirect('/registrar/requirement-types');
}

$types = $pdo->query(
    "SELECT rt.*, (SELECT COUNT(*) FROM enrollment_requirements er WHERE er.requirement_type_id = rt.requirement_type_id) AS usage_count
     FROM requirement_types rt ORDER BY rt.requirement_name"
)->fetchAll();

$pageTitle = 'Requirement Types';
require_once __DIR__ . '/../includes/header.php';
?>

<?php foreach ($errors as $err): ?>
    <div class="alert alert-error"><?= e($err) ?></div><?php endforeach; ?>

<div class="card">
    <h4>Add Requirement Type</h4>
    <p class="helper-text">This is the master checklist. When a student submits their enrollment, the system
        automatically creates a copy of every "Required" item here for the Registrar to verify.</p>
    <form method="POST" style="display:flex; gap:14px; align-items:flex-end; flex-wrap:wrap;">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="add_type">
        <div class="field" style="min-width:280px;"><label>Requirement Name</label><input type="text"
                name="requirement_name" placeholder="e.g. Certificate of Transfer" required></div>
        <div class="field"><label style="font-weight:400;"><input type="checkbox" name="is_required" checked
                    style="width:auto;"> Required (auto-added to every submission)</label></div>
        <div class="field"><label style="visibility:hidden;">&nbsp;</label><button class="btn btn-primary"
                type="submit">Add</button></div>
    </form>
</div>

<div class="card" style="margin-top:20px;">
    <h4>Current Requirement Types</h4>
    <table>
        <thead>
            <tr>
                <th>Requirement</th>
                <th>Required?</th>
                <th>In Use By</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($types as $t): ?>
                <tr>
                    <td><?= e($t['requirement_name']) ?></td>
                    <td><span
                            class="badge badge-<?= $t['is_required'] ? 'info' : 'neutral' ?>"><?= $t['is_required'] ? 'Required' : 'Optional' ?></span>
                    </td>
                    <td><?= (int) $t['usage_count'] ?> application(s)</td>
                    <td>
                        <?php if ((int) $t['usage_count'] === 0): ?>
                            <form method="POST"><?= csrfField() ?>
                                <input type="hidden" name="action" value="delete_type">
                                <input type="hidden" name="requirement_type_id" value="<?= (int) $t['requirement_type_id'] ?>">
                                <button class="btn btn-danger btn-sm"
                                    data-confirm="Delete this requirement type?">Delete</button>
                            </form>
                        <?php else: ?>
                            <span class="helper-text">In use</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>