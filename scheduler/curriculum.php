<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole([ROLE_SCHEDULER]);

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'add_subject') {
        $code = trim($_POST['subject_code'] ?? '');
        $name = trim($_POST['subject_name'] ?? '');
        $units = (float)($_POST['units'] ?? 0);

        if ($code === '' || $name === '') $errors[] = 'Subject code and name are required.';
        if ($units <= 0 || $units > 12) $errors[] = 'Units must be between 0 and 12.';

        if (empty($errors)) {
            try {
                $pdo->prepare("INSERT INTO subjects (subject_code, subject_name, units, lecture_units) VALUES (:c, :n, :u1, :u2)")
                    ->execute([':c' => $code, ':n' => $name, ':u1' => $units, ':u2' => $units]);
                logActivity($pdo, $_SESSION['user_id'], 'subject_created', $code);
                setFlash('success', 'Subject added.');
            } catch (PDOException $e) {
                $errors[] = 'That subject code already exists.';
            }
        }
    }

    if ($action === 'add_to_curriculum') {
        $stmt = $pdo->prepare(
            "INSERT INTO curriculum (program_id, subject_id, year_level, semester_name, subject_type, effective_academic_year_id)
             VALUES (:pid, :sid, :yl, :sem, :type, :ay)"
        );
        $stmt->execute([
            ':pid' => (int)$_POST['program_id'], ':sid' => (int)$_POST['subject_id'],
            ':yl' => (int)$_POST['year_level'], ':sem' => $_POST['semester_name'],
            ':type' => $_POST['subject_type'], ':ay' => (int)$_POST['academic_year_id'],
        ]);
        logActivity($pdo, $_SESSION['user_id'], 'curriculum_updated', 'Subject added to curriculum');
        setFlash('success', 'Subject added to curriculum.');
    }

    if ($action === 'deactivate_subject') {
        $pdo->prepare("UPDATE subjects SET is_active = NOT is_active WHERE subject_id = :id")
            ->execute([':id' => (int)$_POST['subject_id']]);
        logActivity($pdo, $_SESSION['user_id'], 'subject_status_toggled', "Subject #{$_POST['subject_id']}");
        setFlash('success', 'Subject status updated.');
    }

    if ($action === 'delete_subject') {
        $subjectId = (int)$_POST['subject_id'];
        // Only allow a hard delete if nothing references this subject yet — otherwise deactivating is the safe option.
        $inUse = false;

        $chk = $pdo->prepare("SELECT COUNT(*) FROM curriculum WHERE subject_id = :id");
        $chk->execute([':id' => $subjectId]);
        if ((int)$chk->fetchColumn() > 0) $inUse = true;

        if (!$inUse) {
            $chk = $pdo->prepare("SELECT COUNT(*) FROM class_schedules WHERE subject_id = :id");
            $chk->execute([':id' => $subjectId]);
            if ((int)$chk->fetchColumn() > 0) $inUse = true;
        }

        if (!$inUse) {
            $chk = $pdo->prepare("SELECT COUNT(*) FROM subject_prerequisites WHERE subject_id = :id1 OR prerequisite_subject_id = :id2");
            $chk->execute([':id1' => $subjectId, ':id2' => $subjectId]);
            if ((int)$chk->fetchColumn() > 0) $inUse = true;
        }

        if ($inUse) {
            $errors[] = 'This subject is already used in a curriculum, class schedule, or prerequisite link, so it can\'t be permanently deleted. Use "Deactivate" instead — it hides the subject from new curriculum/schedule assignments while keeping existing records intact.';
        } else {
            $pdo->prepare("DELETE FROM subjects WHERE subject_id = :id")->execute([':id' => $subjectId]);
            logActivity($pdo, $_SESSION['user_id'], 'subject_deleted', "Subject #{$subjectId}");
            setFlash('success', 'Subject permanently deleted.');
        }
    }

    if ($action === 'add_prerequisite') {
        $subjectId = (int)$_POST['subject_id'];
        $prereqId = (int)$_POST['prerequisite_subject_id'];

        if ($subjectId === $prereqId) {
            $errors[] = 'A subject cannot be its own prerequisite.';
        } else {
            $reverse = $pdo->prepare(
                "SELECT COUNT(*) FROM subject_prerequisites WHERE subject_id = :pre AND prerequisite_subject_id = :sub"
            );
            $reverse->execute([':pre' => $prereqId, ':sub' => $subjectId]);
            if ((int)$reverse->fetchColumn() > 0) {
                $errors[] = 'That would create a circular requirement — the prerequisite you chose already requires this subject.';
            } else {
                try {
                    $pdo->prepare(
                        "INSERT INTO subject_prerequisites (subject_id, prerequisite_subject_id) VALUES (:sub, :pre)"
                    )->execute([':sub' => $subjectId, ':pre' => $prereqId]);
                    logActivity($pdo, $_SESSION['user_id'], 'prerequisite_added', "Subject #{$subjectId} requires #{$prereqId}");
                    setFlash('success', 'Prerequisite added.');
                } catch (PDOException $e) {
                    $errors[] = 'That prerequisite link already exists.';
                }
            }
        }
    }

    if ($action === 'delete_prerequisite') {
        $pdo->prepare("DELETE FROM subject_prerequisites WHERE id = :id")
            ->execute([':id' => (int)$_POST['prereq_id']]);
        setFlash('success', 'Prerequisite removed.');
    }

    if (empty($errors)) redirect('/scheduler/curriculum');
}

