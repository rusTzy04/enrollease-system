<?php
// Self-registration was replaced with a public enrollment application + staff-issued accounts.
// This stub just forwards anyone who still has the old link bookmarked.
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/constants.php';
header('Location: ' . BASE_URL . '/apply');
exit;