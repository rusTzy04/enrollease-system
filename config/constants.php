<?php
// Railway serves the site at the domain root, so set BASE_URL to an empty string there.
define('BASE_URL', getenv('BASE_URL') !== false ? rtrim(getenv('BASE_URL'), '/') : '/enrollease-system');

define('MIN_PAYMENT', 3000.00);
define('SITE_NAME', 'ENROLLEASE');

// Role IDs (must match the `roles` table seeded in schema.sql)
define('ROLE_ADMIN', 1);
define('ROLE_SCHEDULER', 2);
define('ROLE_REGISTRAR', 3);
define('ROLE_CASHIER', 4);
define('ROLE_TEACHER', 5);
define('ROLE_STUDENT', 6);

define('ROLE_NAMES', [
    ROLE_ADMIN => 'admin',
    ROLE_SCHEDULER => 'academic_scheduler',
    ROLE_REGISTRAR => 'registrar',
    ROLE_CASHIER => 'cashier',
    ROLE_TEACHER => 'teacher',
    ROLE_STUDENT => 'student',
]);

// Session security settings applied before session_start() (see includes/session.php)
define('SESSION_LIFETIME', 60 * 60 * 2); // 2 hours
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOCKOUT_MINUTES', 15);
