<?php

declare(strict_types=1);

use Dotenv\Dotenv;

$root = dirname(__DIR__);

if (file_exists($root . '/.env')) {
    Dotenv::createImmutable($root)->load();
}

return [
    'app' => [
        'env' => $_ENV['APP_ENV'] ?? 'prod',
        'secret' => $_ENV['APP_SECRET'] ?? '',
        'url' => rtrim($_ENV['APP_URL'] ?? '', '/'),
        'root' => $root,
    ],
    'db' => [
        'host' => $_ENV['DB_HOST'] ?? '127.0.0.1',
        'port' => $_ENV['DB_PORT'] ?? '3306',
        'name' => $_ENV['DB_NAME'] ?? '',
        'user' => $_ENV['DB_USER'] ?? '',
        'pass' => $_ENV['DB_PASS'] ?? '',
    ],
    'stripe' => [
        'secret_key' => $_ENV['STRIPE_SECRET_KEY'] ?? '',
        'publishable_key' => $_ENV['STRIPE_PUBLISHABLE_KEY'] ?? '',
        'webhook_secret' => $_ENV['STRIPE_WEBHOOK_SECRET'] ?? '',
        'price_id_pro' => $_ENV['STRIPE_PRICE_ID_PRO'] ?? '',
    ],
    'mail' => [
        'from_address' => $_ENV['MAIL_FROM_ADDRESS'] ?? 'noreply@localhost',
        'from_name' => $_ENV['MAIL_FROM_NAME'] ?? 'findmichonline',
    ],
];
