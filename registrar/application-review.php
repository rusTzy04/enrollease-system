<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/mailer.php';
requireRole([ROLE_REGISTRAR]);

$applicationId = (int) ($_GET['id'] ?? 0);

$stmt = $pdo->prepare(
    "SELECT a.*, p.program_id, p.program_code, p.program_name, s.semester_id, s.semester_name, ay.year_label
     FROM enrollment_applications a
     JOIN programs p ON p.program_id = a.program_id
     JOIN semesters s ON s.semester_id = a.semester_id
     JOIN academic_years ay ON ay.academic_year_id = s.academic_year_id
     WHERE a.application_id = :id"
);
$stmt->execute([':id' => $applicationId]);
$app = $stmt->fetch();

if (!$app) {
    setFlash('error', 'Application not found.');
    redirect('/registrar/new-applications');
}

$errors = [];
$newCredentials = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'verify_requirement') {
        $reqId = (int) $_POST['requirement_id'];
        $pdo->prepare(
            "UPDATE application_requirements SET status = 'Verified', verified_by = :uid, verified_at = NOW() WHERE id = :id AND application_id = :aid"
        )->execute([':uid' => $_SESSION['user_id'], ':id' => $reqId, ':aid' => $applicationId]);
        logActivity($pdo, $_SESSION['user_id'], 'application_requirement_verified', "Application #{$applicationId}");

        // Students can proceed to payment once AT LEAST ONE requirement is verified — this lets
        // them pay while still following up on anything still missing, rather than blocking
        // payment until every single document is in hand.
        $verifiedCount = $pdo->prepare("SELECT COUNT(*) FROM application_requirements WHERE application_id = :aid AND status = 'Verified'");
        $verifiedCount->execute([':aid' => $applicationId]);
        if ((int) $verifiedCount->fetchColumn() >= 1) {
            $pdo->prepare("UPDATE enrollment_applications SET status = 'Payment Pending' WHERE application_id = :aid AND status = 'Requirements Pending'")
                ->execute([':aid' => $applicationId]);
        }
        setFlash('success', 'Requirement marked as verified.');
        redirect('/registrar/application-review?id=' . $applicationId);
    }

    if ($action === 'reject') {
        $reason = trim($_POST['reason'] ?? '');
        if ($reason === '') {
            $errors[] = 'Please provide a reason for rejection.';
        } else {
            $pdo->prepare("UPDATE enrollment_applications SET status = 'Rejected', rejection_reason = :r WHERE application_id = :aid")
                ->execute([':r' => $reason, ':aid' => $applicationId]);
            logActivity($pdo, $_SESSION['user_id'], 'application_rejected', "Application #{$applicationId}: {$reason}");
            setFlash('success', 'Application rejected.');
            redirect('/registrar/application-review?id=' . $applicationId);
        }
    }

    if ($action === 'approve_and_create_account') {
        $sectionId = (int) $_POST['section_id'];

        if ($app['status'] !== 'Payment Verified') {
            $errors[] = 'This application cannot be finalized until payment has been verified by the Cashier.';
        } else {
            // Confirm the chosen section actually belongs to this applicant's program/semester (defends against a tampered section_id)
            $secStmt = $pdo->prepare("SELECT * FROM sections WHERE section_id = :id AND program_id = :pid AND semester_id = :semid");
            $secStmt->execute([':id' => $sectionId, ':pid' => $app['program_id'], ':semid' => $app['semester_id']]);
            $section = $secStmt->fetch();

            if (!$section) {
                $errors[] = 'Please select a valid section for this program and semester.';
            } else {
                $schedStmt = $pdo->prepare(
                    "SELECT cs.schedule_id, cs.slots_taken, sub.units FROM class_schedules cs
                     JOIN subjects sub ON sub.subject_id = cs.subject_id
                     WHERE cs.section_id = :sid AND cs.status = 'Open'"
                );
                $schedStmt->execute([':sid' => $sectionId]);
                $schedules = $schedStmt->fetchAll();

                if (!$schedules) {
                    $errors[] = 'That section has no open class schedules yet — ask the Academic Scheduler to set them up first.';
                } elseif (array_filter($schedules, fn($s) => (int) $s['slots_taken'] >= (int) $section['max_slots'])) {
                    $errors[] = 'That section is already full.';
                }
            }

            if (empty($errors)) {
                $pdo->beginTransaction();

                $studentId = generateStudentId($pdo);
                $tempPassword = bin2hex(random_bytes(6));
                $hash = password_hash($tempPassword, PASSWORD_DEFAULT);

                $pdo->prepare(
                    "INSERT INTO users (role_id, student_id, first_name, last_name, email, phone, password_hash, is_active, email_verified)
                     VALUES (:role, :sid, :fn, :ln, :email, :phone, :hash, 1, 1)"
                )->execute([
                            ':role' => ROLE_STUDENT,
                            ':sid' => $studentId,
                            ':fn' => $app['first_name'],
                            ':ln' => $app['last_name'],
                            ':email' => $app['email'],
                            ':phone' => $app['phone'],
                            ':hash' => $hash,
                        ]);
                $newUserId = (int) $pdo->lastInsertId();

                $pdo->prepare(
                    "INSERT INTO student_profiles (user_id, program_id, year_level, address, birthdate, guardian_name, guardian_contact)
                     VALUES (:uid, :pid, 1, :addr, :bd, :gn, :gc)"
                )->execute([
                            ':uid' => $newUserId,
                            ':pid' => $app['program_id'],
                            ':addr' => $app['address'],
                            ':bd' => $app['birthdate'],
                            ':gn' => $app['guardian_name'],
                            ':gc' => $app['guardian_contact'],
                        ]);

                // Fees/tuition
                $totalUnits = array_sum(array_column($schedules, 'units'));
                $tuitionPerUnit = (float) ($pdo->query("SELECT amount FROM fee_structure WHERE fee_name = 'Tuition per Unit' AND is_active = 1 LIMIT 1")->fetchColumn() ?: 0);
                $fixedFees = (float) $pdo->query("SELECT COALESCE(SUM(amount),0) FROM fee_structure WHERE fee_type = 'Fixed' AND is_active = 1")->fetchColumn();
                $tuition = $totalUnits * $tuitionPerUnit;
                $totalDue = $tuition + $fixedFees;

                $pdo->prepare(
                    "INSERT INTO enrollments (student_id, semester_id, status, total_units, total_tuition, total_fees, total_amount_due, total_paid, submitted_at, approved_by, approved_at)
                     VALUES (:sid, :semid, 'Enrolled', :units, :tuition, :fees, :due, :paid, NOW(), :reg, NOW())"
                )->execute([
                            ':sid' => $newUserId,
                            ':semid' => $app['semester_id'],
                            ':units' => $totalUnits,
                            ':tuition' => $tuition,
                            ':fees' => $fixedFees,
                            ':due' => $totalDue,
                            ':paid' => $app['total_paid'],
                            ':reg' => $_SESSION['user_id'],
                        ]);
                $enrollmentId = (int) $pdo->lastInsertId();

                $insSub = $pdo->prepare("INSERT INTO enrollment_subjects (enrollment_id, schedule_id) VALUES (:eid, :sid)");
                $bumpSlots = $pdo->prepare("UPDATE class_schedules SET slots_taken = slots_taken + 1 WHERE schedule_id = :sid");
                foreach ($schedules as $sc) {
                    $insSub->execute([':eid' => $enrollmentId, ':sid' => $sc['schedule_id']]);
                    $bumpSlots->execute([':sid' => $sc['schedule_id']]);
                }

                // Carry over the verified requirements checklist
                $reqRows = $pdo->prepare("SELECT * FROM application_requirements WHERE application_id = :aid");
                $reqRows->execute([':aid' => $applicationId]);
                $insReq = $pdo->prepare(
                    "INSERT INTO enrollment_requirements (enrollment_id, requirement_type_id, status, received_at, verified_by, verified_at, notes)
                     VALUES (:eid, :rtid, :status, :ra, :vb, :va, :notes)"
                );
                foreach ($reqRows->fetchAll() as $r) {
                    $insReq->execute([
                        ':eid' => $enrollmentId,
                        ':rtid' => $r['requirement_type_id'],
                        ':status' => $r['status'],
                        ':ra' => $r['received_at'],
                        ':vb' => $r['verified_by'],
                        ':va' => $r['verified_at'],
                        ':notes' => $r['notes'],
                    ]);
                }

                // Carry over payment records
                $payRows = $pdo->prepare("SELECT * FROM application_payments WHERE application_id = :aid");
                $payRows->execute([':aid' => $applicationId]);
                $insPay = $pdo->prepare(
                    "INSERT INTO payments (enrollment_id, receipt_number, amount_paid, payment_method, received_by, payment_date, is_verified, remarks)
                     VALUES (:eid, :rn, :amt, :method, :cashier, :date, 1, :remarks)"
                );
                foreach ($payRows->fetchAll() as $p) {
                    $insPay->execute([
                        ':eid' => $enrollmentId,
                        ':rn' => $p['receipt_number'],
                        ':amt' => $p['amount_paid'],
                        ':method' => $p['payment_method'],
                        ':cashier' => $p['received_by'],
                        ':date' => $p['payment_date'],
                        ':remarks' => $p['remarks'],
                    ]);
                }

                $pdo->prepare(
                    "UPDATE enrollment_applications SET status = 'Approved', converted_user_id = :uid, approved_by = :reg, approved_at = NOW() WHERE application_id = :aid"
                )->execute([':uid' => $newUserId, ':reg' => $_SESSION['user_id'], ':aid' => $applicationId]);

                $pdo->commit();

                logActivity($pdo, $_SESSION['user_id'], 'application_approved_account_created', "Application #{$applicationId} -> Student {$studentId}");
                sendApplicationApprovedEmail($pdo, $app['email'], $app['first_name'], $studentId, $tempPassword);

                $_SESSION['new_credentials'] = ['email' => $app['email'], 'password' => $tempPassword, 'student_id' => $studentId];
                setFlash('success', "Enrollment finalized. Student ID: {$studentId}");
                redirect('/registrar/application-review?id=' . $applicationId);
            }
        }
    }

    // Refresh
    $stmt->execute([':id' => $applicationId]);
    $app = $stmt->fetch();
}

