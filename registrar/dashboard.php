<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole([ROLE_REGISTRAR]);

// Everything on this dashboard is scoped to the ACTIVE semester so that data from
// previous terms doesn't inflate the numbers the registrar acts on day to day.
$sem = $pdo->query(
    "SELECT s.semester_id, s.semester_name, ay.year_label
     FROM semesters s JOIN academic_years ay ON ay.academic_year_id = s.academic_year_id
     WHERE s.is_active = 1 LIMIT 1"
)->fetch();

$counts = [];
$appCounts = [];
$archivedEnrollments = 0;
$archivedApplications = 0;

if ($sem) {
    $stmt = $pdo->prepare("SELECT status, COUNT(*) AS cnt FROM enrollments WHERE semester_id = :sid GROUP BY status");
    $stmt->execute([':sid' => $sem['semester_id']]);
    $counts = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    $stmt = $pdo->prepare("SELECT status, COUNT(*) AS cnt FROM enrollment_applications WHERE semester_id = :sid GROUP BY status");
    $stmt->execute([':sid' => $sem['semester_id']]);
    $appCounts = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM enrollments WHERE semester_id != :sid");
    $stmt->execute([':sid' => $sem['semester_id']]);
    $archivedEnrollments = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM enrollment_applications WHERE semester_id != :sid");
    $stmt->execute([':sid' => $sem['semester_id']]);
    $archivedApplications = (int) $stmt->fetchColumn();
}

$pageTitle = 'Registrar Dashboard';
require_once __DIR__ . '/../includes/header.php';
?>

<?php if (!$sem): ?>
    <div class="alert alert-info">No active semester is set. Ask the Academic Scheduler to set one under Academic Year &amp;
        Semester.</div>
<?php else: ?>

    <div class="card" style="margin-bottom:20px;">
        <strong>Current term:</strong> <?= e($sem['year_label'] . ' — ' . $sem['semester_name']) ?>
    </div>

    <h3>New Student Applications</h3>
    <div class="grid grid-4">
        <div class="card stat-card">
            <div class="stat-label">Requirements Pending</div>
            <div class="stat-value"><?= (int) ($appCounts['Requirements Pending'] ?? 0) ?></div>
        </div>
        <div class="card stat-card">
            <div class="stat-label">Payment Pending</div>
            <div class="stat-value"><?= (int) ($appCounts['Payment Pending'] ?? 0) ?></div>
        </div>
        <div class="card stat-card">
            <div class="stat-label">Ready to Finalize</div>
            <div class="stat-value"><?= (int) ($appCounts['Payment Verified'] ?? 0) ?></div>
        </div>
        <div class="card stat-card">
            <div class="stat-label">Approved</div>
            <div class="stat-value"><?= (int) ($appCounts['Approved'] ?? 0) ?></div>
        </div>
    </div>
    <div class="card" style="margin-top:12px; margin-bottom:24px;">
        <a class="btn btn-brass" href="<?= BASE_URL ?>/registrar/new-applications">Review new applications</a>
    </div>

    <h3>Returning Students (Re-enrollment)</h3>
    <div class="grid grid-4">
        <div class="card stat-card">
            <div class="stat-label">Submitted</div>
            <div class="stat-value"><?= (int) ($counts['Submitted'] ?? 0) ?></div>
        </div>
        <div class="card stat-card">
            <div class="stat-label">Payment Pending</div>
            <div class="stat-value"><?= (int) ($counts['Payment Pending'] ?? 0) ?></div>
        </div>
        <div class="card stat-card">
            <div class="stat-label">Payment Verified (awaiting approval)</div>
            <div class="stat-value"><?= (int) ($counts['Payment Verified'] ?? 0) ?></div>
        </div>
        <div class="card stat-card">
            <div class="stat-label">Enrolled</div>
            <div class="stat-value"><?= (int) ($counts['Enrolled'] ?? 0) ?></div>
        </div>
    </div>

    <div class="card" style="margin-top:20px;">
        <div class="grid grid-3">
            <a class="btn btn-brass" href="<?= BASE_URL ?>/registrar/enroll-student">Enroll a returning student</a>
            <a class="btn btn-outline" href="<?= BASE_URL ?>/registrar/applications">Review re-enrollment applications</a>
            <a class="btn btn-outline" href="<?= BASE_URL ?>/registrar/requirements">Verify requirements</a>
        </div>
    </div>

    <?php if ($archivedEnrollments > 0 || $archivedApplications > 0): ?>
        <div class="card" style="margin-top:20px; background:#FBFAF7;">
            <h4 style="margin-bottom:6px;">Previous Terms (archive)</h4>
            <p class="helper-text" style="margin:0;">
                <?= $archivedEnrollments ?> enrollment record(s) and <?= $archivedApplications ?> application(s) from earlier
                semesters are kept for history
                but excluded from the figures above. Use the filters on the Applications and Registration Forms pages to look
                them up.
            </p>
        </div>
    <?php endif; ?>

<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>