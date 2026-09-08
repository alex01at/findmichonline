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

        echo $this->view->render('dashboard/index.twig', [
            'user' => $user,
            'card' => $card,
            'can_view_stats' => $this->auth->can('view_stats'),
        ]);
    }
}
