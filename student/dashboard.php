<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole([ROLE_STUDENT]);

$studentId = $_SESSION['user_id'];

// Active semester
$sem = $pdo->query(
    "SELECT s.semester_id, s.semester_name, ay.year_label
     FROM semesters s JOIN academic_years ay ON ay.academic_year_id = s.academic_year_id
     WHERE s.is_active = 1 LIMIT 1"
)->fetch();

$enrollment = null;
if ($sem) {
    $stmt = $pdo->prepare(
        "SELECT * FROM enrollments WHERE student_id = :sid AND semester_id = :semid"
    );
    $stmt->execute([':sid' => $studentId, ':semid' => $sem['semester_id']]);
    $enrollment = $stmt->fetch();
}

// Profile / program
$stmt = $pdo->prepare(
    "SELECT sp.year_level, p.program_name, p.program_code
     FROM student_profiles sp JOIN programs p ON p.program_id = sp.program_id
     WHERE sp.user_id = :sid"
);
$stmt->execute([':sid' => $studentId]);
$profile = $stmt->fetch();

// Pending requirements count
$pendingReqs = 0;
if ($enrollment) {
    $stmt = $pdo->prepare("SELECT COUNT(*) AS cnt FROM enrollment_requirements WHERE enrollment_id = :eid AND status != 'Verified'");
    $stmt->execute([':eid' => $enrollment['enrollment_id']]);
    $pendingReqs = (int) $stmt->fetch()['cnt'];
}

$balance = $enrollment ? max(0, $enrollment['total_amount_due'] - $enrollment['total_paid']) : 0;

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

$pageTitle = 'Dashboard';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="grid grid-4">
    <div class="card stat-card">
        <div class="stat-label">Enrollment Status</div>
        <div class="stat-value" style="font-size:1.3rem;">
            <?php if ($enrollment): ?>
                <span
                    class="badge badge-<?= $statusBadge[$enrollment['status']] ?? 'neutral' ?>"><?= e($enrollment['status']) ?></span>
            <?php else: ?>
                <span class="badge badge-neutral">Not started</span>
            <?php endif; ?>
        </div>
        <div class="stat-sub"><?= $sem ? e($sem['semester_name'] . ' · ' . $sem['year_label']) : 'No active semester' ?>
        </div>
    </div>
    <div class="card stat-card">
        <div class="stat-label">Pending Requirements</div>
        <div class="stat-value"><?= (int) $pendingReqs ?></div>
        <div class="stat-sub">documents still needed</div>
    </div>
    <div class="card stat-card">
        <div class="stat-label">Remaining Balance</div>
        <div class="stat-value">&#8369;<?= number_format($balance, 2) ?></div>
        <div class="stat-sub">minimum &#8369;<?= number_format(MIN_PAYMENT, 2) ?> to enroll</div>
    </div>
    <div class="card stat-card">
        <div class="stat-label">Year Level</div>
        <div class="stat-value"><?= $profile ? (int) $profile['year_level'] : '—' ?></div>
        <div class="stat-sub"><?= $profile ? e($profile['program_code']) : 'No program set' ?></div>
    </div>
</div>

<div class="card" style="margin-top:20px;">
    <div class="section-head">
        <h3 style="margin:0;">Quick actions</h3>
    </div>
    <div class="grid grid-3">
        <a class="btn btn-outline" href="<?= BASE_URL ?>/student/curriculum">View my curriculum</a>
        <a class="btn btn-outline" href="<?= BASE_URL ?>/student/enroll">View my enrollment &amp; schedule</a>
        <a class="btn btn-outline" href="<?= BASE_URL ?>/student/payments">View payments & balance</a>
    </div>
</div>

<?php if (!$sem): ?>
    <div class="alert alert-info" style="margin-top:20px;">There is no active enrollment period right now. Please check back
        once the registrar opens enrollment.</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>