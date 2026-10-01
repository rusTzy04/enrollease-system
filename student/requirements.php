<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole([ROLE_STUDENT]);

$studentId = $_SESSION['user_id'];


$masterChecklist = $pdo->query(
    "SELECT requirement_name, is_required FROM requirement_types ORDER BY is_required DESC, requirement_name"
)->fetchAll();


$stmt = $pdo->prepare(
    "SELECT rt.requirement_name, er.status, er.notes, ay.year_label, s.semester_name
     FROM enrollment_requirements er
     JOIN requirement_types rt ON rt.requirement_type_id = er.requirement_type_id
     JOIN enrollments e ON e.enrollment_id = er.enrollment_id
     JOIN semesters s ON s.semester_id = e.semester_id
     JOIN academic_years ay ON ay.academic_year_id = s.academic_year_id
     WHERE e.student_id = :sid
     ORDER BY e.created_at DESC, rt.requirement_name"
);
$stmt->execute([':sid' => $studentId]);
$myRequirements = $stmt->fetchAll();

$pending = array_filter($myRequirements, fn($r) => $r['status'] !== 'Verified');
$verified = array_filter($myRequirements, fn($r) => $r['status'] === 'Verified');

$pageTitle = 'Requirements';
require_once __DIR__ . '/../includes/header.php';
?>

<?php if (!empty($pending)): ?>
    <div class="card" style="border: 2px solid var(--warning); background: var(--warning-tint); margin-bottom:20px;">
        <h4 style="margin-bottom:6px;">You still have requirements to follow up</h4>
        <table>
            <thead>
                <tr>
                    <th>Requirement</th>
                    <th>Term</th>
                    <th>Status</th>
                    <th>Notes</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($pending as $r): ?>
                    <tr>
                        <td><?= e($r['requirement_name']) ?></td>
                        <td><?= e($r['year_label'] . ' — ' . $r['semester_name']) ?></td>
                        <td><span
                                class="badge badge-<?= $r['status'] === 'Received' ? 'info' : 'danger' ?>"><?= e($r['status']) ?>
                                <?= $r['status'] === 'Missing' ? '(to be followed)' : '' ?></span></td>
                        <td><?= e($r['notes'] ?? '') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p class="helper-text" style="margin-top:10px;">Bring the original/physical copy of these to the Registrar's office
            to have them marked verified.</p>
    </div>
<?php endif; ?>

<div class="card">
    <h4>Requirements Checklist (bring these for walk-in submission)</h4>
    <p class="helper-text">Whether this is your first time enrolling or you're continuing, prepare these documents
        before visiting the Registrar's office.</p>
    <table>
        <thead>
            <tr>
                <th>Document</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($masterChecklist as $m): ?>
                <tr>
                    <td><?= e($m['requirement_name']) ?></td>
                    <td><span
                            class="badge badge-<?= $m['is_required'] ? 'info' : 'neutral' ?>"><?= $m['is_required'] ? 'Required' : 'Optional' ?></span>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$masterChecklist): ?>
                <tr>
                    <td colspan="2" class="helper-text">No requirement list has been configured yet — check with the
                        Registrar's office.</td>
                </tr><?php endif; ?>
        </tbody>
    </table>
</div>

<?php if (!empty($verified)): ?>
    <div class="card" style="margin-top:20px;">
        <h4>Already Verified</h4>
        <table>
            <thead>
                <tr>
                    <th>Requirement</th>
                    <th>Term</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($verified as $r): ?>
                    <tr>
                        <td><?= e($r['requirement_name']) ?></td>
                        <td><?= e($r['year_label'] . ' — ' . $r['semester_name']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>