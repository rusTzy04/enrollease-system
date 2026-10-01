<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole([ROLE_REGISTRAR]);

$errors = [];

$sem = $pdo->query(
    "SELECT s.semester_id, s.semester_name, ay.year_label
     FROM semesters s JOIN academic_years ay ON ay.academic_year_id = s.academic_year_id
     WHERE s.is_active = 1 LIMIT 1"
)->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $sem) {
    verifyCsrf();
    $studentUserId = (int) $_POST['student_user_id'];
    $sectionId = (int) $_POST['section_id'];

    // Confirm the student exists and has no enrollment yet for this semester
    $stStmt = $pdo->prepare(
        "SELECT u.user_id, u.first_name, u.last_name, sp.program_id, sp.year_level
         FROM users u JOIN student_profiles sp ON sp.user_id = u.user_id
         WHERE u.user_id = :uid AND u.role_id = :role AND u.is_active = 1"
    );
    $stStmt->execute([':uid' => $studentUserId, ':role' => ROLE_STUDENT]);
    $student = $stStmt->fetch();

    if (!$student) {
        $errors[] = 'Student not found.';
    }

    if (!$errors) {
        $dupe = $pdo->prepare(
            "SELECT COUNT(*) FROM enrollments WHERE student_id = :uid AND semester_id = :sid AND status NOT IN ('Rejected','Cancelled')"
        );
        $dupe->execute([':uid' => $studentUserId, ':sid' => $sem['semester_id']]);
        if ((int) $dupe->fetchColumn() > 0) {
            $errors[] = 'This student already has an enrollment record for the current semester.';
        }
    }

    // Prior-term unpaid balance blocks re-enrollment
    if (!$errors) {
        $balStmt = $pdo->prepare(
            "SELECT COALESCE(SUM(total_amount_due - total_paid),0) FROM enrollments
             WHERE student_id = :uid AND semester_id != :sid
               AND status NOT IN ('Rejected','Cancelled','Draft') AND (total_amount_due - total_paid) > 0"
        );
        $balStmt->execute([':uid' => $studentUserId, ':sid' => $sem['semester_id']]);
        $priorBalance = (float) $balStmt->fetchColumn();
        if ($priorBalance > 0) {
            $errors[] = 'This student has an unpaid balance of ₱' . number_format($priorBalance, 2) . ' from a previous term. It must be settled at the Cashier before re-enrolling.';
        }
    }

    // Validate the section belongs to the student's program/year and this semester
    $schedules = [];
    $section = null;
    if (!$errors) {
        $secStmt = $pdo->prepare(
            "SELECT * FROM sections WHERE section_id = :id AND program_id = :pid AND semester_id = :semid AND year_level = :yl"
        );
        $secStmt->execute([
            ':id' => $sectionId,
            ':pid' => $student['program_id'],
            ':semid' => $sem['semester_id'],
            ':yl' => $student['year_level'],
        ]);
        $section = $secStmt->fetch();

        if (!$section) {
            $errors[] = 'Please choose a section that matches this student\'s program and year level.';
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
    }

    if (!$errors) {
        $pdo->beginTransaction();

        $totalUnits = array_sum(array_column($schedules, 'units'));
        $tuitionPerUnit = (float) ($pdo->query("SELECT amount FROM fee_structure WHERE fee_name = 'Tuition per Unit' AND is_active = 1 LIMIT 1")->fetchColumn() ?: 0);
        $fixedFees = (float) $pdo->query("SELECT COALESCE(SUM(amount),0) FROM fee_structure WHERE fee_type = 'Fixed' AND is_active = 1")->fetchColumn();
        $tuition = $totalUnits * $tuitionPerUnit;
        $totalDue = $tuition + $fixedFees;

        // Returning students skip the requirements stage entirely — straight to payment.
        $pdo->prepare(
            "INSERT INTO enrollments (student_id, semester_id, status, total_units, total_tuition, total_fees, total_amount_due, submitted_at)
             VALUES (:sid, :semid, 'Payment Pending', :units, :tuition, :fees, :due, NOW())"
        )->execute([
                    ':sid' => $studentUserId,
                    ':semid' => $sem['semester_id'],
                    ':units' => $totalUnits,
                    ':tuition' => $tuition,
                    ':fees' => $fixedFees,
                    ':due' => $totalDue,
                ]);
        $enrollmentId = (int) $pdo->lastInsertId();

        $insSub = $pdo->prepare("INSERT INTO enrollment_subjects (enrollment_id, schedule_id) VALUES (:eid, :sid)");
        $bumpSlots = $pdo->prepare("UPDATE class_schedules SET slots_taken = slots_taken + 1 WHERE schedule_id = :sid");
        foreach ($schedules as $sc) {
            $insSub->execute([':eid' => $enrollmentId, ':sid' => $sc['schedule_id']]);
            $bumpSlots->execute([':sid' => $sc['schedule_id']]);
        }

        $pdo->prepare(
            "INSERT INTO enrollment_status_history (enrollment_id, old_status, new_status, changed_by) VALUES (:eid, NULL, 'Payment Pending', :uid)"
        )->execute([':eid' => $enrollmentId, ':uid' => $_SESSION['user_id']]);

        $pdo->commit();

        notify(
            $pdo,
            $studentUserId,
            'Enrolled in ' . $section['section_name'],
            'The Registrar has assigned your subjects for this term. Please proceed to the Cashier to settle your payment.'
        );
        logActivity($pdo, $_SESSION['user_id'], 'student_reenrolled', "Student #{$studentUserId} into section #{$sectionId}");

        setFlash('success', "{$student['first_name']} {$student['last_name']} has been enrolled in {$section['section_name']}. They can now pay at the Cashier.");
        redirect('/registrar/enroll-student');
    }
}

// Students eligible for re-enrollment this term
$search = trim($_GET['q'] ?? '');
$eligible = [];
if ($sem) {
    $where = "u.role_id = :role AND u.is_active = 1
              AND NOT EXISTS (SELECT 1 FROM enrollments e2 WHERE e2.student_id = u.user_id AND e2.semester_id = :semid AND e2.status NOT IN ('Rejected','Cancelled'))";
    $params = [':role' => ROLE_STUDENT, ':semid' => $sem['semester_id']];
    if ($search !== '') {
        $where .= " AND (u.student_id LIKE :q1 OR u.first_name LIKE :q2 OR u.last_name LIKE :q3)";
        $like = "%{$search}%";
        $params[':q1'] = $like;
        $params[':q2'] = $like;
        $params[':q3'] = $like;
    }

    $stmt = $pdo->prepare(
        "SELECT u.user_id, u.student_id, u.first_name, u.last_name, sp.program_id, sp.year_level, p.program_code,
                (SELECT COALESCE(SUM(e3.total_amount_due - e3.total_paid),0) FROM enrollments e3
                 WHERE e3.student_id = u.user_id AND e3.semester_id != :semid2
                   AND e3.status NOT IN ('Rejected','Cancelled','Draft') AND (e3.total_amount_due - e3.total_paid) > 0
                ) AS prior_balance
         FROM users u
         JOIN student_profiles sp ON sp.user_id = u.user_id
         LEFT JOIN programs p ON p.program_id = sp.program_id
         WHERE {$where}
         ORDER BY u.last_name LIMIT 200"
    );
    $params[':semid2'] = $sem['semester_id'];
    $stmt->execute($params);
    $eligible = $stmt->fetchAll();

    // Sections available this term, grouped so each student only sees matching ones
    $secStmt = $pdo->prepare(
        "SELECT sec.section_id, sec.section_name, sec.program_id, sec.year_level, sec.max_slots,
                (SELECT COUNT(*) FROM class_schedules cs WHERE cs.section_id = sec.section_id AND cs.status = 'Open') AS schedule_count
         FROM sections sec WHERE sec.semester_id = :semid ORDER BY sec.section_name"
    );
    $secStmt->execute([':semid' => $sem['semester_id']]);
    $allSections = $secStmt->fetchAll();
} else {
    $allSections = [];
}

$pageTitle = 'Enroll a Returning Student';
require_once __DIR__ . '/../includes/header.php';
?>

<?php foreach ($errors as $err): ?>
    <div class="alert alert-error"><?= e($err) ?></div><?php endforeach; ?>

<?php if (!$sem): ?>
    <div class="alert alert-info">No active semester is set. Ask the Academic Scheduler to set one first.</div>
<?php else: ?>

    <div class="card" style="margin-bottom:20px;">
        <strong>Enrolling for:</strong> <?= e($sem['year_label'] . ' — ' . $sem['semester_name']) ?>
        <p class="helper-text" style="margin:6px 0 0;">Choose a section for each student — every subject scheduled for that
            section is added automatically, and they move straight to payment (returning students don't resubmit
            requirements).</p>
    </div>

    <form method="GET" class="toolbar">
        <input type="text" name="q" placeholder="Search by student ID or name..." value="<?= e($search) ?>"
            style="min-width:280px;">
        <button type="submit" class="btn btn-outline btn-sm">Search</button>
        <?php if ($search !== ''): ?><a href="<?= BASE_URL ?>/registrar/enroll-student"
                class="btn btn-outline btn-sm">Clear</a><?php endif; ?>
    </form>

    <div class="card">
        <?php if ($eligible): ?>
            <table>
                <thead>
                    <tr>
                        <th>Student ID</th>
                        <th>Name</th>
                        <th>Program</th>
                        <th>Year</th>
                        <th>Assign Section</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($eligible as $s):
                        $matching = array_filter(
                            $allSections,
                            fn($sec) =>
                                (int) $sec['program_id'] === (int) $s['program_id'] && (int) $sec['year_level'] === (int) $s['year_level'] && (int) $sec['schedule_count'] > 0
                        );
                        ?>
                        <tr>
                            <td><?= e($s['student_id']) ?></td>
                            <td><?= e($s['first_name'] . ' ' . $s['last_name']) ?></td>
                            <td><?= e($s['program_code'] ?? '—') ?></td>
                            <td><?= (int) $s['year_level'] ?></td>
                            <td>
                                <?php if ($s['prior_balance'] > 0): ?>
                                    <span class="badge badge-danger">Unpaid balance ₱<?= number_format($s['prior_balance'], 2) ?> —
                                        settle at Cashier first</span>
                                <?php elseif (!$matching): ?>
                                    <span class="helper-text">No section with schedules exists for <?= e($s['program_code']) ?> Year
                                        <?= (int) $s['year_level'] ?> this term.</span>
                                <?php else: ?>
                                    <form method="POST" style="display:flex; gap:8px;">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="student_user_id" value="<?= (int) $s['user_id'] ?>">
                                        <select name="section_id" required style="width:auto; min-width:130px;">
                                            <?php foreach ($matching as $sec): ?>
                                                <option value="<?= (int) $sec['section_id'] ?>"><?= e($sec['section_name']) ?>
                                                    (<?= (int) $sec['schedule_count'] ?> subjects)</option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="submit" class="btn btn-brass btn-sm"
                                            data-confirm="Enroll <?= e($s['first_name']) ?> into this section?">Enroll</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div class="empty-state">
                <?= $search !== '' ? 'No matching students awaiting re-enrollment.' : 'Every active student already has an enrollment record for this term.' ?>
            </div>
        <?php endif; ?>
    </div>

<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>