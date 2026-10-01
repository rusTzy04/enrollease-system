<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole([ROLE_SCHEDULER, ROLE_ADMIN]);

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'add_year') {
        $label = trim($_POST['year_label'] ?? '');
        if (!preg_match('/^\d{4}-\d{4}$/', $label)) {
            $errors[] = 'Academic year must be in the format 2026-2027.';
        } else {
            try {
                $pdo->prepare("INSERT INTO academic_years (year_label) VALUES (:l)")->execute([':l' => $label]);
                setFlash('success', 'Academic year added.');
            } catch (PDOException $e) {
                $errors[] = 'That academic year already exists.';
            }
        }
    }

    if ($action === 'add_semester') {
        $pdo->prepare(
            "INSERT INTO semesters (academic_year_id, semester_name, enrollment_start, enrollment_end) VALUES (:ay, :sem, :start, :end)"
        )->execute([
                    ':ay' => (int) $_POST['academic_year_id'],
                    ':sem' => $_POST['semester_name'],
                    ':start' => $_POST['enrollment_start'] ?: null,
                    ':end' => $_POST['enrollment_end'] ?: null,
                ]);
        setFlash('success', 'Semester created.');
    }

    if ($action === 'set_active_semester') {
        $semId = (int) $_POST['semester_id'];
        $pdo->exec("UPDATE semesters SET is_active = 0"); // only one active semester system-wide
        $pdo->prepare("UPDATE semesters SET is_active = 1 WHERE semester_id = :id")->execute([':id' => $semId]);
        logActivity($pdo, $_SESSION['user_id'], 'semester_activated', "Semester #{$semId}");
        setFlash('success', 'Active semester updated. Enrollment now applies to this semester.');
    }

    if ($action === 'update_window') {
        $semId = (int) $_POST['semester_id'];
        $start = $_POST['enrollment_start'] ?: null;
        $end = $_POST['enrollment_end'] ?: null;
        if ($start && $end && $start > $end) {
            $errors[] = 'Enrollment start date must be before the end date.';
        } else {
            $pdo->prepare("UPDATE semesters SET enrollment_start = :s, enrollment_end = :e WHERE semester_id = :id")
                ->execute([':s' => $start, ':e' => $end, ':id' => $semId]);
            logActivity($pdo, $_SESSION['user_id'], 'enrollment_window_updated', "Semester #{$semId}");
            setFlash('success', 'Enrollment window updated.');
        }
    }

    if (empty($errors))
        redirect('/scheduler/academic-year');
}

$years = $pdo->query("SELECT * FROM academic_years ORDER BY year_label DESC")->fetchAll();
$semesters = $pdo->query(
    "SELECT s.*, ay.year_label FROM semesters s JOIN academic_years ay ON ay.academic_year_id = s.academic_year_id ORDER BY ay.year_label DESC"
)->fetchAll();

$pageTitle = 'Academic Year & Semester';
require_once __DIR__ . '/../includes/header.php';
?>

<?php foreach ($errors as $err): ?>
    <div class="alert alert-error"><?= e($err) ?></div><?php endforeach; ?>

<div class="grid grid-2">
    <div class="card">
        <h4>Add Academic Year</h4>
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="add_year">
            <div class="field"><label>Year Label</label><input type="text" name="year_label" placeholder="2027-2028"
                    required></div>
            <button class="btn btn-primary" type="submit">Add Year</button>
        </form>
    </div>
    <div class="card">
        <h4>Add Semester</h4>
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="add_semester">
            <div class="field"><label>Academic Year</label>
                <select name="academic_year_id" required>
                    <?php foreach ($years as $y): ?>
                        <option value="<?= (int) $y['academic_year_id'] ?>"><?= e($y['year_label']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field"><label>Semester</label>
                <select name="semester_name">
                    <option>1st Semester</option>
                    <option>2nd Semester</option>
                    <option>Summer</option>
                </select>
            </div>
            <div class="row-2">
                <div class="field"><label>Enrollment Start</label><input type="date" name="enrollment_start"></div>
                <div class="field"><label>Enrollment End</label><input type="date" name="enrollment_end"></div>
            </div>
            <button class="btn btn-brass" type="submit">Create Semester</button>
        </form>
    </div>
</div>

<div class="card" style="margin-top:20px;">
    <h4>All Semesters</h4>
    <p class="helper-text">Students can only enroll while "today" falls between Enrollment Start and Enrollment End
        below. Leave a date blank for "no limit" on that side. Update and Save to fix a window that's expired or was
        never set.</p>
    <table>
        <thead>
            <tr>
                <th>A.Y.</th>
                <th>Semester</th>
                <th>Enrollment Start</th>
                <th>Enrollment End</th>
                <th>Active</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($semesters as $s):
                $fid = 'sem-form-' . (int) $s['semester_id']; ?>
                <tr>
                    <td><?= e($s['year_label']) ?></td>
                    <td><?= e($s['semester_name']) ?></td>
                    <td><input form="<?= $fid ?>" type="date" name="enrollment_start"
                            value="<?= $s['enrollment_start'] ? date('Y-m-d', strtotime($s['enrollment_start'])) : '' ?>"
                            style="min-width:150px;"></td>
                    <td><input form="<?= $fid ?>" type="date" name="enrollment_end"
                            value="<?= $s['enrollment_end'] ? date('Y-m-d', strtotime($s['enrollment_end'])) : '' ?>"
                            style="min-width:150px;"></td>
                    <td><?= $s['is_active'] ? '<span class="badge badge-success">Active</span>' : '' ?></td>
                    <td style="display:flex; gap:6px;">
                        <!-- This empty form owns the two date inputs above via the form="" attribute (they live in <td>s, not nested inside <form>, which keeps the table valid HTML) -->
                        <form id="<?= $fid ?>" method="POST" style="display:contents;">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="update_window">
                            <input type="hidden" name="semester_id" value="<?= (int) $s['semester_id'] ?>">
                            <button type="submit" class="btn btn-outline btn-sm">Save</button>
                        </form>
                        <?php if (!$s['is_active']): ?>
                            <form method="POST" style="display:contents;"><?= csrfField() ?>
                                <input type="hidden" name="action" value="set_active_semester">
                                <input type="hidden" name="semester_id" value="<?= (int) $s['semester_id'] ?>">
                                <button type="submit" class="btn btn-brass btn-sm"
                                    data-confirm="Set this as the active enrollment semester?">Set Active</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>