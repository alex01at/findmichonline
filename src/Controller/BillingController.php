<?php

declare(strict_types=1);

namespace Kartenlink\App\Controller;

use Kartenlink\App\Support\Auth;
use Kartenlink\App\Support\Session;
use Kartenlink\App\Support\StripeService;
use Kartenlink\App\Support\Translator;
use Kartenlink\App\Support\View;

final class BillingController
{
    public function __construct(private Auth $auth, private StripeService $stripe, private View $view, private Translator $translator)
    {
    }

    public function checkout(): void
    {
        if (!$this->stripe->isConfigured()) {
            Session::flash('error', $this->translator->trans('billing.error.not_configured'));
            $this->redirect('/pricing');
        }

        $user = $this->auth->user();

        try {
            $session = $this->stripe->createCheckoutSession($user);
        } catch (\Throwable) {
            Session::flash('error', $this->translator->trans('billing.error.checkout_failed'));
            $this->redirect('/pricing');
        }

        $this->redirect($session->url);
    }

    public function portal(): void
    {
        $user = $this->auth->user();

        if (empty($user['stripe_customer_id'])) {
            $this->redirect('/pricing');
        }

        try {
            $session = $this->stripe->createBillingPortalSession($user['stripe_customer_id']);
        } catch (\Throwable) {
            Session::flash('error', $this->translator->trans('billing.error.portal_failed'));
            $this->redirect('/pricing');
        }

        $this->redirect($session->url);
    }

    public function success(): void
    {
        echo $this->view->render('billing/success.twig');
    }

    private function redirect(string $url): never
    {
        header('Location: ' . $url);
        exit;
    }
}
