<?php
require_once __DIR__ . '/includes/auth.php';

if (isLoggedIn()) {
    redirect(dashboardUrlFor($_SESSION['role_name']));
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e(SITE_NAME) ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
</head>

<body>

    <?php require_once __DIR__ . '/includes/public-nav.php'; ?>

    <div class="hero">
        <h1>Enroll from anywhere. Finish the rest in one walk-in visit.</h1>
        <p>For new students: submit your application online, then bring your requirements and payment in person to
            complete enrollment. Returning students: log in to re-enroll each semester.</p>
        <div class="hero-actions">
            <a href="<?= BASE_URL ?>/apply" class="btn btn-brass">Apply for Enrollment</a>
            <a href="<?= BASE_URL ?>/auth/login" class="btn btn-outline">Login</a>
        </div>
    </div>

    <div class="feature-strip">
        <div class="card">
            <h4>1. Apply online</h4>
            <p style="margin:0;">Fill out a short application with your info and desired program. You'll get a reference
                number to bring with you.</p>
        </div>
        <div class="card">
            <h4>2. Walk in with requirements</h4>
            <p style="margin:0;">Submit your documents to the Registrar and pay the ₱3,000 minimum fee at the Cashier.
            </p>
        </div>
        <div class="card">
            <h4>3. Get your account</h4>
            <p style="margin:0;">Once verified, the Registrar finalizes your enrollment and gives you your login — from
                then on you can re-enroll online every semester.</p>
        </div>
    </div>

    <?php require_once __DIR__ . '/includes/public-footer.php'; ?>

</body>

</html>