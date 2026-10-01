<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole([ROLE_CASHIER]);

// Scope all figures to the ACTIVE semester so last term's collections don't get mixed in.
$sem = $pdo->query(
    "SELECT s.semester_id, s.semester_name, ay.year_label
     FROM semesters s JOIN academic_years ay ON ay.academic_year_id = s.academic_year_id
     WHERE s.is_active = 1 LIMIT 1"
)->fetch();

$todayTotal = 0;
$todayApplicantTotal = 0;
$termTotal = 0;
$termApplicantTotal = 0;
$pendingPayments = 0;
$pendingApplicantPayments = 0;
$totalOutstanding = 0;
$priorOutstanding = 0;

if ($sem) {
    $semId = $sem['semester_id'];

    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(p.amount_paid),0) FROM payments p
         JOIN enrollments e ON e.enrollment_id = p.enrollment_id
         WHERE e.semester_id = :sid AND p.is_refund = 0 AND DATE(p.payment_date) = CURDATE()"
    );
    $stmt->execute([':sid' => $semId]);
    $todayTotal = (float) $stmt->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(ap.amount_paid),0) FROM application_payments ap
         JOIN enrollment_applications a ON a.application_id = ap.application_id
         WHERE a.semester_id = :sid AND DATE(ap.payment_date) = CURDATE()"
    );
    $stmt->execute([':sid' => $semId]);
    $todayApplicantTotal = (float) $stmt->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(p.amount_paid),0) FROM payments p
         JOIN enrollments e ON e.enrollment_id = p.enrollment_id
         WHERE e.semester_id = :sid AND p.is_refund = 0"
    );
    $stmt->execute([':sid' => $semId]);
    $termTotal = (float) $stmt->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(ap.amount_paid),0) FROM application_payments ap
         JOIN enrollment_applications a ON a.application_id = ap.application_id
         WHERE a.semester_id = :sid"
    );
    $stmt->execute([':sid' => $semId]);
    $termApplicantTotal = (float) $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM enrollments WHERE semester_id = :sid AND status = 'Payment Pending'");
    $stmt->execute([':sid' => $semId]);
    $pendingPayments = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM enrollment_applications WHERE semester_id = :sid AND status = 'Payment Pending'");
    $stmt->execute([':sid' => $semId]);
    $pendingApplicantPayments = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(total_amount_due - total_paid),0) FROM enrollments
         WHERE semester_id = :sid AND status NOT IN ('Rejected','Cancelled','Draft') AND (total_amount_due - total_paid) > 0"
    );
    $stmt->execute([':sid' => $semId]);
    $totalOutstanding = (float) $stmt->fetchColumn();

    // Unpaid balances carried over from earlier terms — these block re-enrollment
    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(total_amount_due - total_paid),0) FROM enrollments
         WHERE semester_id != :sid AND status NOT IN ('Rejected','Cancelled','Draft') AND (total_amount_due - total_paid) > 0"
    );
    $stmt->execute([':sid' => $semId]);
    $priorOutstanding = (float) $stmt->fetchColumn();
}

$pageTitle = 'Cashier Dashboard';
require_once __DIR__ . '/../includes/header.php';
?>

<?php if (!$sem): ?>
    <div class="alert alert-info">No active semester is set. Ask the Academic Scheduler to set one under Academic Year &amp;
        Semester.</div>
<?php else: ?>

    <div class="card" style="margin-bottom:20px;">
        <strong>Current term:</strong> <?= e($sem['year_label'] . ' — ' . $sem['semester_name']) ?>
        <span class="helper-text"> · All figures below cover this term only.</span>
    </div>

    <div class="grid grid-4">
        <div class="card stat-card">
            <div class="stat-label">Collected Today</div>
            <div class="stat-value">&#8369;<?= number_format($todayTotal + $todayApplicantTotal, 2) ?></div>
        </div>
        <div class="card stat-card">
            <div class="stat-label">Collected This Term</div>
            <div class="stat-value">&#8369;<?= number_format($termTotal + $termApplicantTotal, 2) ?></div>
        </div>
        <div class="card stat-card">
            <div class="stat-label">Returning Students Awaiting Payment</div>
            <div class="stat-value"><?= $pendingPayments ?></div>
        </div>
        <div class="card stat-card">
            <div class="stat-label">New Applicants Awaiting Payment</div>
            <div class="stat-value"><?= $pendingApplicantPayments ?></div>
        </div>
    </div>

    <div class="grid grid-2" style="margin-top:18px;">
        <div class="card stat-card">
            <div class="stat-label">Outstanding This Term</div>
            <div class="stat-value">&#8369;<?= number_format($totalOutstanding, 2) ?></div>
        </div>
        <div class="card stat-card" style="<?= $priorOutstanding > 0 ? 'border-color:var(--danger);' : '' ?>">
            <div class="stat-label">Unpaid from Previous Terms</div>
            <div class="stat-value">&#8369;<?= number_format($priorOutstanding, 2) ?></div>
        </div>
    </div>

    <div class="card" style="margin-top:20px;">
        <div class="grid grid-3">
            <a class="btn btn-brass" href="<?= BASE_URL ?>/cashier/payment">Record a returning-student payment</a>
            <a class="btn btn-brass" href="<?= BASE_URL ?>/cashier/applicant-payment">Record a new-applicant payment</a>
            <a class="btn btn-outline" href="<?= BASE_URL ?>/cashier/balance-payment">Record a remaining-balance payment</a>
        </div>
    </div>

<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>