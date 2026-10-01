<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole([ROLE_ADMIN, ROLE_SCHEDULER]);

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'add_program') {
        $code = strtoupper(trim($_POST['program_code'] ?? ''));
        $name = trim($_POST['program_name'] ?? '');
        $description = trim($_POST['description'] ?? '');

        if ($code === '' || $name === '') {
            $errors[] = 'Program code and name are required.';
        } elseif (!preg_match('/^[A-Z0-9\-]{2,20}$/', $code)) {
            $errors[] = 'Program code should be short letters/numbers only, e.g. BSA, BSCS.';
        }

        if (empty($errors)) {
            try {
                $pdo->prepare(
                    "INSERT INTO programs (program_code, program_name, description, is_active) VALUES (:c, :n, :d, 1)"
                )->execute([':c' => $code, ':n' => $name, ':d' => $description ?: null]);
                logActivity($pdo, $_SESSION['user_id'], 'program_created', $code);
                setFlash('success', "Program \"{$code}\" added. You can now add subjects for it in Curriculum Management and enable students to register under it.");
            } catch (PDOException $e) {
                $errors[] = "That program code already exists.";
            }
        }
    }

    if ($action === 'toggle_active') {
        $pdo->prepare("UPDATE programs SET is_active = NOT is_active WHERE program_id = :id")
            ->execute([':id' => (int) $_POST['program_id']]);
        logActivity($pdo, $_SESSION['user_id'], 'program_status_toggled', "Program #{$_POST['program_id']}");
    }

    if (empty($errors))
        redirect('/admin/programs');
}

$programs = $pdo->query(
    "SELECT p.*, (SELECT COUNT(*) FROM student_profiles sp WHERE sp.program_id = p.program_id) AS student_count
     FROM programs p ORDER BY p.program_name"
)->fetchAll();

$pageTitle = 'Programs';
require_once __DIR__ . '/../includes/header.php';
?>

<?php foreach ($errors as $err): ?>
    <div class="alert alert-error"><?= e($err) ?></div><?php endforeach; ?>

<div class="card">
    <h4>Add Program</h4>
    <form method="POST" class="grid grid-4" style="align-items:end;">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="add_program">
        <div class="field"><label>Program Code</label><input type="text" name="program_code" placeholder="BSA" required
                maxlength="20"></div>
        <div class="field" style="grid-column: span 2;"><label>Program Name</label><input type="text"
                name="program_name" placeholder="Bachelor of Science in Accountancy" required></div>
        <div class="field"><label style="visibility:hidden;">&nbsp;</label>
            <button class="btn btn-primary" type="submit">Add Program</button>
        </div>
        <div class="field" style="grid-column: 1 / -1;"><label>Description (optional)</label><input type="text"
                name="description"></div>
    </form>
</div>

<div class="card" style="margin-top:20px;">
    <h4>All Programs</h4>
    <table>
        <thead>
            <tr>
                <th>Code</th>
                <th>Name</th>
                <th>Students</th>
                <th>Status</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($programs as $p): ?>
                <tr>
                    <td><?= e($p['program_code']) ?></td>
                    <td><?= e($p['program_name']) ?></td>
                    <td><?= (int) $p['student_count'] ?></td>
                    <td><span
                            class="badge badge-<?= $p['is_active'] ? 'success' : 'neutral' ?>"><?= $p['is_active'] ? 'Active' : 'Inactive' ?></span>
                    </td>
                    <td>
                        <form method="POST"><?= csrfField() ?>
                            <input type="hidden" name="action" value="toggle_active">
                            <input type="hidden" name="program_id" value="<?= (int) $p['program_id'] ?>">
                            <button class="btn btn-outline btn-sm"
                                data-confirm="<?= $p['is_active'] ? 'Deactivate' : 'Reactivate' ?> this program? Deactivated programs no longer appear on the student registration form."><?= $p['is_active'] ? 'Deactivate' : 'Reactivate' ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>