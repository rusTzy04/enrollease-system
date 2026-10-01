<?php
require_once __DIR__ . '/includes/auth.php';
requireRole([ROLE_REGISTRAR, ROLE_CASHIER, ROLE_ADMIN]);

$search = trim($_GET['q'] ?? '');
$where = "WHERE u.role_id = " . ROLE_STUDENT;
$params = [];
if ($search !== '') {
    $where .= " AND (u.student_id LIKE :q1 OR u.first_name LIKE :q2 OR u.last_name LIKE :q3 OR u.email LIKE :q4)";
    $like = "%{$search}%";
    $params[':q1'] = $like;
    $params[':q2'] = $like;
    $params[':q3'] = $like;
    $params[':q4'] = $like;
}

$stmt = $pdo->prepare(
    "SELECT u.user_id, u.student_id, u.first_name, u.last_name, u.email, u.phone, u.is_active, u.created_at,
            p.program_code, sp.year_level,
            (SELECT e.status FROM enrollments e WHERE e.student_id = u.user_id ORDER BY e.created_at DESC LIMIT 1) AS latest_status,
            (SELECT e.total_amount_due - e.total_paid FROM enrollments e WHERE e.student_id = u.user_id ORDER BY e.created_at DESC LIMIT 1) AS balance
     FROM users u
     LEFT JOIN student_profiles sp ON sp.user_id = u.user_id
     LEFT JOIN programs p ON p.program_id = sp.program_id
     {$where}
     ORDER BY u.created_at DESC LIMIT 200"
);
$stmt->execute($params);
$students = $stmt->fetchAll();

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

$pageTitle = 'Student Directory';
require_once __DIR__ . '/includes/header.php';
?>

<form method="GET" class="toolbar">
    <input type="text" name="q" placeholder="Search by Student ID, name, or email..." value="<?= e($search) ?>"
        style="min-width:300px;">
    <button type="submit" class="btn btn-outline btn-sm">Search</button>
</form>

<div class="card">
    <?php if ($students): ?>
        <table>
            <thead>
                <tr>
                    <th>Student ID</th>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Program</th>
                    <th>Latest Status</th>
                    <th>Balance</th>
                    <th>Account</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($students as $s): ?>
                    <tr>
                        <td><?= e($s['student_id'] ?? '—') ?></td>
                        <td><?= e($s['first_name'] . ' ' . $s['last_name']) ?></td>
                        <td><?= e($s['email']) ?></td>
                        <td><?= e($s['program_code'] ?? '—') ?><?= $s['year_level'] ? ' — Yr ' . (int) $s['year_level'] : '' ?>
                        </td>
                        <td><?php if ($s['latest_status']): ?><span
                                    class="badge badge-<?= $statusBadge[$s['latest_status']] ?? 'neutral' ?>"><?= e($s['latest_status']) ?></span><?php else: ?><span
                                    class="badge badge-neutral">No application yet</span><?php endif; ?></td>
                        <td><?= $s['balance'] !== null ? '&#8369;' . number_format(max(0, $s['balance']), 2) : '—' ?></td>
                        <td><span
                                class="badge badge-<?= $s['is_active'] ? 'success' : 'danger' ?>"><?= $s['is_active'] ? 'Active' : 'Deactivated' ?></span>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <div class="empty-state">No student accounts match that search.</div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>