$newCredentials = $_SESSION['new_credentials'] ?? null;
unset($_SESSION['new_credentials']);

$reqStmt = $pdo->prepare(
    "SELECT ar.id, rt.requirement_name, ar.status FROM application_requirements ar
     JOIN requirement_types rt ON rt.requirement_type_id = ar.requirement_type_id
     WHERE ar.application_id = :aid"
);
$reqStmt->execute([':aid' => $applicationId]);
$requirements = $reqStmt->fetchAll();

$payStmt = $pdo->prepare(
    "SELECT p.*, CONCAT(u.first_name,' ',u.last_name) AS cashier_name FROM application_payments p
     JOIN users u ON u.user_id = p.received_by WHERE p.application_id = :aid ORDER BY p.payment_date DESC"
);
$payStmt->execute([':aid' => $applicationId]);
$payments = $payStmt->fetchAll();

$sections = [];
if ($app['status'] === 'Payment Verified') {
    $secStmt = $pdo->prepare("SELECT section_id, section_name, max_slots FROM sections WHERE program_id = :pid AND semester_id = :semid AND year_level = 1");
    $secStmt->execute([':pid' => $app['program_id'], ':semid' => $app['semester_id']]);
    $sections = $secStmt->fetchAll();
}

$statusBadge = [
    'Requirements Pending' => 'warning',
    'Requirements Verified' => 'info',
    'Payment Pending' => 'warning',
    'Payment Verified' => 'info',
    'Approved' => 'success',
    'Rejected' => 'danger',
];

