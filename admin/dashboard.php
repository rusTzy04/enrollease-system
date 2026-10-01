<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole([ROLE_ADMIN]);

$userCounts = $pdo->query(
    "SELECT r.role_name, COUNT(*) AS cnt FROM users u JOIN roles r ON r.role_id = u.role_id GROUP BY r.role_name"
)->fetchAll(PDO::FETCH_KEY_PAIR);

$enrolledCount = $pdo->query("SELECT COUNT(*) FROM enrollments WHERE status = 'Enrolled'")->fetchColumn();
$totalRevenue = $pdo->query("SELECT COALESCE(SUM(amount_paid),0) FROM payments WHERE is_refund = 0")->fetchColumn();

$pageTitle = 'Admin Dashboard';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="grid grid-4">
    <div class="card stat-card">
        <div class="stat-label">Students</div>
        <div class="stat-value"><?= (int) ($userCounts['student'] ?? 0) ?></div>
    </div>
    <div class="card stat-card">
        <div class="stat-label">Staff Accounts</div>
        <div class="stat-value"><?= array_sum($userCounts) - (int) ($userCounts['student'] ?? 0) ?></div>
    </div>
    <div class="card stat-card">
        <div class="stat-label">Enrolled This Term</div>
        <div class="stat-value"><?= (int) $enrolledCount ?></div>
    </div>
    <div class="card stat-card">
        <div class="stat-label">Total Revenue</div>
        <div class="stat-value">&#8369;<?= number_format($totalRevenue, 2) ?></div>
    </div>
</div>

<div class="card" style="margin-top:20px;">
    <div class="grid grid-3">
        <a class="btn btn-outline" href="<?= BASE_URL ?>/admin/users">Manage user accounts</a>
        <a class="btn btn-outline" href="<?= BASE_URL ?>/admin/reports">View reports</a>
        <a class="btn btn-outline" href="<?= BASE_URL ?>/admin/logs">Activity & audit logs</a>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>