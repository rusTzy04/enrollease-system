<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole([ROLE_TEACHER]);

$teacherId = $_SESSION['user_id'];
$scheduleId = (int)($_GET['schedule_id'] ?? 0);

// ---------------------------------------------------------------
// No class chosen yet (e.g. clicking "Grades" in the sidebar):
// show the teacher's classes so they can pick one.
// ---------------------------------------------------------------
if ($scheduleId === 0) {
    $stmt = $pdo->prepare(
        "SELECT cs.schedule_id, sub.subject_code, sub.subject_name, sec.section_name,
                cs.day_of_week, cs.start_time, cs.end_time, s.semester_name, ay.year_label,
                (SELECT COUNT(*) FROM enrollment_subjects es WHERE es.schedule_id = cs.schedule_id) AS enrolled_count
         FROM class_schedules cs
         JOIN subjects sub ON sub.subject_id = cs.subject_id
         JOIN sections sec ON sec.section_id = cs.section_id
         JOIN semesters s ON s.semester_id = sec.semester_id
         JOIN academic_years ay ON ay.academic_year_id = s.academic_year_id
         WHERE cs.teacher_id = :tid AND cs.status = 'Open'
         ORDER BY ay.year_label DESC, s.semester_name, sub.subject_code"
    );
    $stmt->execute([':tid' => $teacherId]);
    $myClasses = $stmt->fetchAll();

    $pageTitle = 'Grades';
    require_once __DIR__ . '/../includes/header.php';
    ?>
    <div class="card">
        <h4>Choose a class to grade</h4>
        <?php if ($myClasses): ?>
        <table>
            <thead><tr><th>Code</th><th>Subject</th><th>Section</th><th>Term</th><th>Schedule</th><th>Students</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($myClasses as $c): ?>
                <tr>
                    <td><?= e($c['subject_code']) ?></td>
                    <td><?= e($c['subject_name']) ?></td>
                    <td><?= e($c['section_name']) ?></td>
                    <td><?= e($c['year_label'] . ' — ' . $c['semester_name']) ?></td>
                    <td><?= e(str_replace(',', '/', $c['day_of_week'])) ?> <?= date('g:iA', strtotime($c['start_time'])) ?>–<?= date('g:iA', strtotime($c['end_time'])) ?></td>
                    <td><?= (int)$c['enrolled_count'] ?></td>
                    <td><a class="btn btn-outline btn-sm" href="<?= BASE_URL ?>/teacher/grades?schedule_id=<?= (int)$c['schedule_id'] ?>">Manage Grades</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
            <div class="empty-state">No classes are assigned to you yet. Once the Registrar or Academic Scheduler assigns you to a class, it will appear here.</div>
        <?php endif; ?>
    </div>
    <?php
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

// ---------------------------------------------------------------
// A class was chosen. Ownership check: it must belong to THIS teacher
// (prevents grade tampering by editing the URL).
// ---------------------------------------------------------------
$stmt = $pdo->prepare(
    "SELECT cs.*, sub.subject_code, sub.subject_name, sec.section_name
     FROM class_schedules cs JOIN subjects sub ON sub.subject_id = cs.subject_id JOIN sections sec ON sec.section_id = cs.section_id
     WHERE cs.schedule_id = :id AND cs.teacher_id = :tid"
);
$stmt->execute([':id' => $scheduleId, ':tid' => $teacherId]);
$class = $stmt->fetch();

if (!$class) {
    setFlash('error', 'That class was not found, or it is not assigned to you.');
    redirect('/teacher/grades');
}

// Students enrolled in this class
$stmt = $pdo->prepare(
    "SELECT u.user_id, u.student_id AS student_number, u.first_name, u.last_name, es.grade, es.remark
     FROM enrollment_subjects es JOIN enrollments e ON e.enrollment_id = es.enrollment_id
     JOIN users u ON u.user_id = e.student_id
     WHERE es.schedule_id = :sid ORDER BY u.last_name, u.first_name"
);
$stmt->execute([':sid' => $scheduleId]);
$students = $stmt->fetchAll();

$errors = [];
$posted = null; // holds what the teacher typed, so it isn't lost if validation fails

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $postedGrades = $_POST['grade'] ?? [];
    $postedRemarks = $_POST['remark'] ?? [];
    $posted = ['grade' => $postedGrades, 'remark' => $postedRemarks];

    $allowedRemarks = ['Passed', 'Failed', 'Withdrawn', 'Incomplete', 'In Progress'];
    $toSave = [];

    // Only students actually enrolled in this class are considered (extra/forged IDs in the POST are ignored)
    foreach ($students as $s) {
        $uid = (int)$s['user_id'];
        $name = $s['first_name'] . ' ' . $s['last_name'];
        $raw = trim((string)($postedGrades[$uid] ?? ''));
        $remark = $postedRemarks[$uid] ?? 'In Progress';

        if (!in_array($remark, $allowedRemarks, true)) {
            $errors[] = "{$name}: invalid remark selected.";
            continue;
        }

        $grade = null;
        if ($raw !== '') {
            // Plain number only: up to 3 digits, optional 1-2 decimals (rejects "1e2", "-5", "abc", etc.)
            if (!preg_match('/^\d{1,3}(\.\d{1,2})?$/', $raw) || (float)$raw > 100) {
                $errors[] = "{$name}: grade must be a number from 0 to 100.";
                continue;
            }
            // Normalize, e.g. "85.50" -> "85.5", "100.00" -> "100" (also keeps it within the 5-char column)
            $grade = rtrim(rtrim(number_format((float)$raw, 2, '.', ''), '0'), '.');
        }

        $toSave[$uid] = ['grade' => $grade, 'remark' => $remark];
    }

    // All-or-nothing: if anything is invalid, nothing is saved.
    if (empty($errors)) {
        $upd = $pdo->prepare(
            "UPDATE enrollment_subjects es
             JOIN enrollments e ON e.enrollment_id = es.enrollment_id
             SET es.grade = :grade, es.remark = :remark
             WHERE es.schedule_id = :sid AND e.student_id = :student_id"
        );
        foreach ($toSave as $uid => $row) {
            $upd->execute([
                ':grade' => $row['grade'],
                ':remark' => $row['remark'],
                ':sid' => $scheduleId,
                ':student_id' => $uid,
            ]);
        }
        logActivity($pdo, $teacherId, 'grades_updated', "Schedule #{$scheduleId}");
        setFlash('success', 'Grades saved.');
        redirect('/teacher/grades?schedule_id=' . $scheduleId);
    }
}