$pageTitle = 'Application: ' . $app['first_name'] . ' ' . $app['last_name'];
require_once __DIR__ . '/../includes/header.php';
?>

<?php foreach ($errors as $err): ?>
    <div class="alert alert-error"><?= e($err) ?></div><?php endforeach; ?>

<?php if ($newCredentials): ?>
    <div class="card" style="border: 2px solid var(--brass); background: var(--brass-tint); margin-bottom:20px;">
        <h4 style="margin-bottom:6px;">Account created — credentials emailed to the student</h4>
        <p class="helper-text" style="margin-bottom:14px;">These were just sent to the student's email. This on-screen copy
            is shown only once as a backup, in case email delivery fails or SMTP isn't configured yet — you can also hand it
            to them directly.</p>
        <div style="display:flex; gap:24px; flex-wrap:wrap;">
            <div><strong>Student ID:</strong> <code><?= e($newCredentials['student_id']) ?></code></div>
            <div><strong>Email:</strong> <code><?= e($newCredentials['email']) ?></code></div>
            <div><strong>Temporary Password:</strong> <code
                    style="font-size:1.05rem;"><?= e($newCredentials['password']) ?></code></div>
        </div>
    </div>
<?php endif; ?>

<div class="card">
    <div class="grid grid-3">
        <p><strong>Reference #:</strong> <?= e($app['reference_no']) ?><br><strong>Name:</strong>
            <?= e($app['first_name'] . ' ' . $app['last_name']) ?><br><strong>Email:</strong> <?= e($app['email']) ?>
        </p>
        <p><strong>Program:</strong> <?= e($app['program_code']) ?><br><strong>Semester:</strong>
            <?= e($app['year_label'] . ' — ' . $app['semester_name']) ?></p>
        <p><strong>Status:</strong> <span
                class="badge badge-<?= $statusBadge[$app['status']] ?? 'neutral' ?>"><?= e($app['status']) ?></span>
            <?php if ($app['status'] === 'Rejected' && $app['rejection_reason']): ?><br><span
                    class="helper-text">Reason: <?= e($app['rejection_reason']) ?></span><?php endif; ?></p>
    </div>
