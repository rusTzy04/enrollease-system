<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole([ROLE_ADMIN]);

$search = trim($_GET['q'] ?? '');
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 30;
$offset = ($page - 1) * $perPage;

$where = '';
$params = [];
if ($search !== '') {
    $where = "WHERE al.action LIKE :q1 OR al.details LIKE :q2 OR u.first_name LIKE :q3 OR u.last_name LIKE :q4";
    $like = "%{$search}%";
    $params[':q1'] = $like;
    $params[':q2'] = $like;
    $params[':q3'] = $like;
    $params[':q4'] = $like;
}

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM activity_logs al LEFT JOIN users u ON u.user_id = al.user_id {$where}");
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();

$stmt = $pdo->prepare(
    "SELECT al.*, u.first_name, u.last_name FROM activity_logs al
     LEFT JOIN users u ON u.user_id = al.user_id {$where}
     ORDER BY al.created_at DESC LIMIT {$perPage} OFFSET {$offset}"
);
$stmt->execute($params);
$logs = $stmt->fetchAll();

$pageTitle = 'Activity Logs';
require_once __DIR__ . '/../includes/header.php';
?>

<form method="GET" class="toolbar">
    <input type="text" name="q" placeholder="Search action, details, or user..." value="<?= e($search) ?>"
        style="min-width:280px;">
    <button type="submit" class="btn btn-outline btn-sm">Search</button>
</form>

<div class="card">
    <table>
        <thead>
            <tr>
                <th>Date/Time</th>
                <th>User</th>
                <th>Action</th>
                <th>Details</th>
                <th>IP</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($logs as $l): ?>
                <tr>
                    <td><?= date('M j, Y g:i:sa', strtotime($l['created_at'])) ?></td>
                    <td><?= $l['first_name'] ? e($l['first_name'] . ' ' . $l['last_name']) : 'System / Unknown' ?></td>
                    <td><?= e(str_replace('_', ' ', $l['action'])) ?></td>
                    <td><?= e($l['details'] ?? '') ?></td>
                    <td><?= e($l['ip_address'] ?? '') ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$logs): ?>
                <tr>
                    <td colspan="5" class="helper-text">No matching activity.</td>
                </tr><?php endif; ?>
        </tbody>
    </table>
    <div class="pagination">
        <?php for ($p = 1; $p <= ceil($total / $perPage); $p++): ?>
            <a href="?<?= http_build_query(array_merge($_GET, ['page' => $p])) ?>"
                class="<?= $p === $page ? 'current' : '' ?>"><?= $p ?></a>
        <?php endfor; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>