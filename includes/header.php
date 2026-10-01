<?php

$pageTitle = $pageTitle ?? SITE_NAME;
$roleName = $_SESSION['role_name'] ?? '';
$initials = strtoupper(substr($_SESSION['first_name'] ?? 'U', 0, 1) . substr($_SESSION['last_name'] ?? '', 0, 1));

$navByRole = [
    'admin' => [
        ['Overview' => [['Dashboard', '/admin/dashboard', 'dashboard']]],
        ['Management' => [
            ['User Accounts', '/admin/users', 'users'],
            ['Programs', '/admin/programs', 'programs'],
            ['Rooms', '/admin/rooms', 'rooms'],
        ]],
        ['System' => [
            ['Reports', '/admin/reports', 'reports'],
            ['Activity Logs', '/admin/logs', 'logs'],
            ['Settings', '/admin/settings', 'settings'],
        ]],
    ],
    'academic_scheduler' => [
        ['Overview' => [['Dashboard', '/scheduler/dashboard', 'dashboard']]],
        ['Curriculum' => [
            ['Programs', '/scheduler/programs', 'programs'],
            ['Subjects & Curriculum', '/scheduler/curriculum', 'curriculum'],
            ['Academic Year & Semester', '/scheduler/academic-year', 'academic-year'],
        ]],
        ['Scheduling' => [
            ['Sections', '/scheduler/sections', 'sections'],
            ['Class Schedules', '/scheduler/schedules', 'schedules'],
        ]],
    ],
    'registrar' => [
        ['Overview' => [['Dashboard', '/registrar/dashboard', 'dashboard']]],
        ['New Students' => [
            ['New Applications', '/registrar/new-applications', 'new-applications'],
        ]],
        ['Enrollment' => [
            ['Enroll a Student', '/registrar/enroll-student', 'enroll-student'],
            ['Applications', '/registrar/applications', 'applications'],
            ['Requirements Queue', '/registrar/requirements', 'requirements'],
            ['Requirement Types', '/registrar/requirement-types', 'requirement-types'],
            ['Class Schedules', '/scheduler/schedules', 'schedules'],
        ]],
        ['Records' => [
            ['Registration Forms', '/registrar/registration-forms', 'registration-forms'],
            ['Student Directory', '/student-directory', 'student-directory'],
        ]],
    ],
    'cashier' => [
        ['Overview' => [['Dashboard', '/cashier/dashboard', 'dashboard']]],
        ['Payments' => [
            ['Walk-in Payment', '/cashier/payment', 'payment'],
            ['New Applicant Payment', '/cashier/applicant-payment', 'applicant-payment'],
            ['Remaining Balance', '/cashier/balance-payment', 'balance-payment'],
            ['Payment History', '/cashier/history', 'history'],
            ['Receipts', '/cashier/receipts', 'receipts'],
        ]],
        ['Records' => [['Student Directory', '/student-directory', 'student-directory']]],
    ],
    'teacher' => [
        ['Overview' => [['Dashboard', '/teacher/dashboard', 'dashboard']]],
        ['Classes' => [
            ['My Sections', '/teacher/classes', 'classes'],
            ['Grades', '/teacher/grades', 'grades'],
        ]],
    ],
    'student' => [
        ['Overview' => [['Dashboard', '/student/dashboard', 'dashboard']]],
        ['Enrollment' => [
            ['Curriculum', '/student/curriculum', 'curriculum'],
            ['My Enrollment', '/student/enroll', 'enroll'],
            ['Enrollment Status', '/student/status', 'status'],
            ['Requirements', '/student/requirements', 'requirements'],
        ]],
        ['My Records' => [
            ['Payments & Balance', '/student/payments', 'payments'],
            ['Academic Records', '/student/records', 'records'],
            ['Profile', '/student/profile', 'profile'],
        ]],
    ],
];

foreach ($navByRole as $roleKey => &$groups) {
    $groups[] = ['Account' => [['Change Password', '/account-settings', 'account-settings']]];
}
unset($groups);

$currentPage = basename($_SERVER['SCRIPT_NAME'], '.php');

$unreadCount = 0;
try {
    $ustmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = :uid AND is_read = 0");
    $ustmt->execute([':uid' => $_SESSION['user_id']]);
    $unreadCount = (int)$ustmt->fetchColumn();
} catch (Throwable $e) {}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title><?= e($pageTitle) ?> · <?= e(SITE_NAME) ?></title>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
</head>
<body>
<div class="app">
    <aside class="sidebar">
        <div class="brand">
            <div class="mark">E</div>
            <div>
                <div class="name"><?= e(SITE_NAME) ?></div>
                <div class="role-badge"><?= e(str_replace('_', ' ', $roleName)) ?></div>
            </div>
        </div>
        <?php foreach (($navByRole[$roleName] ?? []) as $group): foreach ($group as $label => $links): ?>
        <div class="nav-group">
            <div class="label"><?= e($label) ?></div>
            <?php foreach ($links as [$text, $href, $key]): ?>
            <a class="nav-link <?= $currentPage === $key ? 'active' : '' ?>" href="<?= BASE_URL . $href ?>"><?= e($text) ?></a>
            <?php endforeach; ?>
        </div>
        <?php endforeach; endforeach; ?>
        <div class="nav-group">
            <a class="nav-link" href="<?= BASE_URL ?>/auth/logout">Log out</a>
        </div>
    </aside>
    <div class="main">
        <div class="topbar">
            <div style="display:flex;align-items:center;gap:12px;">
                <button class="menu-toggle" aria-label="Toggle menu">&#9776;</button>
                <div class="page-title"><?= e($pageTitle) ?></div>
            </div>
            <div class="user-chip">
                <a href="<?= BASE_URL ?>/notifications" style="position:relative; display:flex; align-items:center; color:var(--ink); text-decoration:none;" aria-label="Notifications">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"></path>
                        <path d="M13.73 21a2 2 0 0 1-3.46 0"></path>
                    </svg>
                    <?php if ($unreadCount > 0): ?>
                        <span style="position:absolute; top:-6px; right:-8px; background:var(--danger); color:#fff; font-size:0.65rem; font-weight:700; border-radius:100px; padding:1px 5px;"><?= $unreadCount > 9 ? '9+' : $unreadCount ?></span>
                    <?php endif; ?>
                </a>
                <?php $profileLink = $roleName === 'student' ? '/student/profile' : '/account-settings'; ?>
                <a href="<?= BASE_URL . $profileLink ?>" style="display:flex; align-items:center; gap:10px; color:var(--ink); text-decoration:none;" title="View profile / account settings">
                    <span><?= e($_SESSION['first_name'] . ' ' . $_SESSION['last_name']) ?></span>
                    <div class="avatar"><?= e($initials) ?></div>
                </a>
            </div>
        </div>
        <div class="content">
        <?php $flash = getFlash(); if ($flash): ?>
            <div class="alert alert-<?= $flash['type'] === 'error' ? 'error' : ($flash['type'] === 'success' ? 'success' : 'info') ?>" data-autohide>
                <?= e($flash['message']) ?>
            </div>
        <?php endif; ?>