<?php

declare(strict_types=1);

use Kartenlink\App\Support\Database;

require dirname(__DIR__) . '/vendor/autoload.php';

$config = require dirname(__DIR__) . '/config/config.php';
$db = Database::connection($config['db']);
$publicRoot = dirname(__DIR__) . '/public';

$expiredIds = $db->query(
    'SELECT id FROM users WHERE is_demo = 1 AND demo_expires_at < NOW()'
)->fetchAll(PDO::FETCH_COLUMN);

foreach ($expiredIds as $userId) {
    $userId = (int) $userId;

    $orgStmt = $db->prepare('SELECT id FROM organizations WHERE owner_user_id = :id');
    $orgStmt->execute(['id' => $userId]);
    if ($orgStmt->fetchColumn() !== false) {
        echo "WARNUNG: Demo-Account {$userId} besitzt eine Organisation - uebersprungen, bitte manuell pruefen.\n";
        continue;
    }

    $cardStmt = $db->prepare('SELECT id FROM business_cards WHERE user_id = :id');
    $cardStmt->execute(['id' => $userId]);
    $cardId = $cardStmt->fetchColumn();

    if ($cardId !== false) {
        $imagesStmt = $db->prepare('SELECT image_path FROM card_gallery_images WHERE business_card_id = :card_id');
        $imagesStmt->execute(['card_id' => $cardId]);
        foreach ($imagesStmt->fetchAll(PDO::FETCH_COLUMN) as $imagePath) {
            @unlink($publicRoot . '/' . $imagePath);
        }
    }

    foreach (['logos', 'photos'] as $subdir) {
        foreach (glob($publicRoot . "/uploads/{$subdir}/{$userId}.*") ?: [] as $file) {
            @unlink($file);
        }
    }

    $db->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $userId]);
    echo "Demo-Account {$userId} geloescht.\n";
}

echo "Fertig.\n";
