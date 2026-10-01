<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole([ROLE_CASHIER]);

$errors = [];
$receiptNumber = null;
$student = null;

// List everyone awaiting payment by default; search just narrows it down.
$searchQ = trim($_GET['q'] ?? '');
$where = "e.status IN ('Payment Pending','Requirements Verified')";
$params = [];
if ($searchQ !== '') {
    $where .= " AND (u.student_id LIKE :q1 OR u.first_name LIKE :q2 OR u.last_name LIKE :q3)";
    $like = "%{$searchQ}%";
    $params[':q1'] = $like;
    $params[':q2'] = $like;
    $params[':q3'] = $like;
}
$stmt = $pdo->prepare(
    "SELECT u.user_id, u.student_id, u.first_name, u.last_name, e.enrollment_id, e.status,
            e.total_amount_due, e.total_paid
     FROM users u JOIN enrollments e ON e.student_id = u.user_id
     WHERE {$where}
     ORDER BY u.last_name LIMIT 200"
);
$stmt->execute($params);
$results = $stmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $enrollmentId = (int) $_POST['enrollment_id'];
    $amount = (float) $_POST['amount'];
    $method = $_POST['payment_method'] ?? 'Cash';

    $stmt = $pdo->prepare("SELECT * FROM enrollments WHERE enrollment_id = :id");
    $stmt->execute([':id' => $enrollmentId]);
    $enrollment = $stmt->fetch();

    if (!$enrollment) {
        $errors[] = 'Enrollment not found.';
    } elseif ($amount <= 0) {
        $errors[] = 'Amount must be greater than zero.';
    } elseif ($enrollment['total_paid'] == 0 && $amount < MIN_PAYMENT) {
        $errors[] = 'The minimum initial payment is &#8369;' . number_format(MIN_PAYMENT, 2) . '.';
    } else {
        $pdo->beginTransaction();
        $receiptNumber = generateReceiptNumber($pdo);

        $pdo->prepare(
            "INSERT INTO payments (enrollment_id, receipt_number, amount_paid, payment_method, received_by, is_verified)
             VALUES (:eid, :rn, :amt, :method, :cashier, 1)"
        )->execute([
                    ':eid' => $enrollmentId,
                    ':rn' => $receiptNumber,
                    ':amt' => $amount,
                    ':method' => $method,
                    ':cashier' => $_SESSION['user_id']
                ]);

        $newTotalPaid = $enrollment['total_paid'] + $amount;
        $newStatus = $enrollment['status'];
        if ($newTotalPaid >= MIN_PAYMENT) {
            $newStatus = 'Payment Verified';
        }

        $pdo->prepare("UPDATE enrollments SET total_paid = :paid, status = :status WHERE enrollment_id = :eid")
            ->execute([':paid' => $newTotalPaid, ':status' => $newStatus, ':eid' => $enrollmentId]);

        if ($newStatus === 'Payment Verified' && $enrollment['status'] !== 'Payment Verified') {
            $pdo->prepare(
                "INSERT INTO enrollment_status_history (enrollment_id, old_status, new_status, changed_by) VALUES (:eid, :old, 'Payment Verified', :uid)"
            )->execute([':eid' => $enrollmentId, ':old' => $enrollment['status'], ':uid' => $_SESSION['user_id']]);
            notify($pdo, $enrollment['student_id'], 'Payment verified', 'Your payment has been verified. Your enrollment is now with the Registrar for final approval.');
        }

        $pdo->commit();
        logActivity($pdo, $_SESSION['user_id'], 'payment_recorded', "Enrollment #{$enrollmentId}, amount {$amount}, receipt {$receiptNumber}");
        setFlash('success', "Payment recorded. Receipt #: {$receiptNumber}");
        redirect('/cashier/receipt?receipt=' . urlencode($receiptNumber));
    }
}

$pageTitle = 'Walk-in Payment';
require_once __DIR__ . '/../includes/header.php';
?>

<?php foreach ($errors as $err): ?>
    <div class="alert alert-error"><?= $err ?></div><?php endforeach; ?>

<div class="card">
    <form method="GET" class="toolbar">
        <input type="text" name="q" placeholder="Search by student ID or name..." value="<?= e($searchQ) ?>"
            style="min-width:280px;">
        <button type="submit" class="btn btn-outline btn-sm">Search</button>
        <?php if ($searchQ !== ''): ?><a href="<?= BASE_URL ?>/cashier/payment"
                class="btn btn-outline btn-sm">Clear</a><?php endif; ?>
    </form>

    <?php if ($results): ?>
        <table>
            <thead>
                <tr>
                    <th>Student ID</th>
                    <th>Name</th>
                    <th>Status</th>
                    <th>Balance</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($results as $r):
                    $bal = max(0, $r['total_amount_due'] - $r['total_paid']); ?>
                    <tr>
                        <td><?= e($r['student_id']) ?></td>
                        <td><?= e($r['first_name'] . ' ' . $r['last_name']) ?></td>
                        <td><span class="badge badge-warning"><?= e($r['status']) ?></span></td>
                        <td>&#8369;<?= number_format($bal, 2) ?></td>
                        <td>
                            <form method="POST" style="display:flex; gap:8px;">
                                <?= csrfField() ?>
                                <input type="hidden" name="enrollment_id" value="<?= (int) $r['enrollment_id'] ?>">
                                <input type="number" step="0.01" min="0.01" name="amount" placeholder="Amount" required
                                    style="width:120px;">
                                <select name="payment_method" style="width:auto;">
                                    <option>Cash</option>
                                    <option>Card</option>
                                    <option>Bank Transfer</option>
                                    <option>Other</option>
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
            <?= $searchQ !== '' ? 'No students awaiting payment match that search.' : 'No students are currently awaiting payment.' ?>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>