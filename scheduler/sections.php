<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole([ROLE_SCHEDULER]);

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'add_section') {
        $name = trim($_POST['section_name'] ?? '');
        $programId = (int) $_POST['program_id'];
        $yearLevel = (int) $_POST['year_level'];
        $semesterId = (int) $_POST['semester_id'];
        $maxSlots = (int) $_POST['max_slots'];

        if ($name === '')
            $errors[] = 'Section name is required.';
        if ($maxSlots <= 0 || $maxSlots > 200)
            $errors[] = 'Max slots must be between 1 and 200.';

        // Prevent accidental duplicates: same name within the same program + semester
        if (empty($errors)) {
            $dupe = $pdo->prepare(
                "SELECT COUNT(*) FROM sections WHERE section_name = :n AND program_id = :p AND semester_id = :s"
            );
            $dupe->execute([':n' => $name, ':p' => $programId, ':s' => $semesterId]);
            if ((int) $dupe->fetchColumn() > 0) {
                $errors[] = "A section named \"{$name}\" already exists for that program and semester.";
            }
        }

        if (empty($errors)) {
            $pdo->prepare(
                "INSERT INTO sections (section_name, program_id, year_level, semester_id, max_slots) VALUES (:n, :p, :y, :s, :m)"
            )->execute([':n' => $name, ':p' => $programId, ':y' => $yearLevel, ':s' => $semesterId, ':m' => $maxSlots]);
            logActivity($pdo, $_SESSION['user_id'], 'section_created', $name);
            setFlash('success', 'Section created.');
        }
    }

    if ($action === 'delete_section') {
        $sectionId = (int) $_POST['section_id'];

        // Block deletion if any student is already enrolled in this section's classes
        $enrolled = $pdo->prepare(
            "SELECT COUNT(*) FROM enrollment_subjects es
             JOIN class_schedules cs ON cs.schedule_id = es.schedule_id
             WHERE cs.section_id = :sid"
        );
        $enrolled->execute([':sid' => $sectionId]);

        if ((int) $enrolled->fetchColumn() > 0) {
            $errors[] = 'Students are already enrolled in this section\'s classes, so it can\'t be deleted. Cancel the individual class schedules instead if you need to stop further enrollment.';
        } else {
            $pdo->beginTransaction();
            // Remove the section's class schedules first (no students are attached, verified above)
            $pdo->prepare("DELETE FROM class_schedules WHERE section_id = :sid")->execute([':sid' => $sectionId]);
            $pdo->prepare("DELETE FROM sections WHERE section_id = :sid")->execute([':sid' => $sectionId]);
            $pdo->commit();
            logActivity($pdo, $_SESSION['user_id'], 'section_deleted', "Section #{$sectionId}");
            setFlash('success', 'Section deleted.');
        }
    }

    if (empty($errors))
        redirect('/scheduler/sections');
}

$programs = $pdo->query("SELECT * FROM programs WHERE is_active = 1")->fetchAll();
$semesters = $pdo->query(
    "SELECT s.semester_id, s.semester_name, ay.year_label FROM semesters s JOIN academic_years ay ON ay.academic_year_id = s.academic_year_id ORDER BY ay.year_label DESC"
)->fetchAll();
$sections = $pdo->query(
    "SELECT sec.*, p.program_code, s.semester_name, ay.year_label,
            (SELECT COUNT(*) FROM class_schedules cs WHERE cs.section_id = sec.section_id) AS schedule_count
     FROM sections sec JOIN programs p ON p.program_id = sec.program_id
     JOIN semesters s ON s.semester_id = sec.semester_id JOIN academic_years ay ON ay.academic_year_id = s.academic_year_id
     ORDER BY sec.section_id DESC"
)->fetchAll();

$pageTitle = 'Sections';
require_once __DIR__ . '/../includes/header.php';
?>

<?php foreach ($errors as $err): ?>
    <div class="alert alert-error"><?= e($err) ?></div><?php endforeach; ?>

<div class="card">
    <h4>Create Section</h4>
    <form method="POST" class="grid grid-4" style="align-items:end;">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="add_section">
        <div class="field"><label>Section Name</label><input type="text" name="section_name" placeholder="BSIT-1A"
                required></div>
        <div class="field"><label>Program</label>
            <select name="program_id" required><?php foreach ($programs as $p): ?>
                    <option value="<?= (int) $p['program_id'] ?>"><?= e($p['program_code']) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="field"><label>Year Level</label><input type="number" name="year_level" min="1" max="5" value="1"
                required></div>
        <div class="field"><label>Semester</label>
            <select name="semester_id" required><?php foreach ($semesters as $s): ?>
                    <option value="<?= (int) $s['semester_id'] ?>"><?= e($s['year_label'] . ' ' . $s['semester_name']) ?>
                    </option><?php endforeach; ?>
            </select>
        </div>
        <div class="field"><label>Max Slots</label><input type="number" name="max_slots" min="1" max="200" value="40"
                required></div>
        <div class="field"><label style="visibility:hidden;">&nbsp;</label>
            <button class="btn btn-primary" type="submit">Create</button>
        </div>
    </form>
</div>

<div class="card" style="margin-top:20px;">
    <h4>All Sections</h4>
    <table>
        <thead>
            <tr>
                <th>Section</th>
                <th>Program</th>
                <th>Year</th>
                <th>Semester</th>
                <th>Max Slots</th>
                <th>Schedules</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($sections as $s): ?>
                <tr>
                    <td><?= e($s['section_name']) ?></td>
                    <td><?= e($s['program_code']) ?></td>
                    <td><?= (int) $s['year_level'] ?></td>
                    <td><?= e($s['year_label'] . ' ' . $s['semester_name']) ?></td>
                    <td><?= (int) $s['max_slots'] ?></td>
                    <td><?= (int) $s['schedule_count'] ?></td>
                    <td>
                        <form method="POST"><?= csrfField() ?>
                            <input type="hidden" name="action" value="delete_section">
                            <input type="hidden" name="section_id" value="<?= (int) $s['section_id'] ?>">
                            <button class="btn btn-danger btn-sm"
                                data-confirm="Delete section <?= e($s['section_name']) ?>? This also removes its <?= (int) $s['schedule_count'] ?> class schedule(s). Only works if no students are enrolled in it.">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>