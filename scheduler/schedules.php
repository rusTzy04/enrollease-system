<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole([ROLE_SCHEDULER, ROLE_REGISTRAR]);

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'add_schedule') {
        $subjectId = (int)$_POST['subject_id'];
        $sectionId = (int)$_POST['section_id'];
        $teacherId = $_POST['teacher_id'] !== '' ? (int)$_POST['teacher_id'] : null;
        $roomId = $_POST['room_id'] !== '' ? (int)$_POST['room_id'] : null;
        $days = implode(',', $_POST['days'] ?? []);
        $startTime = $_POST['start_time'];
        $endTime = $_POST['end_time'];

        if ($days === '') $errors[] = 'Select at least one day.';
        if ($startTime >= $endTime) $errors[] = 'Start time must be before end time.';

        // Conflict check: same teacher or same room, overlapping day+time
        if (empty($errors) && ($teacherId || $roomId)) {
            // Note: comparing a column to a NULL parameter never matches in MySQL, so we don't
            // need an extra "IS NOT NULL" guard here — passing NULL for teacherId/roomId simply
            // excludes that half of the OR naturally.
            $stmt = $pdo->prepare(
                "SELECT cs.schedule_id, cs.day_of_week, cs.start_time, cs.end_time, sub.subject_code
                 FROM class_schedules cs JOIN subjects sub ON sub.subject_id = cs.subject_id
                 WHERE cs.teacher_id = :tid OR cs.room_id = :rid"
            );
            $stmt->execute([':tid' => $teacherId, ':rid' => $roomId]);
            foreach ($stmt->fetchAll() as $existing) {
                $existingDays = explode(',', $existing['day_of_week']);
                $newDays = explode(',', $days);
                if (array_intersect($existingDays, $newDays) && $startTime < $existing['end_time'] && $existing['start_time'] < $endTime) {
                    $errors[] = "Conflict detected with {$existing['subject_code']} at the same day/time (teacher or room already booked).";
                    break;
                }
            }
        }

        if (empty($errors)) {
            $pdo->prepare(
                "INSERT INTO class_schedules (subject_id, section_id, teacher_id, room_id, day_of_week, start_time, end_time)
                 VALUES (:sub, :sec, :t, :r, :d, :st, :et)"
            )->execute([
                ':sub' => $subjectId, ':sec' => $sectionId, ':t' => $teacherId, ':r' => $roomId,
                ':d' => $days, ':st' => $startTime, ':et' => $endTime,
            ]);
            logActivity($pdo, $_SESSION['user_id'], 'schedule_created', "Subject #{$subjectId}, Section #{$sectionId}");
            setFlash('success', 'Class schedule created.');
        }
    }

    if ($action === 'cancel_schedule') {
        $pdo->prepare("UPDATE class_schedules SET status = 'Cancelled' WHERE schedule_id = :id")
            ->execute([':id' => (int)$_POST['schedule_id']]);
        setFlash('success', 'Schedule cancelled.');
    }

    if ($action === 'assign_teacher') {
        $scheduleId = (int)$_POST['schedule_id'];
        $teacherId = $_POST['teacher_id'] !== '' ? (int)$_POST['teacher_id'] : null;

        // Conflict check: don't double-book a teacher into overlapping day/time slots
        if ($teacherId) {
            $current = $pdo->prepare("SELECT day_of_week, start_time, end_time FROM class_schedules WHERE schedule_id = :id");
            $current->execute([':id' => $scheduleId]);
            $thisSched = $current->fetch();

            if ($thisSched) {
                $conflict = $pdo->prepare(
                    "SELECT cs.day_of_week, cs.start_time, cs.end_time, sub.subject_code
                     FROM class_schedules cs JOIN subjects sub ON sub.subject_id = cs.subject_id
                     WHERE cs.teacher_id = :tid AND cs.schedule_id != :sid AND cs.status = 'Open'"
                );
                $conflict->execute([':tid' => $teacherId, ':sid' => $scheduleId]);
                $theseDays = explode(',', $thisSched['day_of_week']);
                foreach ($conflict->fetchAll() as $existing) {
                    $existingDays = explode(',', $existing['day_of_week']);
                    if (array_intersect($existingDays, $theseDays)
                        && $thisSched['start_time'] < $existing['end_time']
                        && $existing['start_time'] < $thisSched['end_time']) {
                        $errors[] = "That teacher is already booked for {$existing['subject_code']} at an overlapping day/time.";
                        break;
                    }
                }
            }
        }

        if (empty($errors)) {
            $pdo->prepare("UPDATE class_schedules SET teacher_id = :tid WHERE schedule_id = :id")
                ->execute([':tid' => $teacherId, ':id' => $scheduleId]);
            logActivity($pdo, $_SESSION['user_id'], 'teacher_assigned', "Schedule #{$scheduleId} -> Teacher #{$teacherId}");
            setFlash('success', 'Teacher updated.');
        }
    }

    if (empty($errors)) redirect('/scheduler/schedules');
}

