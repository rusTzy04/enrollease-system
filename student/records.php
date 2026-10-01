<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole([ROLE_STUDENT]);

$studentId = $_SESSION['user_id'];

$stmt = $pdo->prepare(
    "SELECT ay.year_label, s.semester_name, sub.subject_code, sub.subject_name, sub.units, es.grade, es.remark
     FROM enrollment_subjects es
     JOIN enrollments e ON e.enrollment_id = es.enrollment_id
     JOIN semesters s ON s.semester_id = e.semester_id
     JOIN academic_years ay ON ay.academic_year_id = s.academic_year_id
     JOIN class_schedules cs ON cs.schedule_id = es.schedule_id
     JOIN subjects sub ON sub.subject_id = cs.subject_id
     WHERE e.student_id = :sid AND e.status = 'Enrolled'
     ORDER BY ay.year_label, s.semester_name"
);
$stmt->execute([':sid' => $studentId]);
$records = $stmt->fetchAll();

$totalUnitsCompleted = 0;
foreach ($records as $r) {
    if ($r['remark'] === 'Passed')
        $totalUnitsCompleted += $r['units'];
}

$pageTitle = 'Academic Records';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="grid grid-3">
    <div class="card stat-card">
        <div class="stat-label">Total Units Completed</div>
        <div class="stat-value"><?= number_format($totalUnitsCompleted, 1) ?></div>
    </div>
    <div class="card stat-card">
        <div class="stat-label">Subjects Taken</div>
        <div class="stat-value"><?= count($records) ?></div>
    </div>
    <div class="card stat-card">
        <div class="stat-label">Failed / Withdrawn</div>
        <div class="stat-value">
            <?= count(array_filter($records, fn($r) => in_array($r['remark'], ['Failed', 'Withdrawn']))) ?></div>
    </div>
</div>

<div class="card" style="margin-top:20px;">
    <h4>Enrollment History</h4>
    <?php if ($records): ?>
        <table>
            <thead>
                <tr>
                    <th>A.Y.</th>
                    <th>Semester</th>
                    <th>Code</th>
                    <th>Subject</th>
                    <th>Units</th>
                    <th>Grade</th>
                    <th>Remark</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($records as $r): ?>
                    <tr>
                        <td><?= e($r['year_label']) ?></td>
                        <td><?= e($r['semester_name']) ?></td>
                        <td><?= e($r['subject_code']) ?></td>
                        <td><?= e($r['subject_name']) ?></td>
                        <td><?= number_format($r['units'], 1) ?></td>
                        <td><?= e($r['grade'] ?? '—') ?></td>
                        <td>
                            <?php $rc = ['Passed' => 'success', 'Failed' => 'danger', 'Withdrawn' => 'warning', 'Incomplete' => 'warning', 'In Progress' => 'info']; ?>
                            <span class="badge badge-<?= $rc[$r['remark']] ?? 'neutral' ?>"><?= e($r['remark']) ?></span>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <div class="empty-state">No academic records yet.</div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>