$subjects = $pdo->query("SELECT * FROM subjects ORDER BY is_active DESC, subject_code")->fetchAll();
$activeSubjects = array_values(array_filter($subjects, fn($s) => (int)$s['is_active'] === 1));
$programs = $pdo->query("SELECT * FROM programs WHERE is_active = 1")->fetchAll();
$years = $pdo->query("SELECT * FROM academic_years ORDER BY year_label DESC")->fetchAll();
$prerequisites = $pdo->query(
    "SELECT sp.id, sub.subject_code, sub.subject_name, pre.subject_code AS prereq_code, pre.subject_name AS prereq_name
     FROM subject_prerequisites sp
     JOIN subjects sub ON sub.subject_id = sp.subject_id
     JOIN subjects pre ON pre.subject_id = sp.prerequisite_subject_id
     ORDER BY sub.subject_code"
)->fetchAll();

$pageTitle = 'Curriculum Management';
require_once __DIR__ . '/../includes/header.php';
?>

<?php foreach ($errors as $err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endforeach; ?>

<div class="grid grid-2">
    <div class="card">
        <h4>Add Subject</h4>
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="add_subject">
            <div class="field"><label>Subject Code</label><input type="text" name="subject_code" required placeholder="e.g. IT101"></div>
            <div class="field"><label>Subject Name</label><input type="text" name="subject_name" required></div>
            <div class="field"><label>Units</label><input type="number" step="0.5" min="0.5" max="12" name="units" required value="3"></div>
            <button class="btn btn-primary" type="submit">Add Subject</button>
        </form>
    </div>

    <div class="card">
        <h4>Assign Subject to Curriculum</h4>
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="add_to_curriculum">
            <div class="field"><label>Program</label>
                <select name="program_id" required>
                    <?php foreach ($programs as $p): ?><option value="<?= (int)$p['program_id'] ?>"><?= e($p['program_code']) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="field"><label>Subject</label>
                <select name="subject_id" required>
                    <?php foreach ($activeSubjects as $s): ?><option value="<?= (int)$s['subject_id'] ?>"><?= e($s['subject_code'] . ' — ' . $s['subject_name']) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="row-2">
                <div class="field"><label>Year Level</label><input type="number" name="year_level" min="1" max="5" value="1" required></div>
                <div class="field"><label>Semester</label>
                    <select name="semester_name" required>
                        <option>1st Semester</option><option>2nd Semester</option><option>Summer</option>
                    </select>
                </div>
            </div>
            <div class="row-2">
                <div class="field"><label>Type</label>
                    <select name="subject_type"><option>Required</option><option>Elective</option></select>
                </div>
                <div class="field"><label>Effective A.Y.</label>
                    <select name="academic_year_id" required>
                        <?php foreach ($years as $y): ?><option value="<?= (int)$y['academic_year_id'] ?>"><?= e($y['year_label']) ?></option><?php endforeach; ?>
                    </select>
                </div>
            </div>
            <button class="btn btn-brass" type="submit">Add to Curriculum</button>
        </form>
    </div>
</div>

<div class="card" style="margin-top:20px;">
    <h4>All Subjects</h4>
    <table>
        <thead><tr><th>Code</th><th>Name</th><th>Units</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($subjects as $s): ?>
            <tr>
                <td><?= e($s['subject_code']) ?></td>
                <td><?= e($s['subject_name']) ?></td>
                <td><?= number_format($s['units'],1) ?></td>
                <td><span class="badge badge-<?= $s['is_active'] ? 'success' : 'neutral' ?>"><?= $s['is_active'] ? 'Active' : 'Inactive' ?></span></td>
                <td style="display:flex; gap:6px;">
                    <form method="POST"><?= csrfField() ?>
                        <input type="hidden" name="action" value="deactivate_subject">
                        <input type="hidden" name="subject_id" value="<?= (int)$s['subject_id'] ?>">
                        <button class="btn btn-outline btn-sm" data-confirm="<?= $s['is_active'] ? 'Deactivate' : 'Reactivate' ?> <?= e($s['subject_code']) ?>?"><?= $s['is_active'] ? 'Deactivate' : 'Reactivate' ?></button>
                    </form>
                    <form method="POST"><?= csrfField() ?>
                        <input type="hidden" name="action" value="delete_subject">
                        <input type="hidden" name="subject_id" value="<?= (int)$s['subject_id'] ?>">
                        <button class="btn btn-danger btn-sm" data-confirm="Permanently delete <?= e($s['subject_code']) ?>? This can't be undone, and only works if the subject isn't already used anywhere.">Delete</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$subjects): ?><tr><td colspan="5" class="helper-text">No subjects yet.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>

