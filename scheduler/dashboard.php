<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole([ROLE_SCHEDULER]);

$subjectCount = $pdo->query("SELECT COUNT(*) FROM subjects WHERE is_active = 1")->fetchColumn();
$sectionCount = $pdo->query("SELECT COUNT(*) FROM sections")->fetchColumn();
$scheduleCount = $pdo->query("SELECT COUNT(*) FROM class_schedules WHERE status = 'Open'")->fetchColumn();

$pageTitle = 'Scheduler Dashboard';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="grid grid-3">
    <div class="card stat-card">
        <div class="stat-label">Active Subjects</div>
        <div class="stat-value"><?= (int) $subjectCount ?></div>
    </div>
    <div class="card stat-card">
        <div class="stat-label">Sections</div>
        <div class="stat-value"><?= (int) $sectionCount ?></div>
    </div>
    <div class="card stat-card">
        <div class="stat-label">Open Schedules</div>
        <div class="stat-value"><?= (int) $scheduleCount ?></div>
    </div>
</div>
<div class="card" style="margin-top:20px;">
    <div class="grid grid-3">
        <a class="btn btn-outline" href="<?= BASE_URL ?>/scheduler/curriculum">Manage curriculum</a>
        <a class="btn btn-outline" href="<?= BASE_URL ?>/scheduler/sections">Manage sections</a>
        <a class="btn btn-outline" href="<?= BASE_URL ?>/scheduler/schedules">Manage class schedules</a>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>