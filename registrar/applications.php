<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole([ROLE_REGISTRAR]);

// ---- Filters (Feature 21: search, filter, sort) ----
$search = trim($_GET['q'] ?? '');
$statusFilter = $_GET['status'] ?? '';
$sort = ($_GET['sort'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 15;
$offset = ($page - 1) * $perPage;

$where = [];
$params = [];
if ($search !== '') {
    $where[] = "(u.first_name LIKE :q1 OR u.last_name LIKE :q2 OR u.student_id LIKE :q3)";
    $like = "%{$search}%";
    $params[':q1'] = $like;
    $params[':q2'] = $like;
    $params[':q3'] = $like;
}
if ($statusFilter !== '') {
    $where[] = "e.status = :status";
    $params[':status'] = $statusFilter;
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM enrollments e JOIN users u ON u.user_id = e.student_id {$whereSql}");
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();

$stmt = $pdo->prepare(
    "SELECT e.enrollment_id, e.status, e.submitted_at, e.total_amount_due, e.total_paid,
            u.student_id, u.first_name, u.last_name
     FROM enrollments e JOIN users u ON u.user_id = e.student_id
     {$whereSql}
     ORDER BY e.submitted_at {$sort}
     LIMIT {$perPage} OFFSET {$offset}"
);
$stmt->execute($params);
$applications = $stmt->fetchAll();

$statusBadge = [
    'Draft' => 'neutral',
    'Submitted' => 'info',
    'Requirements Pending' => 'warning',
    'Requirements Verified' => 'info',
    'Payment Pending' => 'warning',
    'Payment Verified' => 'info',
    'Enrolled' => 'success',
    'Rejected' => 'danger',
    'Cancelled' => 'danger',
];

$pageTitle = 'Enrollment Applications';
require_once __DIR__ . '/../includes/header.php';
?>

<form method="GET" class="toolbar">
    <input type="text" name="q" placeholder="Search name or student ID..." value="<?= e($search) ?>">
    <select name="status">
        <option value="">All statuses</option>
        <?php foreach (['Submitted', 'Requirements Pending', 'Requirements Verified', 'Payment Pending', 'Payment Verified', 'Enrolled', 'Rejected', 'Cancelled'] as $s): ?>
            <option value="<?= e($s) ?>" <?= $statusFilter === $s ? 'selected' : '' ?>><?= e($s) ?></option>
        <?php endforeach; ?>
    </select>
    <select name="sort">
        <option value="desc" <?= $sort === 'DESC' ? 'selected' : '' ?>>Newest first</option>
        <option value="asc" <?= $sort === 'ASC' ? 'selected' : '' ?>>Oldest first</option>
    </select>
    <button type="submit" class="btn btn-outline btn-sm">Filter</button>
</form>

<div class="card">
    <?php if ($applications): ?>
        <table>
            <thead>
                <tr>
                    <th>Student ID</th>
                    <th>Name</th>
                    <th>Status</th>
                    <th>Submitted</th>
                    <th>Balance</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($applications as $a):
                    $bal = max(0, $a['total_amount_due'] - $a['total_paid']); ?>
                    <tr>
                        <td><?= e($a['student_id']) ?></td>
                        <td><?= e($a['first_name'] . ' ' . $a['last_name']) ?></td>
                        <td><span
                                class="badge badge-<?= $statusBadge[$a['status']] ?? 'neutral' ?>"><?= e($a['status']) ?></span>
                        </td>
                        <td><?= $a['submitted_at'] ? date('M j, Y', strtotime($a['submitted_at'])) : '—' ?></td>
                        <td>&#8369;<?= number_format($bal, 2) ?></td>
                        <td><a class="btn btn-outline btn-sm"
                                href="<?= BASE_URL ?>/registrar/application-detail?id=<?= (int) $a['enrollment_id'] ?>">Review</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <div class="pagination">
            <?php for ($p = 1; $p <= ceil($total / $perPage); $p++): ?>
                <a href="?<?= http_build_query(array_merge($_GET, ['page' => $p])) ?>"
                    class="<?= $p === $page ? 'current' : '' ?>"><?= $p ?></a>
            <?php endfor; ?>
        </div>
    <?php else: ?>
        <div class="empty-state">No applications match your filters.</div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>