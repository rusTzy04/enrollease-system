<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole([ROLE_ADMIN, ROLE_REGISTRAR, ROLE_CASHIER]);

$byStatus = $pdo->query("SELECT status, COUNT(*) AS cnt FROM enrollments GROUP BY status")->fetchAll();
$byProgram = $pdo->query(
    "SELECT p.program_name, COUNT(*) AS cnt FROM enrollments e
     JOIN student_profiles sp ON sp.user_id = e.student_id
     JOIN programs p ON p.program_id = sp.program_id
     WHERE e.status = 'Enrolled' GROUP BY p.program_name"
)->fetchAll();
$outstanding = $pdo->query(
    "SELECT u.student_id, u.first_name, u.last_name, e.total_amount_due, e.total_paid
     FROM enrollments e JOIN users u ON u.user_id = e.student_id
     WHERE (e.total_amount_due - e.total_paid) > 0 AND e.status NOT IN ('Rejected','Cancelled')
     ORDER BY (e.total_amount_due - e.total_paid) DESC LIMIT 50"
)->fetchAll();
$revenueByMethod = $pdo->query("SELECT payment_method, COALESCE(SUM(amount_paid),0) AS total FROM payments WHERE is_refund = 0 GROUP BY payment_method")->fetchAll();

$pageTitle = 'Reports';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="grid grid-2">
    <div class="card">
        <h4>Enrollment by Status</h4>
        <table>
            <thead>
                <tr>
                    <th>Status</th>
                    <th>Count</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($byStatus as $r): ?>
                    <tr>
                        <td><?= e($r['status']) ?></td>
                        <td><?= (int) $r['cnt'] ?></td>
                    </tr><?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="card">
        <h4>Enrolled Students by Program</h4>
        <table>
            <thead>
                <tr>
                    <th>Program</th>
                    <th>Count</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($byProgram as $r): ?>
                    <tr>
                        <td><?= e($r['program_name']) ?></td>
                        <td><?= (int) $r['cnt'] ?></td>
                    </tr><?php endforeach; ?>
                <?php if (!$byProgram): ?>
                    <tr>
                        <td colspan="2" class="helper-text">No enrolled students yet.</td>
                    </tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card" style="margin-top:20px;">
    <h4>Revenue by Payment Method</h4>
    <table>
        <thead>
            <tr>
                <th>Method</th>
                <th>Total</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($revenueByMethod as $r): ?>
                <tr>
                    <td><?= e($r['payment_method']) ?></td>
                    <td>&#8369;<?= number_format($r['total'], 2) ?></td>
                </tr><?php endforeach; ?>
        </tbody>
    </table>
</div>

<div class="card" style="margin-top:20px;">
    <h4>Outstanding Balances</h4>
    <table>
        <thead>
            <tr>
                <th>Student ID</th>
                <th>Name</th>
                <th>Due</th>
                <th>Paid</th>
                <th>Balance</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($outstanding as $o): ?>
                <tr>
                    <td><?= e($o['student_id']) ?></td>
                    <td><?= e($o['first_name'] . ' ' . $o['last_name']) ?></td>
                    <td>&#8369;<?= number_format($o['total_amount_due'], 2) ?></td>
                    <td>&#8369;<?= number_format($o['total_paid'], 2) ?></td>
                    <td>&#8369;<?= number_format($o['total_amount_due'] - $o['total_paid'], 2) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$outstanding): ?>
                <tr>
                    <td colspan="5" class="helper-text">No outstanding balances.</td>
                </tr><?php endif; ?>
        </tbody>
    </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>