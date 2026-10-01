<?php
// Creates the tables and seed data in the configured database.
// Command line only:  php database/import.php
// The CREATE DATABASE / USE lines in the .sql files are skipped, so it runs
// against whatever database config/database.php points at (e.g. Railway's).

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/database.php';

$existing = $pdo->query("SHOW TABLES LIKE 'users'")->fetchColumn();
if ($existing && !in_array('--force', $argv, true)) {
    fwrite(STDERR, "Tables already exist in {$DB_NAME}; nothing done. Use --force to run anyway.\n");
    exit(1);
}

foreach (['schema.sql', 'migration-applications.sql'] as $file) {
    $sql = file_get_contents(__DIR__ . '/' . $file);
    $sql = preg_replace('/^\s*(CREATE DATABASE|USE)\b[^;]*;/mi', '', $sql);
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);

    $count = 0;
    foreach (preg_split('/;\s*(\r?\n|$)/', $sql) as $statement) {
        if (trim($statement) === '') {
            continue;
        }
        $pdo->exec($statement);
        $count++;
    }
    echo "{$file}: {$count} statements\n";
}

echo "Done. Tables in {$DB_NAME}: " . count($pdo->query('SHOW TABLES')->fetchAll()) . "\n";
