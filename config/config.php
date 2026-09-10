<?php

declare(strict_types=1);

use Dotenv\Dotenv;

// Must match Database::connection()'s `SET time_zone = '+00:00'` - both
// sides of every "time remaining" computation (trial_ends_at,
// demo_expires_at, ...) need to agree on one timezone. Without this, PHP
// falls back to whatever the host's php.ini sets (which varies per server -
// e.g. Europe/Vienna on production vs. UTC in local dev), so strtotime()
// misreads the UTC datetime strings MySQL now returns.
date_default_timezone_set('UTC');

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
        'price_id_pro_monthly' => $_ENV['STRIPE_PRICE_ID_PRO_MONTHLY'] ?? '',
        'price_id_pro_yearly' => $_ENV['STRIPE_PRICE_ID_PRO_YEARLY'] ?? '',
        'price_id_firma_monthly' => $_ENV['STRIPE_PRICE_ID_FIRMA_MONTHLY'] ?? '',
    ],
    'mail' => [
        'from_address' => $_ENV['MAIL_FROM_ADDRESS'] ?? 'noreply@localhost',
        'from_name' => $_ENV['MAIL_FROM_NAME'] ?? 'findmichonline',
        'contact_address' => ($_ENV['CONTACT_EMAIL'] ?? '') !== ''
            ? $_ENV['CONTACT_EMAIL']
            : ($_ENV['MAIL_FROM_ADDRESS'] ?? 'noreply@localhost'),
    ],
];