<div class="card" style="margin-top:20px;">
    <h4>Prerequisites</h4>
    <p class="helper-text" style="margin-bottom:14px;">
        Add a prerequisite requirement for a subject. For example, if "IT201" requires "IT101", students must pass "IT101" before enrolling in "IT201".
    </p>
    <form method="POST" class="grid grid-3" style="align-items:end; margin-bottom:18px;">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="add_prerequisite">
        <div class="field"><label>Subject</label>
            <select name="subject_id" required>
                <?php foreach ($activeSubjects as $s): ?><option value="<?= (int)$s['subject_id'] ?>"><?= e($s['subject_code'] . ' — ' . $s['subject_name']) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="field"><label>Requires (prerequisite)</label>
            <select name="prerequisite_subject_id" required>
                <?php foreach ($activeSubjects as $s): ?><option value="<?= (int)$s['subject_id'] ?>"><?= e($s['subject_code'] . ' — ' . $s['subject_name']) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="field"><label style="visibility:hidden;">&nbsp;</label>
        <button class="btn btn-primary" type="submit">Add Prerequisite</button>
        </div>
    </form>
    <table>
        <thead><tr><th>Subject</th><th>Requires</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($prerequisites as $p): ?>
            <tr>
                <td><?= e($p['subject_code'] . ' — ' . $p['subject_name']) ?></td>
                <td><?= e($p['prereq_code'] . ' — ' . $p['prereq_name']) ?></td>
                <td>
                    <form method="POST"><?= csrfField() ?>
                        <input type="hidden" name="action" value="delete_prerequisite">
                        <input type="hidden" name="prereq_id" value="<?= (int)$p['id'] ?>">
                        <button class="btn btn-danger btn-sm" data-confirm="Remove this prerequisite requirement?">Remove</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$prerequisites): ?><tr><td colspan="3" class="helper-text">No prerequisite links set yet.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>