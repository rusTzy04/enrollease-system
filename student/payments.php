<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole([ROLE_STUDENT]);

$studentId = $_SESSION['user_id'];

$stmt = $pdo->prepare("SELECT * FROM enrollments WHERE student_id = :sid ORDER BY created_at DESC LIMIT 1");
$stmt->execute([':sid' => $studentId]);
$enrollment = $stmt->fetch();

$payments = [];
if ($enrollment) {
    $stmt = $pdo->prepare(
        "SELECT p.*, CONCAT(u.first_name, ' ', u.last_name) AS cashier_name
         FROM payments p JOIN users u ON u.user_id = p.received_by
         WHERE p.enrollment_id = :eid ORDER BY p.payment_date DESC"
    );
    $stmt->execute([':eid' => $enrollment['enrollment_id']]);
    $payments = $stmt->fetchAll();
}

$balance = $enrollment ? max(0, $enrollment['total_amount_due'] - $enrollment['total_paid']) : 0;

$pageTitle = 'Payments & Balance';
require_once __DIR__ . '/../includes/header.php';
?>

<?php if (!$enrollment): ?>
    <div class="card empty-state">No enrollment record found yet.</div>
<?php else: ?>
    <div class="grid grid-4">
        <div class="card stat-card">
            <div class="stat-label">Tuition</div>
            <div class="stat-value" style="font-size:1.4rem;">&#8369;<?= number_format($enrollment['total_tuition'], 2) ?>
            </div>
        </div>
        <div class="card stat-card">
            <div class="stat-label">Fees</div>
            <div class="stat-value" style="font-size:1.4rem;">&#8369;<?= number_format($enrollment['total_fees'], 2) ?></div>
        </div>
        <div class="card stat-card">
            <div class="stat-label">Total Paid</div>
            <div class="stat-value" style="font-size:1.4rem;">&#8369;<?= number_format($enrollment['total_paid'], 2) ?></div>
        </div>
        <div class="card stat-card">
            <div class="stat-label">Balance</div>
            <div class="stat-value" style="font-size:1.4rem;">&#8369;<?= number_format($balance, 2) ?></div>
        </div>
    </div>

    <div class="card" style="margin-top:20px;">
        <h4>Payment History</h4>
        <?php if ($payments): ?>
            <table>
                <thead>
                    <tr>
                        <th>Receipt #</th>
                        <th>Date</th>
                        <th>Amount</th>
                        <th>Method</th>
                        <th>Received By</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($payments as $p): ?>
                        <tr>
                            <td><?= e($p['receipt_number']) ?></td>
                            <td><?= date('M j, Y g:ia', strtotime($p['payment_date'])) ?></td>
                            <td><?= $p['is_refund'] ? '−' : '' ?>&#8369;<?= number_format($p['amount_paid'], 2) ?></td>
                            <td><?= e($p['payment_method']) ?></td>
                            <td><?= e($p['cashier_name']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p class="helper-text">No payments recorded yet. Visit the Cashier's office with at least
                &#8369;<?= number_format(MIN_PAYMENT, 2) ?> to proceed with your enrollment.</p>
        <?php endif; ?>
    </div>

    <?php if ($enrollment['status'] === 'Enrolled'): ?>
        <div class="card" style="margin-top:20px;">
            <a class="btn btn-brass" href="<?= BASE_URL ?>/student/registration-form" target="_blank">View registration Form</a>
        </div>
    <?php endif; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>