$pageTitle = 'Grades: ' . $class['subject_code'];
require_once __DIR__ . '/../includes/header.php';
?>

<?php foreach ($errors as $err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endforeach; ?>

<div class="card">
    <div class="section-head">
        <h4 style="margin:0;"><?= e($class['subject_code'] . ' — ' . $class['subject_name']) ?> (<?= e($class['section_name']) ?>)</h4>
        <a class="btn btn-outline btn-sm" href="<?= BASE_URL ?>/teacher/grades">&larr; All classes</a>
    </div>
    <?php if ($students): ?>
    <form method="POST">
        <?= csrfField() ?>
        <table>
            <thead><tr><th>Student ID</th><th>Name</th><th>Grade (0–100)</th><th>Remark</th></tr></thead>
            <tbody>
            <?php foreach ($students as $s):
                $uid = (int)$s['user_id'];
                $gradeValue = $posted !== null ? (string)($posted['grade'][$uid] ?? '') : (string)($s['grade'] ?? '');
                $remarkValue = $posted !== null ? ($posted['remark'][$uid] ?? $s['remark']) : $s['remark'];
            ?>
                <tr>
                    <td><?= e($s['student_number']) ?></td>
                    <td><?= e($s['first_name'] . ' ' . $s['last_name']) ?></td>
                    <td><input type="number" class="grade-input" name="grade[<?= $uid ?>]" value="<?= e($gradeValue) ?>" min="0" max="100" step="0.01" inputmode="decimal" placeholder="0–100" style="width:110px;"></td>
                    <td>
                        <select name="remark[<?= $uid ?>]">
                            <?php foreach (['In Progress', 'Passed', 'Failed', 'Withdrawn', 'Incomplete'] as $r): ?>
                                <option value="<?= $r ?>" <?= $remarkValue === $r ? 'selected' : '' ?>><?= $r ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <div class="field-error" id="grade-error" style="margin-top:14px;"></div>
        <button class="btn btn-primary" id="save-grades-btn" type="submit" style="margin-top:14px; width:auto; padding:11px 24px;">Save Grades</button>
    </form>
    <?php else: ?><div class="empty-state">No students enrolled in this class yet.</div><?php endif; ?>
</div>

<script>
// Live check: flag any grade outside 0–100 immediately and block saving until it's fixed.
(function () {
    const inputs = document.querySelectorAll('.grade-input');
    const box = document.getElementById('grade-error');
    const btn = document.getElementById('save-grades-btn');
    if (!inputs.length || !box || !btn) return;

    function check() {
        let bad = 0;
        const pattern = /^\d{1,3}(\.\d{1,2})?$/;
        inputs.forEach(function (input) {
            const v = input.value.trim();
            const invalid = v !== '' && (!pattern.test(v) || Number(v) > 100);
            input.classList.toggle('input-invalid', invalid);
            if (invalid) bad++;
        });
        box.textContent = bad ? 'Grades must be a number from 0 to 100 (up to 2 decimal places). Please correct the highlighted field(s).' : '';
        box.classList.toggle('show', bad > 0);
        btn.disabled = bad > 0;
    }

    inputs.forEach(function (input) { input.addEventListener('input', check); });
    check();
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>