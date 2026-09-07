<?php

declare(strict_types=1);

// The PHP built-in dev server (php -S ... public/index.php) invokes this
// script for every request. Returning false here lets it fall back to
// serving a real file directly (e.g. uploaded logos) instead of running
// it through the app. Apache doesn't use this file as a router, so this
// has no effect in production — public/.htaccess handles it there.
if (PHP_SAPI === 'cli-server') {
    $requestedFile = __DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if (is_file($requestedFile)) {
        return false;
    }
}

use Kartenlink\App\Controller\AccountController;
use Kartenlink\App\Controller\AdminController;
use Kartenlink\App\Controller\AuthController;
use Kartenlink\App\Controller\BillingController;
use Kartenlink\App\Controller\CardController;
use Kartenlink\App\Controller\DashboardController;
use Kartenlink\App\Controller\HomeController;
use Kartenlink\App\Controller\PricingController;
use Kartenlink\App\Controller\QrCodeController;
use Kartenlink\App\Controller\StripeWebhookController;
use Kartenlink\App\Support\Auth;
use Kartenlink\App\Support\Database;
use Kartenlink\App\Support\LogoUploader;
use Kartenlink\App\Support\Mailer;
use Kartenlink\App\Support\Router;
use Kartenlink\App\Support\Session;
use Kartenlink\App\Support\StripeService;
use Kartenlink\App\Support\Translator;
use Kartenlink\App\Support\View;

require dirname(__DIR__) . '/vendor/autoload.php';

$config = require dirname(__DIR__) . '/config/config.php';

if ($config['app']['env'] !== 'dev') {
    ini_set('display_errors', '0');
    error_reporting(E_ALL);
}

Session::start();

$locale = Session::get('locale');
if (!in_array($locale, Translator::SUPPORTED_LOCALES, true)) {
    $locale = Translator::detectLocale($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? null);
    Session::set('locale', $locale);
}
$translator = new Translator($locale, dirname(__DIR__) . '/lang');

$db = Database::connection($config['db']);
$auth = new Auth($db);
$auth->attemptRememberLogin();
$view = new View(
    dirname(__DIR__) . '/templates',
    dirname(__DIR__) . '/var/cache/twig',
    $config['app']['env'] === 'dev',
    $translator,
    $auth
);

$router = new Router();

$requireAuth = function () use ($auth): void {
    if (!$auth->check()) {
        header('Location: /login');
        exit;
    }
};

$requireAdmin = function () use ($auth): void {
    if (!$auth->check()) {
        header('Location: /login');
        exit;
    }
    if (!$auth->isAdmin()) {
        http_response_code(403);
        exit('403 - Kein Zugriff');
    }
};

$home = new HomeController($view, $auth);
$router->get('/', fn () => $home->index());

$mailer = new Mailer($config['mail']['from_address'], $config['mail']['from_name']);
$authController = new AuthController($db, $view, $auth, $translator, $mailer, $config['app']['url']);
$router->get('/register', fn () => $authController->showRegister());
$router->post('/register', fn () => $authController->register());
$router->get('/login', fn () => $authController->showLogin());
$router->post('/login', fn () => $authController->login());
$router->get('/logout', fn () => $authController->logout());
$router->get('/forgot-password', fn () => $authController->showForgotPassword());
$router->post('/forgot-password', fn () => $authController->forgotPassword());
$router->get('/reset-password/{token}', fn (array $params) => $authController->showResetPassword($params));
$router->post('/reset-password/{token}', fn (array $params) => $authController->resetPassword($params));

$dashboard = new DashboardController($db, $view, $auth);
$router->get('/dashboard', function () use ($dashboard, $requireAuth) {
    $requireAuth();
    $dashboard->index();
});

$logoUploader = new LogoUploader(dirname(__DIR__) . '/public');
$card = new CardController($db, $view, $auth, $translator, $logoUploader);
$router->get('/card/edit', function () use ($card, $requireAuth) {
    $requireAuth();
    $card->edit();
});
$router->post('/card/edit', function () use ($card, $requireAuth) {
    $requireAuth();
    $card->save();
});

$qrCode = new QrCodeController($db, $auth, $config['app']['url'], $translator);
$router->get('/card/qr.png', function () use ($qrCode, $requireAuth) {
    $requireAuth();
    $qrCode->png();
});
$router->get('/card/qr.svg', function () use ($qrCode, $requireAuth) {
    $requireAuth();
    $qrCode->svg();
});

$stripe = new StripeService(
    $config['stripe']['secret_key'],
    $config['stripe']['price_id_pro'],
    $config['app']['url']
);

$pricing = new PricingController($view, $auth, $stripe);
$router->get('/pricing', fn () => $pricing->index());

$account = new AccountController($db, $auth, $translator);
$router->post('/account/plan', function () use ($account, $requireAuth) {
    $requireAuth();
    $account->updatePlan();
});

$billing = new BillingController($auth, $stripe, $view, $translator);
$router->post('/billing/checkout', function () use ($billing, $requireAuth) {
    $requireAuth();
    $billing->checkout();
});
$router->post('/billing/portal', function () use ($billing, $requireAuth) {
    $requireAuth();
    $billing->portal();
});
$router->get('/billing/success', function () use ($billing, $requireAuth) {
    $requireAuth();
    $billing->success();
});

$stripeWebhook = new StripeWebhookController($db, $stripe, $config['stripe']['webhook_secret']);
$router->post('/webhook/stripe', fn () => $stripeWebhook->handle());

$router->get('/lang/{locale}', function (array $params) {
    if (in_array($params['locale'], Translator::SUPPORTED_LOCALES, true)) {
        Session::set('locale', $params['locale']);
    }

    $referer = $_SERVER['HTTP_REFERER'] ?? '/';
    header('Location: ' . $referer);
    exit;
});

$admin = new AdminController($db, $view, $auth, $translator, $logoUploader);
$router->get('/admin', function () use ($admin, $requireAdmin) {
    $requireAdmin();
    $admin->index();
});
$router->get('/admin/users/create', function () use ($admin, $requireAdmin) {
    $requireAdmin();
    $admin->showCreateUser();
});
$router->post('/admin/users/create', function () use ($admin, $requireAdmin) {
    $requireAdmin();
    $admin->createUser();
});
$router->get('/admin/users/{id}', function (array $params) use ($admin, $requireAdmin) {
    $requireAdmin();
    $admin->showEditUser($params);
});
$router->post('/admin/users/{id}', function (array $params) use ($admin, $requireAdmin) {
    $requireAdmin();
    $admin->updateUser($params);
});
$router->post('/admin/users/{id}/delete', function (array $params) use ($admin, $requireAdmin) {
    $requireAdmin();
    $admin->deleteUser($params);
});
$router->post('/admin/users/{id}/card', function (array $params) use ($admin, $requireAdmin) {
    $requireAdmin();
    $admin->updateCard($params);
});
$router->post('/admin/users/{id}/card/delete', function (array $params) use ($admin, $requireAdmin) {
    $requireAdmin();
    $admin->deleteCard($params);
});

// Catch-all for published business cards (findmichonline.com/{slug}).
// Must stay the last GET route registered so every fixed route above
// takes priority over a card slug.
$router->get('/{slug}', fn (array $params) => $card->showPublic($params));

$router->dispatch($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
