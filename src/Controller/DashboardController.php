<?php

declare(strict_types=1);

namespace Kartenlink\App\Controller;

use Kartenlink\App\Model\BusinessCard;
use Kartenlink\App\Support\Auth;
use Kartenlink\App\Support\View;
use PDO;

final class DashboardController
{
    private BusinessCard $cards;

    public function __construct(private PDO $db, private View $view, private Auth $auth)
    {
        $this->cards = new BusinessCard($db);
    }

    public function index(): void
    {
        $user = $this->auth->user();
        $card = $this->cards->findByUserId((int) $user['id']);

        if ($card === null || $card['onboarding_completed_at'] === null) {
            header('Location: /onboarding');
            exit;
        }

        $trialDaysLeft = null;
        if ($this->auth->isOnIndividualTrial()) {
            $trialDaysLeft = (int) ceil((strtotime($user['trial_ends_at']) - time()) / 86400);
        }

        $demoMinutesLeft = null;
        if (!empty($user['is_demo']) && !empty($user['demo_expires_at'])) {
            $demoMinutesLeft = max(0, (int) ceil((strtotime($user['demo_expires_at']) - time()) / 60));
        }

        echo $this->view->render('dashboard/index.twig', [
            'user' => $user,
            'card' => $card,
            'can_view_stats' => $this->auth->can('view_stats'),
            'trial_days_left' => $trialDaysLeft,
            'demo_minutes_left' => $demoMinutesLeft,
        ]);
    }
}
