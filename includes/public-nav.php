<?php

$__currentPath = strtok($_SERVER['REQUEST_URI'], '?');
function __navActive(string $path, string $current): string
{
    return rtrim($current, '/') === rtrim($path, '/') ? 'active' : '';
}
?>
<header class="public-nav">
    <a href="<?= BASE_URL ?>/" class="public-nav-brand">
        <span><?= e(SITE_NAME) ?></span>
    </a>
    <nav class="public-nav-links">
        <a href="<?= BASE_URL ?>/" class="<?= __navActive(BASE_URL . '/', $__currentPath) ?>">Home</a>
        <a href="<?= BASE_URL ?>/apply" class="btn btn-brass btn-sm">Apply for Enrollment</a>
        <a href="<?= BASE_URL ?>/auth/login" class="btn btn-outline btn-sm">Login</a>
    </nav>
</header>