</div>

<div class="grid grid-2" style="margin-top:20px;">
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
                                <form method="POST"><?= csrfField() ?>
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
    <div class="card">
        <h4>Payments (&#8369;<?= number_format($app['total_paid'], 2) ?> of &#8369;<?= number_format(MIN_PAYMENT, 2) ?>
            min.)</h4>
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
            <div class="empty-state">No payments recorded yet.</div>
        <?php endif; ?>
    </div>
</div>

<?php if ($app['status'] === 'Payment Verified'):
    $pendingReqCount = count(array_filter($requirements, fn($r) => $r['status'] !== 'Verified')); ?>
    <div class="card" style="margin-top:20px;">
        <h4>Finalize Enrollment & Create Account</h4>
        <p class="helper-text">Choose the freshman section this student will join — every subject scheduled for that section
            will be added to their enrollment automatically.</p>
        <?php if ($pendingReqCount > 0): ?>
            <div class="alert alert-info"><?= $pendingReqCount ?> requirement(s) are still unverified. The student was allowed
                to pay early — remind them to complete these before or shortly after enrollment.</div>
        <?php endif; ?>
        <?php if ($sections): ?>
            <form method="POST" style="display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap;">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="approve_and_create_account">
                <div class="field"><label>Section</label>
                    <select name="section_id" required>
                        <?php foreach ($sections as $s): ?>
                            <option value="<?= (int) $s['section_id'] ?>"><?= e($s['section_name']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="field"><label style="visibility:hidden;">&nbsp;</label>
                    <button type="submit" class="btn btn-brass"
                        data-confirm="Finalize enrollment and create this student's account?">Finalize & Create Account</button>
                </div>
            </form>
        <?php else: ?>
            <div class="empty-state">No Year 1 sections exist yet for <?= e($app['program_code']) ?> this semester. Ask the
                Academic Scheduler to create one first.</div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php if ($app['status'] === 'Approved' && $app['converted_user_id']): ?>
    <div class="card" style="margin-top:20px;">
        <?php
        $enrStmt = $pdo->prepare("SELECT enrollment_id FROM enrollments WHERE student_id = :uid AND semester_id = :semid");
        $enrStmt->execute([':uid' => $app['converted_user_id'], ':semid' => $app['semester_id']]);
        $finalEnrollmentId = $enrStmt->fetchColumn();
        ?>
        <div class="alert alert-success" style="margin:0;">This applicant is enrolled.
            <?php if ($finalEnrollmentId): ?>
                <a href="<?= BASE_URL ?>/registrar/print-registration-form?enrollment_id=<?= (int) $finalEnrollmentId ?>"
                    target="_blank">View / print their Registration Form</a>.
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<?php if (!in_array($app['status'], ['Approved', 'Rejected'], true)): ?>
    <div class="card" style="margin-top:20px;">
        <h4>Reject Application</h4>
        <form method="POST" style="display:flex; gap:8px;">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="reject">
            <input type="text" name="reason" placeholder="Reason for rejection" style="width:280px;">
            <button type="submit" class="btn btn-danger" data-confirm="Reject this application?">Reject</button>
        </form>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>