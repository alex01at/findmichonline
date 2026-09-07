<?php

declare(strict_types=1);

namespace Kartenlink\App\Controller;

use Kartenlink\App\Model\User;
use Kartenlink\App\Support\Auth;
use Kartenlink\App\Support\Features;
use Kartenlink\App\Support\Session;
use Kartenlink\App\Support\Translator;
use PDO;

/**
 * Manual plan switch used only as a fallback when Stripe is not configured
 * (no STRIPE_SECRET_KEY/STRIPE_PRICE_ID_PRO set), so the app stays testable
 * without real Stripe keys. When Stripe is configured, the pricing page uses
 * BillingController (checkout + billing portal) instead, and the plan is
 * kept in sync by StripeWebhookController.
 */
final class AccountController
{
    private User $users;

    public function __construct(private PDO $db, private Auth $auth, private Translator $translator)
    {
        $this->users = new User($db);
    }

    public function updatePlan(): void
    {
        $plan = $_POST['plan'] ?? '';

        if (!in_array($plan, [Features::FREE, Features::PRO], true)) {
            http_response_code(400);
            echo $this->translator->trans('account.errors.invalid_plan');
            return;
        }

        $user = $this->auth->user();
        $this->users->updatePlan((int) $user['id'], $plan);

        Session::flash(
            'success',
            $this->translator->trans($plan === Features::PRO ? 'account.plan_updated_pro' : 'account.plan_updated_free')
        );

        header('Location: /pricing');
        exit;
    }
}
