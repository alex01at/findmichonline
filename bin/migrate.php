<?php

declare(strict_types=1);

use Kartenlink\App\Support\Database;

require dirname(__DIR__) . '/vendor/autoload.php';

$config = require dirname(__DIR__) . '/config/config.php';
$db = Database::connection($config['db']);

$db->exec(
    'CREATE TABLE IF NOT EXISTS migrations (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        filename VARCHAR(255) NOT NULL UNIQUE,
        applied_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
);

$applied = $db->query('SELECT filename FROM migrations')->fetchAll(PDO::FETCH_COLUMN);

$migrationsDir = dirname(__DIR__) . '/migrations';
$files = glob($migrationsDir . '/*.sql');
sort($files);

foreach ($files as $file) {
    $filename = basename($file);

    if (in_array($filename, $applied, true)) {
        continue;
    }

    $sql = file_get_contents($file);
    $db->exec($sql);

    $stmt = $db->prepare('INSERT INTO migrations (filename, applied_at) VALUES (:filename, NOW())');
    $stmt->execute(['filename' => $filename]);

    echo "Migration angewendet: {$filename}\n";
}

echo "Fertig.\n";
