<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole([ROLE_STUDENT]);

$studentId = $_SESSION['user_id'];

$stmt = $pdo->prepare(
    "SELECT e.* FROM enrollments e
     JOIN semesters s ON s.semester_id = e.semester_id
     WHERE e.student_id = :sid ORDER BY e.created_at DESC LIMIT 1"
);
$stmt->execute([':sid' => $studentId]);
$enrollment = $stmt->fetch();

$requirements = [];
$history = [];
$subjects = [];
if ($enrollment) {
    $stmt = $pdo->prepare(
        "SELECT rt.requirement_name, er.status, er.received_at, er.verified_at
         FROM enrollment_requirements er JOIN requirement_types rt ON rt.requirement_type_id = er.requirement_type_id
         WHERE er.enrollment_id = :eid"
    );
    $stmt->execute([':eid' => $enrollment['enrollment_id']]);
    $requirements = $stmt->fetchAll();

    $stmt = $pdo->prepare("SELECT * FROM enrollment_status_history WHERE enrollment_id = :eid ORDER BY changed_at ASC");
    $stmt->execute([':eid' => $enrollment['enrollment_id']]);
    $history = $stmt->fetchAll();

    $stmt = $pdo->prepare(
        "SELECT sub.subject_code, sub.subject_name, sub.units, sec.section_name
         FROM enrollment_subjects es
         JOIN class_schedules cs ON cs.schedule_id = es.schedule_id
         JOIN subjects sub ON sub.subject_id = cs.subject_id
         JOIN sections sec ON sec.section_id = cs.section_id
         WHERE es.enrollment_id = :eid"
    );
    $stmt->execute([':eid' => $enrollment['enrollment_id']]);
    $subjects = $stmt->fetchAll();
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

$pageTitle = 'Enrollment Status';
require_once __DIR__ . '/../includes/header.php';
?>

<?php if (!$enrollment): ?>
    <div class="card empty-state">You don't have an enrollment record yet. Visit the Registrar's office to be assigned your
        subjects for this term, or see <a href="<?= BASE_URL ?>/student/enroll">My Enrollment</a> for details.</div>
<?php else: ?>
    <div class="card">
        <div class="section-head">
            <h3 style="margin:0;">Current status</h3>
            <span class="badge badge-<?= $statusBadge[$enrollment['status']] ?? 'neutral' ?>"
                style="font-size:0.9rem;"><?= e($enrollment['status']) ?></span>
        </div>
        <?php if ($enrollment['status'] === 'Rejected' && $enrollment['rejection_reason']): ?>
            <div class="alert alert-error">Reason: <?= e($enrollment['rejection_reason']) ?></div>
        <?php endif; ?>
        <p class="helper-text">Submitted:
            <?= $enrollment['submitted_at'] ? date('M j, Y g:ia', strtotime($enrollment['submitted_at'])) : 'Not yet submitted' ?>
        </p>
    </div>

    <div class="grid grid-2" style="margin-top:20px;">
        <div class="card">
            <h4>Enrolled Subjects</h4>
            <?php if ($subjects): ?>
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
            <?php else: ?>
                <p class="helper-text">No subjects selected yet.</p><?php endif; ?>
        </div>
        <div class="card">
            <h4>Requirements Checklist</h4>
            <?php if ($requirements): ?>
                <table>
                    <thead>
                        <tr>
                            <th>Requirement</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($requirements as $r): ?>
                            <tr>
                                <td><?= e($r['requirement_name']) ?></td>
                                <td><span
                                        class="badge badge-<?= $r['status'] === 'Verified' ? 'success' : ($r['status'] === 'Received' ? 'warning' : 'danger') ?>"><?= e($r['status']) ?></span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <p class="helper-text" style="margin-top:10px;">Submit physical/original copies of these documents to the
                    Registrar's office.</p>
            <?php else: ?>
                <p class="helper-text">Requirements will appear here after you submit your enrollment.</p><?php endif; ?>
        </div>
    </div>

    <div class="card" style="margin-top:20px;">
        <h4>Status History</h4>
        <?php if ($history): ?>
            <table>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Change</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (array_reverse($history) as $h): ?>
                        <tr>
                            <td><?= date('M j, Y g:ia', strtotime($h['changed_at'])) ?></td>
                            <td><?= e($h['old_status'] ?? '—') ?> &rarr; <?= e($h['new_status']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p class="helper-text">No history yet.</p><?php endif; ?>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>