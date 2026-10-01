<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole([ROLE_CASHIER]);

$dateFrom = $_GET['from'] ?? '';
$dateTo = $_GET['to'] ?? '';

$whereStudent = [];
$whereApplicant = [];
$params = [];
if ($dateFrom) {
    $whereStudent[] = "p.payment_date >= :from1";
    $whereApplicant[] = "ap.payment_date >= :from2";
    $params[':from1'] = $dateFrom . ' 00:00:00';
    $params[':from2'] = $dateFrom . ' 00:00:00';
}
if ($dateTo) {
    $whereStudent[] = "p.payment_date <= :to1";
    $whereApplicant[] = "ap.payment_date <= :to2";
    $params[':to1'] = $dateTo . ' 23:59:59';
    $params[':to2'] = $dateTo . ' 23:59:59';
}
$whereStudentSql = $whereStudent ? 'WHERE ' . implode(' AND ', $whereStudent) : '';
$whereApplicantSql = $whereApplicant ? 'WHERE ' . implode(' AND ', $whereApplicant) : '';

$stmt = $pdo->prepare(
    "SELECT p.receipt_number, p.payment_date, p.amount_paid, p.payment_method,
            CONCAT(u.first_name, ' ', u.last_name) AS payer_name, u.student_id AS payer_ref, 'Student' AS payer_type
     FROM payments p JOIN enrollments e ON e.enrollment_id = p.enrollment_id JOIN users u ON u.user_id = e.student_id
     {$whereStudentSql}
     UNION ALL
     SELECT ap.receipt_number, ap.payment_date, ap.amount_paid, ap.payment_method,
            CONCAT(a.first_name, ' ', a.last_name) AS payer_name, a.reference_no AS payer_ref, 'Applicant' AS payer_type
     FROM application_payments ap JOIN enrollment_applications a ON a.application_id = ap.application_id
     {$whereApplicantSql}
     ORDER BY payment_date DESC LIMIT 200"
);
$stmt->execute($params);
$payments = $stmt->fetchAll();

$pageTitle = 'Payment History';
require_once __DIR__ . '/../includes/header.php';
?>

<form method="GET" class="toolbar">
    <input type="date" name="from" value="<?= e($dateFrom) ?>">
    <input type="date" name="to" value="<?= e($dateTo) ?>">
    <button type="submit" class="btn btn-outline btn-sm">Filter by date</button>
</form>

<div class="card">
    <?php if ($payments): ?>
        <table>
            <thead>
                <tr>
                    <th>Receipt #</th>
                    <th>Date</th>
                    <th>Payer</th>
                    <th>Type</th>
                    <th>Amount</th>
                    <th>Method</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($payments as $p): ?>
                    <tr>
                        <td><?= e($p['receipt_number']) ?></td>
                        <td><?= date('M j, Y g:ia', strtotime($p['payment_date'])) ?></td>
                        <td><?= e($p['payer_name']) ?> (<?= e($p['payer_ref']) ?>)</td>
                        <td><span
                                class="badge badge-<?= $p['payer_type'] === 'Student' ? 'info' : 'neutral' ?>"><?= e($p['payer_type']) ?></span>
                        </td>
                        <td>&#8369;<?= number_format($p['amount_paid'], 2) ?></td>
                        <td><?= e($p['payment_method']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <div class="empty-state">No payments found for that range.</div><?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>