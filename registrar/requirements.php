<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole([ROLE_REGISTRAR]);

$stmt = $pdo->query(
    "SELECT er.id, er.status, rt.requirement_name, u.student_id, u.first_name, u.last_name, e.enrollment_id
     FROM enrollment_requirements er
     JOIN requirement_types rt ON rt.requirement_type_id = er.requirement_type_id
     JOIN enrollments e ON e.enrollment_id = er.enrollment_id
     JOIN users u ON u.user_id = e.student_id
     WHERE er.status != 'Verified'
     ORDER BY u.last_name"
);
$pending = $stmt->fetchAll();

$pageTitle = 'Requirements Queue';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="card">
    <?php if ($pending): ?>
    <table>
        <thead><tr><th>Student ID</th><th>Name</th><th>Requirement</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($pending as $r): ?>
            <tr>
                <td><?= e($r['student_id']) ?></td>
                <td><?= e($r['first_name'] . ' ' . $r['last_name']) ?></td>
                <td><?= e($r['requirement_name']) ?></td>
                <td><span class="badge badge-warning"><?= e($r['status']) ?></span></td>
                <td><a class="btn btn-outline btn-sm" href="<?= BASE_URL ?>/registrar/application-detail?id=<?= (int)$r['enrollment_id'] ?>">Open</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php else: ?>
        <div class="empty-state">All requirements are verified. Nothing pending.</div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>