<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole([ROLE_STUDENT]);

$studentId = $_SESSION['user_id'];

$sem = $pdo->query(
    "SELECT s.semester_id, s.semester_name, ay.year_label
     FROM semesters s JOIN academic_years ay ON ay.academic_year_id = s.academic_year_id
     WHERE s.is_active = 1 LIMIT 1"
)->fetch();

$enrollment = null;
$subjects = [];
if ($sem) {
    $stmt = $pdo->prepare("SELECT * FROM enrollments WHERE student_id = :sid AND semester_id = :semid");
    $stmt->execute([':sid' => $studentId, ':semid' => $sem['semester_id']]);
    $enrollment = $stmt->fetch();

    if ($enrollment) {
        $stmt = $pdo->prepare(
            "SELECT sub.subject_code, sub.subject_name, sub.units, sec.section_name,
                    cs.day_of_week, cs.start_time, cs.end_time,
                    CONCAT(t.first_name,' ',t.last_name) AS teacher_name, r.room_name
             FROM enrollment_subjects es
             JOIN class_schedules cs ON cs.schedule_id = es.schedule_id
             JOIN subjects sub ON sub.subject_id = cs.subject_id
             JOIN sections sec ON sec.section_id = cs.section_id
             LEFT JOIN users t ON t.user_id = cs.teacher_id
             LEFT JOIN rooms r ON r.room_id = cs.room_id
             WHERE es.enrollment_id = :eid
             ORDER BY sub.subject_code"
        );
        $stmt->execute([':eid' => $enrollment['enrollment_id']]);
        $subjects = $stmt->fetchAll();
    }
}

// Unpaid balances carried over from previous terms
$balStmt = $pdo->prepare(
    "SELECT ay.year_label, s.semester_name, (e.total_amount_due - e.total_paid) AS balance
     FROM enrollments e
     JOIN semesters s ON s.semester_id = e.semester_id
     JOIN academic_years ay ON ay.academic_year_id = s.academic_year_id
     WHERE e.student_id = :sid
       AND (:semid IS NULL OR e.semester_id != :semid2)
       AND e.status NOT IN ('Rejected','Cancelled','Draft')
       AND (e.total_amount_due - e.total_paid) > 0
     ORDER BY ay.year_label DESC"
);
$balStmt->execute([
    ':sid' => $studentId,
    ':semid' => $sem['semester_id'] ?? null,
    ':semid2' => $sem['semester_id'] ?? 0,
]);
$outstandingBalances = $balStmt->fetchAll();
$totalOutstanding = array_sum(array_column($outstandingBalances, 'balance'));

$statusStepMap = [
    'Draft' => 1,
    'Submitted' => 1,
    'Requirements Pending' => 1,
    'Requirements Verified' => 2,
    'Payment Pending' => 2,
    'Payment Verified' => 3,
    'Enrolled' => 4,
    'Rejected' => 0,
    'Cancelled' => 0,
];
$currentStep = $statusStepMap[$enrollment['status'] ?? ''] ?? 0;
function stepClass(int $step, int $current): string
{
    if ($current > $step)
        return 'done';
    if ($current === $step)
        return 'active';
    return '';
}

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

$balance = $enrollment ? max(0, $enrollment['total_amount_due'] - $enrollment['total_paid']) : 0;

$pageTitle = 'My Enrollment';
require_once __DIR__ . '/../includes/header.php';
?>

<?php if ($enrollment): ?>
    <div class="stepper">
        <div class="step <?= stepClass(1, $currentStep) ?>">1. Subjects Assigned</div>
        <div class="step <?= stepClass(2, $currentStep) ?>">2. Payment</div>
        <div class="step <?= stepClass(3, $currentStep) ?>">3. Registrar Approval</div>
        <div class="step <?= stepClass(4, $currentStep) ?>">4. Enrolled</div>
    </div>
<?php endif; ?>

<?php if ($totalOutstanding > 0): ?>
    <div class="card" style="border:2px solid var(--danger); background:var(--danger-tint); margin-bottom:20px;">
        <h4 style="margin-bottom:6px;">Outstanding balance from a previous term</h4>
        <p>You owe <strong>&#8369;<?= number_format($totalOutstanding, 2) ?></strong>. Please settle this at the Cashier's
            office — you can't be enrolled for a new term until it's cleared.</p>
        <table style="margin-top:10px;">
            <thead>
                <tr>
                    <th>Term</th>
                    <th>Balance</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($outstandingBalances as $b): ?>
                    <tr>
                        <td><?= e($b['year_label'] . ' — ' . $b['semester_name']) ?></td>
                        <td>&#8369;<?= number_format($b['balance'], 2) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php if (!$sem): ?>
    <div class="card empty-state">There is no active enrollment term right now. Please check back once your school opens
        enrollment.</div>

