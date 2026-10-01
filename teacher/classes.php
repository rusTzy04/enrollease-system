<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole([ROLE_TEACHER]);

$teacherId = $_SESSION['user_id'];

$stmt = $pdo->prepare(
    "SELECT cs.schedule_id, sub.subject_code, sub.subject_name, sec.section_name
     FROM class_schedules cs JOIN subjects sub ON sub.subject_id = cs.subject_id
     JOIN sections sec ON sec.section_id = cs.section_id
     WHERE cs.teacher_id = :tid ORDER BY sub.subject_code"
);
$stmt->execute([':tid' => $teacherId]);
$classes = $stmt->fetchAll();

$pageTitle = 'My Sections';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="grid grid-2">
    <?php foreach ($classes as $c):
        $stmt2 = $pdo->prepare(
            "SELECT u.student_id, u.first_name, u.last_name
         FROM enrollment_subjects es JOIN enrollments e ON e.enrollment_id = es.enrollment_id
         JOIN users u ON u.user_id = e.student_id
         WHERE es.schedule_id = :sid ORDER BY u.last_name"
        );
        $stmt2->execute([':sid' => $c['schedule_id']]);
        $students = $stmt2->fetchAll();
        ?>
        <div class="card">
            <h4><?= e($c['subject_code'] . ' — ' . $c['subject_name']) ?></h4>
            <p class="helper-text">Section: <?= e($c['section_name']) ?> · <?= count($students) ?> student(s)</p>
            <table>
                <thead>
                    <tr>
                        <th>Student ID</th>
                        <th>Name</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($students as $s): ?>
                        <tr>
                            <td><?= e($s['student_id']) ?></td>
                            <td><?= e($s['first_name'] . ' ' . $s['last_name']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$students): ?>
                        <tr>
                            <td colspan="2" class="helper-text">No students enrolled yet.</td>
                        </tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    <?php endforeach; ?>
    <?php if (!$classes): ?>
        <div class="card empty-state">No classes assigned.</div><?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>