<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole([ROLE_REGISTRAR]);

$enrollmentId = (int)($_GET['enrollment_id'] ?? 0);

$stmt = $pdo->prepare(
    "SELECT e.*, s.semester_name, ay.year_label, u.first_name, u.last_name, u.student_id AS student_number,
            p.program_name, sp.year_level
     FROM enrollments e
     JOIN semesters s ON s.semester_id = e.semester_id
     JOIN academic_years ay ON ay.academic_year_id = s.academic_year_id
     JOIN users u ON u.user_id = e.student_id
     JOIN student_profiles sp ON sp.user_id = u.user_id
     LEFT JOIN programs p ON p.program_id = sp.program_id
     WHERE e.enrollment_id = :eid AND e.status = 'Enrolled'"
);
$stmt->execute([':eid' => $enrollmentId]);
$enrollment = $stmt->fetch();

if (!$enrollment) {
    setFlash('error', 'That enrollment is not found, or isn\'t fully finalized yet.');
    redirect('/registrar/registration-forms');
}

$stmt = $pdo->prepare(
    "SELECT sub.subject_code, sub.subject_name, sub.units, sec.section_name, cs.day_of_week, cs.start_time, cs.end_time
     FROM enrollment_subjects es
     JOIN class_schedules cs ON cs.schedule_id = es.schedule_id
     JOIN subjects sub ON sub.subject_id = cs.subject_id
     JOIN sections sec ON sec.section_id = cs.section_id
     WHERE es.enrollment_id = :eid"
);
$stmt->execute([':eid' => $enrollment['enrollment_id']]);
$subjects = $stmt->fetchAll();
$balance = max(0, $enrollment['total_amount_due'] - $enrollment['total_paid']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Registration Form — <?= e($enrollment['first_name'] . ' ' . $enrollment['last_name']) ?></title>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
<style>
  @page { margin: 15mm; }
  body { background:#fff; margin:0; display:flex; justify-content:center; }
  .doc { max-width: 800px; width:100%; box-sizing:border-box; border: 1px solid var(--line); padding: 32px; margin: 40px 0; }
  @media print { .no-print { display:none !important; } } /*inadd ko para ma-hide yung back button and print button when printing*/
</style>
</head>
<body>
<div class="doc">
    <div class="no-print" style="display:flex; justify-content:space-between; margin-bottom:20px;">
        <a href="<?= BASE_URL ?>/registrar/registration-forms" class="btn btn-outline">&larr; Back</a>
        <button class="btn btn-brass" onclick="window.print()">Print</button>
    </div>
    <div style="text-align:center; margin-bottom:24px;">
        <h2 style="margin-bottom:4px;"><?= e(SITE_NAME) ?></h2>
        <p style="margin:0 auto;">Official Registration Form</p>
    </div>
    <div class="grid grid-2" style="margin-bottom: 20px;">
        <p><strong>Student:</strong> <?= e($enrollment['first_name'] . ' ' . $enrollment['last_name']) ?><br>
           <strong>Student ID:</strong> <?= e($enrollment['student_number']) ?></p>
        <p><strong>Program:</strong> <?= e($enrollment['program_name']) ?><br>
           <strong>Year Level:</strong> <?= (int)$enrollment['year_level'] ?><br>
           <strong>Academic Year / Semester:</strong> <?= e($enrollment['year_label'] . ' — ' . $enrollment['semester_name']) ?></p>
    </div>
    <table>
        <thead><tr><th>Code</th><th>Subject</th><th>Units</th><th>Section</th><th>Schedule</th></tr></thead>
        <tbody>
        <?php foreach ($subjects as $s): ?>
            <tr>
                <td><?= e($s['subject_code']) ?></td>
                <td><?= e($s['subject_name']) ?></td>
                <td><?= number_format($s['units'],1) ?></td>
                <td><?= e($s['section_name']) ?></td>
                <td><?= e(str_replace(',', '/', $s['day_of_week'])) ?> <?= date('g:iA', strtotime($s['start_time'])) ?>–<?= date('g:iA', strtotime($s['end_time'])) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <div class="card" style="margin-top:20px;">
        <p style="margin:0;"><strong>Total Units:</strong> <?= number_format($enrollment['total_units'],1) ?></p>
        <p style="margin:0;"><strong>Tuition:</strong> &#8369;<?= number_format($enrollment['total_tuition'],2) ?></p>
        <p style="margin:0;"><strong>Fees:</strong> &#8369;<?= number_format($enrollment['total_fees'],2) ?></p>
        <p style="margin:0;"><strong>Total Amount Due:</strong> &#8369;<?= number_format($enrollment['total_amount_due'],2) ?></p>
        <p style="margin:0;"><strong>Amount Paid:</strong> &#8369;<?= number_format($enrollment['total_paid'],2) ?></p>
        <p style="margin:0;"><strong>Remaining Balance:</strong> &#8369;<?= number_format($balance,2) ?></p>
        <p style="margin:0;"><strong>Status:</strong> <?= e($enrollment['status']) ?></p>
    </div>
</div>
</body>
</html>