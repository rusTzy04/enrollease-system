<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/mailer.php';
requireRole([ROLE_REGISTRAR]);

$enrollmentId = (int) ($_GET['id'] ?? 0);

$stmt = $pdo->prepare(
    "SELECT e.*, u.first_name, u.last_name, u.student_id AS student_number, u.email, p.program_name, sp.year_level
     FROM enrollments e JOIN users u ON u.user_id = e.student_id
     LEFT JOIN student_profiles sp ON sp.user_id = u.user_id
     LEFT JOIN programs p ON p.program_id = sp.program_id
     WHERE e.enrollment_id = :id"
);
$stmt->execute([':id' => $enrollmentId]);
$enrollment = $stmt->fetch();

if (!$enrollment) {
    setFlash('error', 'Application not found.');
    redirect('/registrar/applications');
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'verify_requirement') {
        $reqId = (int) $_POST['requirement_id'];
        $pdo->prepare(
            "UPDATE enrollment_requirements SET status = 'Verified', verified_by = :uid, verified_at = NOW() WHERE id = :id AND enrollment_id = :eid"
        )->execute([':uid' => $_SESSION['user_id'], ':id' => $reqId, ':eid' => $enrollmentId]);
        logActivity($pdo, $_SESSION['user_id'], 'requirement_verified', "Enrollment #{$enrollmentId}, requirement #{$reqId}");

        // If all requirements verified, advance status
        $remaining = $pdo->prepare("SELECT COUNT(*) FROM enrollment_requirements WHERE enrollment_id = :eid AND status != 'Verified'");
        $remaining->execute([':eid' => $enrollmentId]);
        if ((int) $remaining->fetchColumn() === 0) {
            $pdo->prepare("UPDATE enrollments SET status = 'Requirements Verified' WHERE enrollment_id = :eid")->execute([':eid' => $enrollmentId]);
            $pdo->prepare("INSERT INTO enrollment_status_history (enrollment_id, old_status, new_status, changed_by) VALUES (:eid, 'Requirements Pending', 'Requirements Verified', :uid)")
                ->execute([':eid' => $enrollmentId, ':uid' => $_SESSION['user_id']]);
            notify($pdo, $enrollment['student_id'], 'Requirements verified', 'All requirements verified. Please proceed to the Cashier for payment.');
            $pdo->prepare("UPDATE enrollments SET status = 'Payment Pending' WHERE enrollment_id = :eid")->execute([':eid' => $enrollmentId]);
        }
        setFlash('success', 'Requirement marked as verified.');
        redirect('/registrar/application-detail?id=' . $enrollmentId);
    }

    if ($action === 'approve') {
        if ($enrollment['status'] !== 'Payment Verified') {
            $errors[] = 'This application cannot be approved until payment has been verified by the Cashier.';
        } else {
            $pdo->beginTransaction();
            $pdo->prepare(
                "UPDATE enrollments SET status = 'Enrolled', approved_by = :uid, approved_at = NOW() WHERE enrollment_id = :eid"
            )->execute([':uid' => $_SESSION['user_id'], ':eid' => $enrollmentId]);
            $pdo->prepare(
                "INSERT INTO enrollment_status_history (enrollment_id, old_status, new_status, changed_by) VALUES (:eid, :old, 'Enrolled', :uid)"
            )->execute([':eid' => $enrollmentId, ':old' => $enrollment['status'], ':uid' => $_SESSION['user_id']]);
            $pdo->commit();

            notify($pdo, $enrollment['student_id'], 'Enrollment approved', 'Congratulations! You are officially enrolled. Your registration form is now available.');
            sendEnrollmentApprovedEmail($pdo, $enrollment['email'], $enrollment['first_name']);
            logActivity($pdo, $_SESSION['user_id'], 'enrollment_approved', "Enrollment #{$enrollmentId}");
            setFlash('success', 'Enrollment finalized and approved.');
            redirect('/registrar/application-detail?id=' . $enrollmentId);
        }
    }

    if ($action === 'reject') {
        $reason = trim($_POST['reason'] ?? '');
        if ($reason === '') {
            $errors[] = 'Please provide a reason for rejection.';
        } else {
            $pdo->prepare(
                "UPDATE enrollments SET status = 'Rejected', rejection_reason = :reason WHERE enrollment_id = :eid"
            )->execute([':reason' => $reason, ':eid' => $enrollmentId]);
            $pdo->prepare(
                "INSERT INTO enrollment_status_history (enrollment_id, old_status, new_status, changed_by) VALUES (:eid, :old, 'Rejected', :uid)"
            )->execute([':eid' => $enrollmentId, ':old' => $enrollment['status'], ':uid' => $_SESSION['user_id']]);

            notify($pdo, $enrollment['student_id'], 'Enrollment rejected', "Reason: {$reason}");
            sendEnrollmentRejectedEmail($pdo, $enrollment['email'], $enrollment['first_name'], $reason);
            logActivity($pdo, $_SESSION['user_id'], 'enrollment_rejected', "Enrollment #{$enrollmentId}: {$reason}");
            setFlash('success', 'Application rejected.');
            redirect('/registrar/application-detail?id=' . $enrollmentId);
        }
    }

    // Refresh enrollment after any action
    $stmt->execute([':id' => $enrollmentId]);
    $enrollment = $stmt->fetch();
}

