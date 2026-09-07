<?php

declare(strict_types=1);

namespace Kartenlink\App\Controller;

use Kartenlink\App\Support\Auth;
use Kartenlink\App\Support\StripeService;
use Kartenlink\App\Support\View;

final class PricingController
{
    public function __construct(private View $view, private Auth $auth, private StripeService $stripe)
    {
    }

    public function index(): void
    {
        $user = $this->auth->check() ? $this->auth->user() : null;

        echo $this->view->render('pricing.twig', [
            'current_plan' => $user['plan'] ?? null,
            'cancel_at_period_end' => (bool) ($user['cancel_at_period_end'] ?? false),
            'stripe_configured' => $this->stripe->isConfigured(),
            'has_stripe_customer' => !empty($user['stripe_customer_id'] ?? null),
        ]);
    }
}
