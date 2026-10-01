<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole([ROLE_TEACHER]);

$teacherId = $_SESSION['user_id'];

$stmt = $pdo->prepare(
    "SELECT cs.schedule_id, sub.subject_code, sub.subject_name, sec.section_name, cs.day_of_week, cs.start_time, cs.end_time,
            (SELECT COUNT(*) FROM enrollment_subjects es WHERE es.schedule_id = cs.schedule_id) AS enrolled_count
     FROM class_schedules cs
     JOIN subjects sub ON sub.subject_id = cs.subject_id
     JOIN sections sec ON sec.section_id = cs.section_id
     WHERE cs.teacher_id = :tid AND cs.status = 'Open'
     ORDER BY sub.subject_code"
);
$stmt->execute([':tid' => $teacherId]);
$classes = $stmt->fetchAll();

$pageTitle = 'Teacher Dashboard';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="grid grid-3">
    <div class="card stat-card">
        <div class="stat-label">Assigned Classes</div>
        <div class="stat-value"><?= count($classes) ?></div>
    </div>
    <div class="card stat-card">
        <div class="stat-label">Total Students</div>
        <div class="stat-value"><?= array_sum(array_column($classes, 'enrolled_count')) ?></div>
    </div>
</div>

<div class="card" style="margin-top:20px;">
    <h4>My Classes</h4>
    <?php if ($classes): ?>
        <table>
            <thead>
                <tr>
                    <th>Code</th>
                    <th>Subject</th>
                    <th>Section</th>
                    <th>Schedule</th>
                    <th>Students</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($classes as $c): ?>
                    <tr>
                        <td><?= e($c['subject_code']) ?></td>
                        <td><?= e($c['subject_name']) ?></td>
                        <td><?= e($c['section_name']) ?></td>
                        <td><?= e(str_replace(',', '/', $c['day_of_week'])) ?>
                            <?= date('g:iA', strtotime($c['start_time'])) ?>–<?= date('g:iA', strtotime($c['end_time'])) ?></td>
                        <td><?= (int) $c['enrolled_count'] ?></td>
                        <td><a class="btn btn-outline btn-sm"
                                href="<?= BASE_URL ?>/teacher/grades?schedule_id=<?= (int) $c['schedule_id'] ?>">Manage
                                Grades</a></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <div class="empty-state">No classes assigned yet.</div><?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>