$subjects = $pdo->query("SELECT * FROM subjects WHERE is_active = 1 ORDER BY subject_code")->fetchAll();
$sections = $pdo->query("SELECT section_id, section_name FROM sections ORDER BY section_name")->fetchAll();
$teachers = $pdo->query("SELECT user_id, first_name, last_name FROM users WHERE role_id = " . ROLE_TEACHER . " ORDER BY last_name")->fetchAll();
$rooms = $pdo->query("SELECT * FROM rooms ORDER BY room_name")->fetchAll();

$schedules = $pdo->query(
    "SELECT cs.*, sub.subject_code, sec.section_name, CONCAT(t.first_name,' ',t.last_name) AS teacher_name, r.room_name
     FROM class_schedules cs
     JOIN subjects sub ON sub.subject_id = cs.subject_id
     JOIN sections sec ON sec.section_id = cs.section_id
     LEFT JOIN users t ON t.user_id = cs.teacher_id
     LEFT JOIN rooms r ON r.room_id = cs.room_id
     ORDER BY cs.schedule_id DESC"
)->fetchAll();

$pageTitle = 'Class Schedules';
require_once __DIR__ . '/../includes/header.php';
?>

<?php foreach ($errors as $err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endforeach; ?>

<?php if ($_SESSION['role_name'] === 'academic_scheduler'): ?>
<div class="card">
    <h4>Create Class Schedule</h4>
    <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="add_schedule">
        <div class="row-2">
            <div class="field"><label>Subject</label>
                <select name="subject_id" required><?php foreach ($subjects as $s): ?><option value="<?= (int)$s['subject_id'] ?>"><?= e($s['subject_code']) ?></option><?php endforeach; ?></select>
            </div>
            <div class="field"><label>Section</label>
                <select name="section_id" required><?php foreach ($sections as $s): ?><option value="<?= (int)$s['section_id'] ?>"><?= e($s['section_name']) ?></option><?php endforeach; ?></select>
            </div>
        </div>
        <div class="row-2">
            <div class="field"><label>Teacher</label>
                <select name="teacher_id"><option value="">— TBA —</option><?php foreach ($teachers as $t): ?><option value="<?= (int)$t['user_id'] ?>"><?= e($t['first_name'] . ' ' . $t['last_name']) ?></option><?php endforeach; ?></select>
            </div>
            <div class="field"><label>Room</label>
                <select name="room_id"><option value="">— TBA —</option><?php foreach ($rooms as $r): ?><option value="<?= (int)$r['room_id'] ?>"><?= e($r['room_name']) ?></option><?php endforeach; ?></select>
            </div>
        </div>
        <div class="field">
            <label>Days</label>
            <div style="display:flex; gap:14px; flex-wrap:wrap;">
                <?php foreach (['Mon','Tue','Wed','Thu','Fri','Sat'] as $d): ?>
                    <label style="font-weight:400;"><input type="checkbox" name="days[]" value="<?= $d ?>" style="width:auto;"> <?= $d ?></label>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="row-2">
            <div class="field"><label>Start Time</label><input type="time" name="start_time" required></div>
            <div class="field"><label>End Time</label><input type="time" name="end_time" required></div>
        </div>
        <button class="btn btn-primary" type="submit">Create Schedule</button>
    </form>
</div>
<?php endif; ?>

<div class="card" style="margin-top:20px;">
    <h4>All Schedules</h4>
    <p class="helper-text">Assign or change a subject's teacher any time — useful once Admin has added the real teacher's account and it's no longer "TBA."</p>
    <table>
        <thead><tr><th>Subject</th><th>Section</th><th>Teacher</th><th>Room</th><th>Days</th><th>Time</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($schedules as $s): ?>
            <tr>
                <td><?= e($s['subject_code']) ?></td>
                <td><?= e($s['section_name']) ?></td>
                <td>
                    <form method="POST" style="display:flex; gap:6px;">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="assign_teacher">
                        <input type="hidden" name="schedule_id" value="<?= (int)$s['schedule_id'] ?>">
                        <select name="teacher_id" style="width:auto; min-width:140px;">
                            <option value="">— TBA —</option>
                            <?php foreach ($teachers as $t): ?>
                                <option value="<?= (int)$t['user_id'] ?>" <?= (int)$s['teacher_id'] === (int)$t['user_id'] ? 'selected' : '' ?>><?= e($t['first_name'] . ' ' . $t['last_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="btn btn-outline btn-sm">Save</button>
                    </form>
                </td>
                <td><?= e($s['room_name'] ?? 'TBA') ?></td>
                <td><?= e(str_replace(',', '/', $s['day_of_week'])) ?></td>
                <td><?= date('g:iA', strtotime($s['start_time'])) ?>–<?= date('g:iA', strtotime($s['end_time'])) ?></td>
                <td><span class="badge badge-<?= $s['status'] === 'Open' ? 'success' : ($s['status'] === 'Cancelled' ? 'danger' : 'neutral') ?>"><?= e($s['status']) ?></span></td>
                <td>
                    <?php if ($s['status'] === 'Open' && $_SESSION['role_name'] === 'academic_scheduler'): ?>
                    <form method="POST"><?= csrfField() ?>
                        <input type="hidden" name="action" value="cancel_schedule">
                        <input type="hidden" name="schedule_id" value="<?= (int)$s['schedule_id'] ?>">
                        <button class="btn btn-danger btn-sm" data-confirm="Cancel this class schedule?">Cancel</button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>