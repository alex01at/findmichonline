<?php

declare(strict_types=1);

namespace Kartenlink\App\Controller;

use Kartenlink\App\Model\BusinessCard;
use Kartenlink\App\Model\User;
use Kartenlink\App\Support\Auth;
use Kartenlink\App\Support\Session;
use Kartenlink\App\Support\Translator;
use PDO;

/**
 * A demo account is a real, but marked and time-limited `users` row - not a
 * separate "fake" code path. That way the existing dashboard/editor/card
 * logic needs zero special-casing; only account creation (here) and cleanup
 * (bin/cleanup-demo-accounts.php) know about `is_demo`.
 */
final class DemoController
{
    private const TRIAL_DAYS = 7;
    private const DEMO_HOURS = 2;
    private const MAX_SIGNUPS_PER_IP_PER_DAY = 5;

    private User $users;
    private BusinessCard $cards;

    public function __construct(private PDO $db, private Auth $auth, private Translator $translator)
    {
        $this->users = new User($db);
        $this->cards = new BusinessCard($db);
    }

    public function start(): void
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';

        if ($ip !== '' && $this->users->countRecentDemoSignups($ip) >= self::MAX_SIGNUPS_PER_IP_PER_DAY) {
            Session::flash('error', $this->translator->trans('demo.rate_limited'));
            $this->redirect('/');
        }

        $email = 'demo-' . bin2hex(random_bytes(6)) . '@demo.findmichonline.local';
        $passwordHash = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);

        $userId = $this->users->create('Demo Nutzer', $email, $passwordHash);
        $this->users->startTrial($userId, self::TRIAL_DAYS);
        $this->users->markAsDemo($userId, $ip, self::DEMO_HOURS);

        $persona = DemoCardController::PERSONAS['default'];
        $data = array_merge(DemoCardController::BASE_DEFAULTS, $persona, [
            'slug' => $this->cards->generateUniqueSlug($persona['slug'], $userId, BusinessCard::RESERVED_SLUGS),
            'design' => 'classic',
            'is_published' => true,
        ]);

        $this->cards->upsertForUser($userId, $data);
        $savedCard = $this->cards->findByUserId($userId);
        $this->cards->markOnboardingDoneIfNeeded((int) $savedCard['id']);

        $this->auth->login(['id' => $userId]);

        Session::flash('success', $this->translator->trans('demo.started'));
        $this->redirect('/dashboard');
    }

    private function redirect(string $path): void
    {
        header('Location: ' . $path);
        exit;
    }
}
