<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole([ROLE_STUDENT]);

$studentId = $_SESSION['user_id'];

$stmt = $pdo->prepare("SELECT program_id, year_level FROM student_profiles WHERE user_id = :sid");
$stmt->execute([':sid' => $studentId]);
$profile = $stmt->fetch();

$curriculum = [];
if ($profile) {
    $stmt = $pdo->prepare(
        "SELECT c.year_level, c.semester_name, c.subject_type, sub.subject_code, sub.subject_name, sub.units,
                GROUP_CONCAT(pre.subject_code SEPARATOR ', ') AS prerequisites
         FROM curriculum c
         JOIN subjects sub ON sub.subject_id = c.subject_id
         LEFT JOIN subject_prerequisites sp ON sp.subject_id = sub.subject_id
         LEFT JOIN subjects pre ON pre.subject_id = sp.prerequisite_subject_id
         WHERE c.program_id = :pid
         GROUP BY c.year_level, c.semester_name, c.subject_type, sub.subject_code, sub.subject_name, sub.units
         ORDER BY c.year_level, FIELD(c.semester_name, '1st Semester','2nd Semester','Summer')"
    );
    $stmt->execute([':pid' => $profile['program_id']]);
    foreach ($stmt->fetchAll() as $row) {
        $curriculum[$row['year_level']][$row['semester_name']][] = $row;
    }
}

$pageTitle = 'My Curriculum';
require_once __DIR__ . '/../includes/header.php';
?>

<?php if (empty($curriculum)): ?>
    <div class="card empty-state">No curriculum has been set up for your program yet. Please check with the Academic
        Scheduler's office.</div>
<?php else: ?>
    <?php foreach ($curriculum as $yearLevel => $semesters): ?>
        <h3>Year <?= (int) $yearLevel ?></h3>
        <div class="grid grid-2" style="margin-bottom: 24px;">
            <?php foreach ($semesters as $semName => $subjects): ?>
                <div class="card">
                    <h4 style="margin-bottom:12px;"><?= e($semName) ?></h4>
                    <table>
                        <thead>
                            <tr>
                                <th>Code</th>
                                <th>Subject</th>
                                <th>Units</th>
                                <th>Type</th>
                                <th>Prerequisite(s)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($subjects as $s): ?>
                                <tr>
                                    <td><?= e($s['subject_code']) ?></td>
                                    <td><?= e($s['subject_name']) ?></td>
                                    <td><?= number_format($s['units'], 1) ?></td>
                                    <td><span
                                            class="badge badge-<?= $s['subject_type'] === 'Required' ? 'info' : 'neutral' ?>"><?= e($s['subject_type']) ?></span>
                                    </td>
                                    <td><?= $s['prerequisites'] ? e($s['prerequisites']) : '—' ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>