$reqStmt = $pdo->prepare(
    "SELECT er.id, rt.requirement_name, er.status FROM enrollment_requirements er
     JOIN requirement_types rt ON rt.requirement_type_id = er.requirement_type_id
     WHERE er.enrollment_id = :eid"
);
$reqStmt->execute([':eid' => $enrollmentId]);
$requirements = $reqStmt->fetchAll();

$subStmt = $pdo->prepare(
    "SELECT sub.subject_code, sub.subject_name, sub.units, sec.section_name
     FROM enrollment_subjects es JOIN class_schedules cs ON cs.schedule_id = es.schedule_id
     JOIN subjects sub ON sub.subject_id = cs.subject_id JOIN sections sec ON sec.section_id = cs.section_id
     WHERE es.enrollment_id = :eid"
);
$subStmt->execute([':eid' => $enrollmentId]);
$subjects = $subStmt->fetchAll();

$payStmt = $pdo->prepare(
    "SELECT p.*, CONCAT(u.first_name,' ',u.last_name) AS cashier_name FROM payments p
     JOIN users u ON u.user_id = p.received_by WHERE p.enrollment_id = :eid ORDER BY p.payment_date DESC"
);
$payStmt->execute([':eid' => $enrollmentId]);
$payments = $payStmt->fetchAll();

$pageTitle = 'Application: ' . $enrollment['first_name'] . ' ' . $enrollment['last_name'];
require_once __DIR__ . '/../includes/header.php';
?>

<?php foreach ($errors as $err): ?>
    <div class="alert alert-error"><?= e($err) ?></div><?php endforeach; ?>

<div class="card">
    <div class="grid grid-3">
        <p><strong>Student:</strong>
            <?= e($enrollment['first_name'] . ' ' . $enrollment['last_name']) ?><br><strong>ID:</strong>
            <?= e($enrollment['student_number']) ?></p>
        <p><strong>Program:</strong> <?= e($enrollment['program_name'] ?? '—') ?><br><strong>Year:</strong>
            <?= (int) $enrollment['year_level'] ?></p>
        <p><strong>Status:</strong> <span class="badge badge-info"><?= e($enrollment['status']) ?></span></p>
    </div>
</div>

