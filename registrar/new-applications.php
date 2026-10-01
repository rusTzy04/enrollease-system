<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole([ROLE_REGISTRAR]);

$search = trim($_GET['q'] ?? '');
$statusFilter = $_GET['status'] ?? '';
$where = [];
$params = [];
if ($search !== '') {
    $where[] = "(a.first_name LIKE :q1 OR a.last_name LIKE :q2 OR a.reference_no LIKE :q3 OR a.email LIKE :q4)";
    $like = "%{$search}%";
    $params[':q1'] = $like;
    $params[':q2'] = $like;
    $params[':q3'] = $like;
    $params[':q4'] = $like;
}
if ($statusFilter !== '') {
    $where[] = "a.status = :status";
    $params[':status'] = $statusFilter;
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = $pdo->prepare(
    "SELECT a.application_id, a.reference_no, a.first_name, a.last_name, a.status, a.total_paid, a.submitted_at, p.program_code
     FROM enrollment_applications a JOIN programs p ON p.program_id = a.program_id
     {$whereSql}
     ORDER BY a.submitted_at DESC LIMIT 100"
);
$stmt->execute($params);
$applications = $stmt->fetchAll();

$statusBadge = [
    'Requirements Pending' => 'warning',
    'Requirements Verified' => 'info',
    'Payment Pending' => 'warning',
    'Payment Verified' => 'info',
    'Approved' => 'success',
    'Rejected' => 'danger',
];

$pageTitle = 'New Student Applications';
require_once __DIR__ . '/../includes/header.php';
?>

<form method="GET" class="toolbar">
    <input type="text" name="q" placeholder="Search name, email, or reference #..." value="<?= e($search) ?>"
        style="min-width:280px;">
    <select name="status">
        <option value="">All statuses</option>
        <?php foreach (['Requirements Pending', 'Requirements Verified', 'Payment Pending', 'Payment Verified', 'Approved', 'Rejected'] as $s): ?>
            <option value="<?= e($s) ?>" <?= $statusFilter === $s ? 'selected' : '' ?>><?= e($s) ?></option>
        <?php endforeach; ?>
    </select>
    <button type="submit" class="btn btn-outline btn-sm">Filter</button>
</form>

<div class="card">
    <?php if ($applications): ?>
        <table>
            <thead>
                <tr>
                    <th>Reference #</th>
                    <th>Name</th>
                    <th>Program</th>
                    <th>Status</th>
                    <th>Paid</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($applications as $a): ?>
                    <tr>
                        <td><?= e($a['reference_no']) ?></td>
                        <td><?= e($a['first_name'] . ' ' . $a['last_name']) ?></td>
                        <td><?= e($a['program_code']) ?></td>
                        <td><span
                                class="badge badge-<?= $statusBadge[$a['status']] ?? 'neutral' ?>"><?= e($a['status']) ?></span>
                        </td>
                        <td>&#8369;<?= number_format($a['total_paid'], 2) ?></td>
                        <td><a class="btn btn-outline btn-sm"
                                href="<?= BASE_URL ?>/registrar/application-review?id=<?= (int) $a['application_id'] ?>">Review</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <div class="empty-state">No applications match your filters.</div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>