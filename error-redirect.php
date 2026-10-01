<?php
require_once __DIR__ . '/includes/auth.php';

if (isLoggedIn()) {
    redirect(dashboardUrlFor($_SESSION['role_name']));
} else {
    redirect('/');
}