<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole([ROLE_CASHIER]);

$receiptNumber = $_GET['receipt'] ?? '';

// Try enrolled-student payments first...
$stmt = $pdo->prepare(
    "SELECT p.receipt_number, p.amount_paid, p.payment_method, p.payment_date,
            u.first_name, u.last_name, u.student_id, CONCAT(c.first_name,' ',c.last_name) AS cashier_name
     FROM payments p
     JOIN enrollments e ON e.enrollment_id = p.enrollment_id
     JOIN users u ON u.user_id = e.student_id
     JOIN users c ON c.user_id = p.received_by
     WHERE p.receipt_number = :rn"
);
$stmt->execute([':rn' => $receiptNumber]);
$payment = $stmt->fetch();

// ...then fall back to pre-account applicant payments
if (!$payment) {
    $stmt = $pdo->prepare(
        "SELECT ap.receipt_number, ap.amount_paid, ap.payment_method, ap.payment_date,
                a.first_name, a.last_name, a.reference_no AS student_id, CONCAT(c.first_name,' ',c.last_name) AS cashier_name
         FROM application_payments ap
         JOIN enrollment_applications a ON a.application_id = ap.application_id
         JOIN users c ON c.user_id = ap.received_by
         WHERE ap.receipt_number = :rn"
    );
    $stmt->execute([':rn' => $receiptNumber]);
    $payment = $stmt->fetch();
    $isApplicant = true;
} else {
    $isApplicant = false;
}

if (!$payment) {
    setFlash('error', 'Receipt not found.');
    redirect('/cashier/payment');
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Receipt <?= e($receiptNumber) ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
    <style>
        body {
            background: #fff;
            padding: 40px;
        }

        .doc {
            max-width: 420px;
            margin: 0 auto;
            border: 1px solid var(--line);
            padding: 28px;
        }

        @media print {
            .no-print {
                display: none;
            }
        }
    </style>
</head>

<body>
    <div class="doc">
        <div class="no-print" style="text-align:right; margin-bottom:16px;"><button class="btn btn-brass btn-sm"
                onclick="window.print()">Print</button></div>
        <h3 style="text-align:center;"><?= e(SITE_NAME) ?></h3>
        <p style="text-align:center; margin-top:-8px;">Official Receipt</p>
        <hr>
        <p><strong>Receipt #:</strong> <?= e($payment['receipt_number']) ?><br>
            <strong>Date:</strong> <?= date('M j, Y g:ia', strtotime($payment['payment_date'])) ?>
        </p>
        <p><strong><?= $isApplicant ? 'Applicant' : 'Student' ?>:</strong>
            <?= e($payment['first_name'] . ' ' . $payment['last_name']) ?><br>
            <strong><?= $isApplicant ? 'Reference #' : 'Student ID' ?>:</strong> <?= e($payment['student_id']) ?>
        </p>
        <p><strong>Amount Paid:</strong> &#8369;<?= number_format($payment['amount_paid'], 2) ?><br>
            <strong>Method:</strong> <?= e($payment['payment_method']) ?>
        </p>
        <p><strong>Received by:</strong> <?= e($payment['cashier_name']) ?></p>
        <hr>
        <p style="text-align:center; font-size:0.8rem; color:var(--ink-soft);">Thank you.</p>
        <div class="no-print" style="text-align:center; margin-top:16px;">
            <a href="<?= BASE_URL ?>/cashier/<?= $isApplicant ? 'applicant-payment' : 'payment' ?>"
                class="btn btn-outline btn-sm">&larr; Back to
                <?= $isApplicant ? 'New Applicant Payment' : 'Walk-in Payment' ?></a>
        </div>
    </div>
</body>

</html>