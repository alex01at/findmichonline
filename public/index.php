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
use Kartenlink\App\Controller\ContactController;
use Kartenlink\App\Controller\DashboardController;
use Kartenlink\App\Controller\DemoController;
use Kartenlink\App\Controller\HomeController;
use Kartenlink\App\Controller\DemoCardController;
use Kartenlink\App\Controller\LandingController;
use Kartenlink\App\Controller\LegalController;
use Kartenlink\App\Controller\OnboardingController;
use Kartenlink\App\Controller\PricingController;
use Kartenlink\App\Controller\QrCodeController;
use Kartenlink\App\Controller\StripeWebhookController;
use Kartenlink\App\Controller\TeamController;
use Kartenlink\App\Support\Auth;
use Kartenlink\App\Support\Database;
use Kartenlink\App\Support\GalleryUploader;
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

// Reduce fingerprintability for tools like Wappalyzer - expose_php can only
// be set in php.ini (PHP_INI_SYSTEM), but the header it controls can still
// be removed at runtime regardless of that setting.
header_remove('X-Powered-By');

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
    $auth,
    $config['app']['url']
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

$requireOrgOwner = function () use ($auth): void {
    if (!$auth->check()) {
        header('Location: /login');
        exit;
    }
    if (!$auth->isOrgOwner()) {
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

$demo = new DemoController($db, $auth, $translator);
$router->post('/demo/start', fn () => $demo->start());

$dashboard = new DashboardController($db, $view, $auth);
$router->get('/dashboard', function () use ($dashboard, $requireAuth) {
    $requireAuth();
    $dashboard->index();
});

$logoUploader = new LogoUploader(dirname(__DIR__) . '/public');
$photoUploader = new LogoUploader(dirname(__DIR__) . '/public', 'photos');
$orgLogoUploader = new LogoUploader(dirname(__DIR__) . '/public', 'org_logos');
$galleryUploader = new GalleryUploader(dirname(__DIR__) . '/public');
$card = new CardController($db, $view, $auth, $translator, $logoUploader, $photoUploader, $galleryUploader, $config['app']['url']);
$router->get('/card/edit', function () use ($card, $requireAuth) {
    $requireAuth();
    $card->edit();
});
$router->post('/card/edit', function () use ($card, $requireAuth) {
    $requireAuth();
    $card->save();
});
$router->post('/card/gallery/add', function () use ($card, $requireAuth) {
    $requireAuth();
    $card->uploadGalleryImage();
});
$router->post('/card/gallery/{id}/delete', function (array $params) use ($card, $requireAuth) {
    $requireAuth();
    $card->deleteGalleryImage($params);
});
$router->post('/card/offerings/add', function () use ($card, $requireAuth) {
    $requireAuth();
    $card->addOffering();
});
$router->post('/card/offerings/{id}/delete', function (array $params) use ($card, $requireAuth) {
    $requireAuth();
    $card->deleteOffering($params);
});

$onboarding = new OnboardingController($db, $view, $auth, $translator, $logoUploader, $photoUploader, $config['app']['url']);
// Literal paths must be registered before the /onboarding/{step} pattern below,
// since {step} matches any single path segment (e.g. "success", "check-slug").
$router->get('/onboarding', function () use ($onboarding, $requireAuth) {
    $requireAuth();
    $onboarding->index();
});
$router->get('/onboarding/success', function () use ($onboarding, $requireAuth) {
    $requireAuth();
    $onboarding->success();
});
$router->get('/onboarding/check-slug', function () use ($onboarding, $requireAuth) {
    $requireAuth();
    $onboarding->checkSlug();
});
$router->post('/onboarding/publish', function () use ($onboarding, $requireAuth) {
    $requireAuth();
    $onboarding->publish();
});
$router->get('/onboarding/{step}', function (array $params) use ($onboarding, $requireAuth) {
    $requireAuth();
    $onboarding->showStep($params);
});
$router->post('/onboarding/{step}', function (array $params) use ($onboarding, $requireAuth) {
    $requireAuth();
    $onboarding->saveStep($params);
});
$router->get('/card/preview', function () use ($onboarding, $requireAuth) {
    $requireAuth();
    $onboarding->preview();
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
$router->get('/qr/demo.svg', fn () => $qrCode->demoSvg());
$router->get('/qr/{slug}.png', fn (array $params) => $qrCode->publicPng($params));
$router->get('/qr/{slug}.svg', fn (array $params) => $qrCode->publicSvg($params));
$router->get('/go/{slug}/{type}', fn (array $params) => $card->trackClick($params));
$router->get('/vcard/{slug}.vcf', fn (array $params) => $card->downloadVcard($params));

$stripe = new StripeService(
    $config['stripe']['secret_key'],
    $config['stripe']['price_id_pro_monthly'],
    $config['stripe']['price_id_pro_yearly'],
    $config['stripe']['price_id_firma_monthly'],
    $config['app']['url']
);

$pricing = new PricingController($view, $auth, $stripe);
$router->get('/pricing', fn () => $pricing->index());

$legal = new LegalController($view, $translator, $db, $locale);
$router->get('/impressum', fn () => $legal->show('impressum'));
$router->get('/datenschutz', fn () => $legal->show('datenschutz'));

$contact = new ContactController($view, $translator, $mailer, $config['mail']['contact_address']);
$router->get('/kontakt', fn () => $contact->show());
$router->post('/kontakt', fn () => $contact->submit());

$landing = new LandingController($view);
foreach (array_keys(LandingController::PROFESSIONS) as $profession) {
    $router->get('/digitale-visitenkarte-' . $profession, fn () => $landing->show(['profession' => $profession]));
}

$demoCard = new DemoCardController($view);
$router->get('/demo-card/{design}', fn (array $params) => $demoCard->show($params));

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

$team = new TeamController($db, $auth, $view, $translator, $stripe, $orgLogoUploader, $photoUploader, $mailer, $config['app']['url']);
$router->get('/team', function () use ($team, $requireAuth) {
    $requireAuth();
    $team->index();
});
$router->post('/team/create', function () use ($team, $requireAuth) {
    $requireAuth();
    $team->create();
});
$router->post('/team/branding', function () use ($team, $requireOrgOwner) {
    $requireOrgOwner();
    $team->branding();
});
$router->post('/team/invite', function () use ($team, $requireOrgOwner) {
    $requireOrgOwner();
    $team->invite();
});
$router->post('/team/invite-csv', function () use ($team, $requireOrgOwner) {
    $requireOrgOwner();
    $team->inviteCsv();
});
$router->post('/team/reinvite/{userId}', function (array $params) use ($team, $requireOrgOwner) {
    $requireOrgOwner();
    $team->reinvite($params);
});
$router->get('/invite/{token}', fn (array $params) => $team->showAcceptInvite($params));
$router->get('/team/complete-profile', function () use ($team, $requireAuth) {
    $requireAuth();
    $team->showCompleteProfile();
});
$router->post('/team/complete-profile', function () use ($team, $requireAuth) {
    $requireAuth();
    $team->completeProfile();
});
$router->post('/team/remove/{userId}', function (array $params) use ($team, $requireOrgOwner) {
    $requireOrgOwner();
    $team->remove($params);
});
$router->post('/team/checkout', function () use ($team, $requireOrgOwner) {
    $requireOrgOwner();
    $team->checkout();
});
$router->post('/team/portal', function () use ($team, $requireOrgOwner) {
    $requireOrgOwner();
    $team->portal();
});
$router->post('/team/dev-activate', function () use ($team, $requireOrgOwner) {
    $requireOrgOwner();
    $team->devActivate();
});

$router->get('/lang/{locale}', function (array $params) {
    if (in_array($params['locale'], Translator::SUPPORTED_LOCALES, true)) {
        Session::set('locale', $params['locale']);
    }

    $referer = $_SERVER['HTTP_REFERER'] ?? '/';
    header('Location: ' . $referer);
    exit;
});

$admin = new AdminController($db, $view, $auth, $translator, $logoUploader, $photoUploader, $galleryUploader);
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
$router->post('/admin/users/{id}/gallery/{imageId}/delete', function (array $params) use ($admin, $requireAdmin) {
    $requireAdmin();
    $admin->deleteGalleryImage($params);
});
$router->post('/admin/users/{id}/offerings/{offeringId}/delete', function (array $params) use ($admin, $requireAdmin) {
    $requireAdmin();
    $admin->deleteOffering($params);
});
$router->get('/admin/legal', function () use ($admin, $requireAdmin) {
    $requireAdmin();
    $admin->showLegal();
});
$router->post('/admin/legal', function () use ($admin, $requireAdmin) {
    $requireAdmin();
    $admin->updateLegal();
});
$router->get('/admin/categories', function () use ($admin, $requireAdmin) {
    $requireAdmin();
    $admin->showCategories();
});
$router->post('/admin/categories/create', function () use ($admin, $requireAdmin) {
    $requireAdmin();
    $admin->createCategory();
});
$router->post('/admin/categories/{id}/update', function (array $params) use ($admin, $requireAdmin) {
    $requireAdmin();
    $admin->updateCategory($params);
});
$router->post('/admin/categories/{id}/delete', function (array $params) use ($admin, $requireAdmin) {
    $requireAdmin();
    $admin->deleteCategory($params);
});

// Catch-all for published business cards (findmichonline.com/{slug}).
// Must stay the last GET route registered so every fixed route above
// takes priority over a card slug.
$router->get('/{slug}', fn (array $params) => $card->showPublic($params));

$router->dispatch($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