<?php elseif (!$enrollment): ?>
    <div class="card empty-state">
        <h4>You're not yet enrolled for <?= e($sem['year_label'] . ' — ' . $sem['semester_name']) ?></h4>
        <p style="margin:0 auto;">Visit the Registrar's office to be assigned your subjects and section for this term. Once
            they've done that, your schedule and balance will appear here and you can pay at the Cashier.</p>
    </div>

<?php else: ?>
    <div class="card">
        <div class="section-head">
            <h3 style="margin:0;"><?= e($sem['year_label'] . ' — ' . $sem['semester_name']) ?></h3>
            <span class="badge badge-<?= $statusBadge[$enrollment['status']] ?? 'neutral' ?>"
                style="font-size:0.9rem;"><?= e($enrollment['status']) ?></span>
        </div>
        <?php if ($enrollment['status'] === 'Payment Pending'): ?>
            <div class="alert alert-info" style="margin-bottom:0;">Your subjects have been assigned. Please proceed to the
                Cashier to settle your payment of &#8369;<?= number_format($balance, 2) ?>.</div>
        <?php elseif ($enrollment['status'] === 'Payment Verified'): ?>
            <div class="alert alert-info" style="margin-bottom:0;">Your payment has been verified. The Registrar is finalizing
                your enrollment.</div>
        <?php elseif ($enrollment['status'] === 'Enrolled'): ?>
            <div class="alert alert-success" style="margin-bottom:0;">You are officially enrolled. <a
                    href="<?= BASE_URL ?>/student/registration-form" target="_blank">View your Registration Form</a>.</div>
        <?php elseif ($enrollment['status'] === 'Rejected'): ?>
            <div class="alert alert-error" style="margin-bottom:0;">Your enrollment was
                rejected<?= $enrollment['rejection_reason'] ? ': ' . e($enrollment['rejection_reason']) : '.' ?> Please see the
                Registrar.</div>
        <?php endif; ?>
    </div>

    <div class="grid grid-4" style="margin-top:20px;">
        <div class="card stat-card">
            <div class="stat-label">Total Units</div>
            <div class="stat-value"><?= number_format($enrollment['total_units'], 1) ?></div>
        </div>
        <div class="card stat-card">
            <div class="stat-label">Total Due</div>
            <div class="stat-value" style="font-size:1.4rem;">
                &#8369;<?= number_format($enrollment['total_amount_due'], 2) ?></div>
        </div>
        <div class="card stat-card">
            <div class="stat-label">Paid</div>
            <div class="stat-value" style="font-size:1.4rem;">&#8369;<?= number_format($enrollment['total_paid'], 2) ?>
            </div>
        </div>
        <div class="card stat-card">
            <div class="stat-label">Balance</div>
            <div class="stat-value" style="font-size:1.4rem;">&#8369;<?= number_format($balance, 2) ?></div>
        </div>
    </div>

    <div class="card" style="margin-top:20px;">
        <h4>My Subjects &amp; Schedule</h4>
        <?php if ($subjects): ?>
            <table>
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Subject</th>
                        <th>Units</th>
                        <th>Section</th>
                        <th>Schedule</th>
                        <th>Teacher</th>
                        <th>Room</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($subjects as $s): ?>
                        <tr>
                            <td><?= e($s['subject_code']) ?></td>
                            <td><?= e($s['subject_name']) ?></td>
                            <td><?= number_format($s['units'], 1) ?></td>
                            <td><?= e($s['section_name']) ?></td>
                            <td><?= e(str_replace(',', '/', $s['day_of_week'])) ?>
                                <?= date('g:iA', strtotime($s['start_time'])) ?>–<?= date('g:iA', strtotime($s['end_time'])) ?></td>
                            <td><?= e($s['teacher_name'] ?? 'TBA') ?></td>
                            <td><?= e($s['room_name'] ?? 'TBA') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p class="helper-text" style="margin-top:10px;">Need a change to your subjects or section? Please see the
                Registrar's office.</p>
        <?php else: ?>
            <div class="empty-state">No subjects assigned yet.</div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>