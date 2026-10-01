<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole([ROLE_CASHIER]);

$errors = [];
$searchQ = trim($_GET['q'] ?? '');

$where = "a.status = 'Payment Pending'";
$params = [];
if ($searchQ !== '') {
    $where .= " AND (a.reference_no LIKE :q1 OR a.first_name LIKE :q2 OR a.last_name LIKE :q3 OR a.email LIKE :q4)";
    $like = "%{$searchQ}%";
    $params[':q1'] = $like;
    $params[':q2'] = $like;
    $params[':q3'] = $like;
    $params[':q4'] = $like;
}
$stmt = $pdo->prepare(
    "SELECT a.application_id, a.reference_no, a.first_name, a.last_name, a.status, a.total_paid
     FROM enrollment_applications a
     WHERE {$where}
     ORDER BY a.last_name LIMIT 200"
);
$stmt->execute($params);
$results = $stmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $applicationId = (int) $_POST['application_id'];
    $amount = (float) $_POST['amount'];
    $method = $_POST['payment_method'] ?? 'Cash';

    $stmt = $pdo->prepare("SELECT * FROM enrollment_applications WHERE application_id = :id");
    $stmt->execute([':id' => $applicationId]);
    $app = $stmt->fetch();

    if (!$app) {
        $errors[] = 'Application not found.';
    } elseif ($amount <= 0) {
        $errors[] = 'Amount must be greater than zero.';
    } elseif ($app['total_paid'] == 0 && $amount < MIN_PAYMENT) {
        $errors[] = 'The minimum initial payment is &#8369;' . number_format(MIN_PAYMENT, 2) . '.';
    } else {
        $pdo->beginTransaction();
        $receiptNumber = generateReceiptNumber($pdo);

        $pdo->prepare(
            "INSERT INTO application_payments (application_id, receipt_number, amount_paid, payment_method, received_by)
             VALUES (:aid, :rn, :amt, :method, :cashier)"
        )->execute([
                    ':aid' => $applicationId,
                    ':rn' => $receiptNumber,
                    ':amt' => $amount,
                    ':method' => $method,
                    ':cashier' => $_SESSION['user_id']
                ]);

        $newTotalPaid = $app['total_paid'] + $amount;
        $newStatus = $newTotalPaid >= MIN_PAYMENT ? 'Payment Verified' : $app['status'];

        $pdo->prepare("UPDATE enrollment_applications SET total_paid = :paid, status = :status WHERE application_id = :id")
            ->execute([':paid' => $newTotalPaid, ':status' => $newStatus, ':id' => $applicationId]);

        $pdo->commit();
        logActivity($pdo, $_SESSION['user_id'], 'applicant_payment_recorded', "Application #{$applicationId}, amount {$amount}, receipt {$receiptNumber}");
        setFlash('success', "Payment recorded. Receipt #: {$receiptNumber}");
        redirect('/cashier/receipt?receipt=' . urlencode($receiptNumber));
    }
}

$pageTitle = 'New Applicant Payment';
require_once __DIR__ . '/../includes/header.php';
?>

<?php foreach ($errors as $err): ?>
    <div class="alert alert-error"><?= $err ?></div><?php endforeach; ?>

<div class="card">
    <form method="GET" class="toolbar">
        <input type="text" name="q" placeholder="Search by reference #, name, or email..." value="<?= e($searchQ) ?>"
            style="min-width:280px;">
        <button type="submit" class="btn btn-outline btn-sm">Search</button>
        <?php if ($searchQ !== ''): ?><a href="<?= BASE_URL ?>/cashier/applicant-payment"
                class="btn btn-outline btn-sm">Clear</a><?php endif; ?>
    </form>

    <?php if ($results): ?>
        <table>
            <thead>
                <tr>
                    <th>Reference #</th>
                    <th>Name</th>
                    <th>Paid so far</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($results as $r): ?>
                    <tr>
                        <td><?= e($r['reference_no']) ?></td>
                        <td><?= e($r['first_name'] . ' ' . $r['last_name']) ?></td>
                        <td>&#8369;<?= number_format($r['total_paid'], 2) ?></td>
                        <td>
                            <form method="POST" style="display:flex; gap:8px;">
                                <?= csrfField() ?>
                                <input type="hidden" name="application_id" value="<?= (int) $r['application_id'] ?>">
                                <input type="number" step="0.01" min="0.01" name="amount" placeholder="Amount" required
                                    style="width:120px;">
                                <select name="payment_method" style="width:auto;">
                                    <option>Cash</option>
                                    <option>Card</option>
                                    <option>Bank Transfer</option>
                                </select>
                                <button type="submit" class="btn btn-brass btn-sm"
                                    data-confirm="Confirm payment for <?= e($r['first_name']) ?>?">Record Payment</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <div class="empty-state">
            <?= $searchQ !== '' ? 'No applicants awaiting payment match that search.' : 'No applicants are currently awaiting payment.' ?>
            Check that the Registrar has fully verified their requirements first.</div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>