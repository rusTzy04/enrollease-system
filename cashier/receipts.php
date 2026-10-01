<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole([ROLE_CASHIER]);

$q = trim($_GET['q'] ?? '');

$whereStudent = "1=1";
$whereApplicant = "1=1";
$params = [];
if ($q !== '') {
    $whereStudent = "p.receipt_number LIKE :q1";
    $whereApplicant = "ap.receipt_number LIKE :q2";
    $like = "%{$q}%";
    $params[':q1'] = $like;
    $params[':q2'] = $like;
}

$stmt = $pdo->prepare(
    "SELECT p.receipt_number, p.payment_date, p.amount_paid,
            CONCAT(u.first_name, ' ', u.last_name) AS payer_name, u.student_id AS payer_ref, 'Student' AS payer_type
     FROM payments p JOIN enrollments e ON e.enrollment_id = p.enrollment_id JOIN users u ON u.user_id = e.student_id
     WHERE {$whereStudent}
     UNION ALL
     SELECT ap.receipt_number, ap.payment_date, ap.amount_paid,
            CONCAT(a.first_name, ' ', a.last_name) AS payer_name, a.reference_no AS payer_ref, 'Applicant' AS payer_type
     FROM application_payments ap JOIN enrollment_applications a ON a.application_id = ap.application_id
     WHERE {$whereApplicant}
     ORDER BY payment_date DESC LIMIT 200"
);
$stmt->execute($params);
$results = $stmt->fetchAll();

$pageTitle = 'Receipts';
require_once __DIR__ . '/../includes/header.php';
?>
<form method="GET" class="toolbar">
    <input type="text" name="q" placeholder="Search receipt number..." value="<?= e($q) ?>">
    <button type="submit" class="btn btn-outline btn-sm">Search</button>
    <?php if ($q !== ''): ?><a href="<?= BASE_URL ?>/cashier/receipts"
            class="btn btn-outline btn-sm">Clear</a><?php endif; ?>
</form>
<div class="card">
    <?php if ($results): ?>
        <table>
            <thead>
                <tr>
                    <th>Receipt #</th>
                    <th>Date</th>
                    <th>Payer</th>
                    <th>Type</th>
                    <th>Amount</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($results as $r): ?>
                    <tr>
                        <td><?= e($r['receipt_number']) ?></td>
                        <td><?= date('M j, Y g:ia', strtotime($r['payment_date'])) ?></td>
                        <td><?= e($r['payer_name']) ?> (<?= e($r['payer_ref']) ?>)</td>
                        <td><span
                                class="badge badge-<?= $r['payer_type'] === 'Student' ? 'info' : 'neutral' ?>"><?= e($r['payer_type']) ?></span>
                        </td>
                        <td>&#8369;<?= number_format($r['amount_paid'], 2) ?></td>
                        <td><a class="btn btn-outline btn-sm"
                                href="<?= BASE_URL ?>/cashier/receipt?receipt=<?= urlencode($r['receipt_number']) ?>">View</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <div class="empty-state">No receipts found.</div><?php endif; ?>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>