<div class="grid grid-2" style="margin-top:20px;">
    <div class="card">
        <h4>Subjects</h4>
        <table>
            <thead>
                <tr>
                    <th>Code</th>
                    <th>Subject</th>
                    <th>Units</th>
                    <th>Section</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($subjects as $s): ?>
                    <tr>
                        <td><?= e($s['subject_code']) ?></td>
                        <td><?= e($s['subject_name']) ?></td>
                        <td><?= number_format($s['units'], 1) ?></td>
                        <td><?= e($s['section_name']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="card">
        <h4>Requirements</h4>
        <table>
            <thead>
                <tr>
                    <th>Requirement</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($requirements as $r): ?>
                    <tr>
                        <td><?= e($r['requirement_name']) ?></td>
                        <td><span
                                class="badge badge-<?= $r['status'] === 'Verified' ? 'success' : 'warning' ?>"><?= e($r['status']) ?></span>
                        </td>
                        <td>
                            <?php if ($r['status'] !== 'Verified'): ?>
                                <form method="POST" style="display:inline;">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="verify_requirement">
                                    <input type="hidden" name="requirement_id" value="<?= (int) $r['id'] ?>">
                                    <button type="submit" class="btn btn-outline btn-sm">Mark verified</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card" style="margin-top:20px;">
    <h4>Payments (&#8369;<?= number_format($enrollment['total_paid'], 2) ?> of
        &#8369;<?= number_format($enrollment['total_amount_due'], 2) ?>)</h4>
    <?php if ($payments): ?>
        <table>
            <thead>
                <tr>
                    <th>Receipt #</th>
                    <th>Date</th>
                    <th>Amount</th>
                    <th>By</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($payments as $p): ?>
                    <tr>
                        <td><?= e($p['receipt_number']) ?></td>
                        <td><?= date('M j, Y', strtotime($p['payment_date'])) ?></td>
                        <td>&#8369;<?= number_format($p['amount_paid'], 2) ?></td>
                        <td><?= e($p['cashier_name']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <div class="empty-state">No payments recorded against this enrollment yet. If the Cashier says they already
            collected payment, double check they used <strong>Cashier → Walk-in Payment</strong> (not "New Applicant
            Payment," which is a separate table for pre-account applicants) and that they searched for and selected this
            exact student.</div>
    <?php endif; ?>
</div>

<?php if (in_array($enrollment['status'], ['Enrolled', 'Rejected'], true)): ?>
    <div class="card" style="margin-top:20px;">
        <h4>Decision</h4>
        <?php if ($enrollment['status'] === 'Enrolled'): ?>
            <div class="alert alert-success" style="margin:0;">This student is already fully enrolled — approved on
                <?= $enrollment['approved_at'] ? date('M j, Y g:ia', strtotime($enrollment['approved_at'])) : '—' ?>. <a
                    href="<?= BASE_URL ?>/registrar/print-registration-form?enrollment_id=<?= $enrollmentId ?>"
                    target="_blank">View/print their Registration Form</a>.</div>
        <?php else: ?>
            <div class="alert alert-error" style="margin:0;">This application was
                rejected<?= $enrollment['rejection_reason'] ? ': ' . e($enrollment['rejection_reason']) : '.' ?></div>
        <?php endif; ?>
    </div>
<?php else: ?>
    <div class="card" style="margin-top:20px;">
        <h4>Decision</h4>
        <div style="display:flex; gap:12px; flex-wrap:wrap; align-items:flex-start;">
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="approve">
                <button type="submit" class="btn btn-brass" data-confirm="Approve and finalize this enrollment?"
                    <?= $enrollment['status'] !== 'Payment Verified' ? 'disabled' : '' ?>>Approve & Finalize</button>
            </form>
            <form method="POST" style="display:flex; gap:8px;">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="reject">
                <input type="text" name="reason" placeholder="Reason for rejection" style="width:260px;">
                <button type="submit" class="btn btn-danger" data-confirm="Reject this application?">Reject</button>
            </form>
        </div>
        <?php if ($enrollment['status'] !== 'Payment Verified'): ?>
            <p class="helper-text" style="margin-top:10px;">Approval is enabled once the Cashier has verified payment. Current
                status: <strong><?= e($enrollment['status']) ?></strong>.</p>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>1