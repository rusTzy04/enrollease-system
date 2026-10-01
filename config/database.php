<?php
// On Railway these come from environment variables (DB_* set on the app service).
// Locally (XAMPP/Laragon) nothing is set, so the defaults below are used.
$DB_HOST = getenv('DB_HOST') ?: 'localhost';
$DB_PORT = getenv('DB_PORT') ?: '3306';
$DB_NAME = getenv('DB_DATABASE') ?: 'enrollease_system';
$DB_USER = getenv('DB_USERNAME') ?: 'root';
$DB_PASS = getenv('DB_PASSWORD') !== false ? getenv('DB_PASSWORD') : '';

try {
    $pdo = new PDO(
        "mysql:host={$DB_HOST};port={$DB_PORT};dbname={$DB_NAME};charset=utf8mb4",
        $DB_USER,
        $DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false, // forces REAL prepared statements (stronger SQLi protection)
        ]
    );
} catch (PDOException $e) {
    // Never leak raw DB errors to the user/browser
    error_log('DB connection failed: ' . $e->getMessage());
    die('A system error occurred. Please try again later.');
}
