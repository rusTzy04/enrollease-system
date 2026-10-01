<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole([ROLE_REGISTRAR]);

$search = trim($_GET['q'] ?? '');
$params = [':status' => 'Enrolled'];
$where = "WHERE e.status = :status";
if ($search !== '') {
    $where .= " AND (u.first_name LIKE :q1 OR u.last_name LIKE :q2 OR u.student_id LIKE :q3)";
    $like = "%{$search}%";
    $params[':q1'] = $like;
    $params[':q2'] = $like;
    $params[':q3'] = $like;
}

$stmt = $pdo->prepare(
    "SELECT e.enrollment_id, u.student_id, u.first_name, u.last_name, e.approved_at
     FROM enrollments e JOIN users u ON u.user_id = e.student_id {$where} ORDER BY e.approved_at DESC LIMIT 100"
);
$stmt->execute($params);
$forms = $stmt->fetchAll();

$pageTitle = 'Registration Forms';
require_once __DIR__ . '/../includes/header.php';
?>
<form method="GET" class="toolbar">
    <input type="text" name="q" placeholder="Search name or student ID..." value="<?= e($search) ?>">
    <button type="submit" class="btn btn-outline btn-sm">Search</button>
</form>
<div class="card">
    <?php if ($forms): ?>
        <table>
            <thead>
                <tr>
                    <th>Student ID</th>
                    <th>Name</th>
                    <th>Approved</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($forms as $f): ?>
                    <tr>
                        <td><?= e($f['student_id']) ?></td>
                        <td><?= e($f['first_name'] . ' ' . $f['last_name']) ?></td>
                        <td><?= $f['approved_at'] ? date('M j, Y', strtotime($f['approved_at'])) : '—' ?></td>
                        <td>
                            <a class="btn btn-outline btn-sm"
                                href="<?= BASE_URL ?>/registrar/application-detail?id=<?= (int) $f['enrollment_id'] ?>">View</a>
                            <a class="btn btn-brass btn-sm"
                                href="<?= BASE_URL ?>/registrar/print-registration-form?enrollment_id=<?= (int) $f['enrollment_id'] ?>"
                                target="_blank">Print</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <div class="empty-state">No enrolled students yet.</div><?php endif; ?>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>