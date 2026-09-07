<?php

declare(strict_types=1);

namespace Kartenlink\App\Controller;

use Kartenlink\App\Model\User;
use Kartenlink\App\Support\Features;
use Kartenlink\App\Support\StripeService;
use PDO;
use Stripe\Exception\SignatureVerificationException;

final class StripeWebhookController
{
    private User $users;

    public function __construct(private PDO $db, private StripeService $stripe, private string $webhookSecret)
    {
        $this->users = new User($db);
    }

    public function handle(): void
    {
        $payload = file_get_contents('php://input');
        $sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

        try {
            $event = $this->stripe->constructWebhookEvent($payload, $sigHeader, $this->webhookSecret);
        } catch (\UnexpectedValueException|SignatureVerificationException) {
            http_response_code(400);
            echo 'invalid signature';
            return;
        }

        match ($event->type) {
            'checkout.session.completed' => $this->handleCheckoutCompleted($event->data->object),
            'customer.subscription.updated' => $this->handleSubscriptionChange($event->data->object, false),
            'customer.subscription.deleted' => $this->handleSubscriptionChange($event->data->object, true),
            default => null,
        };

        http_response_code(200);
        echo 'ok';
    }

    private function handleCheckoutCompleted(object $session): void
    {
        $userId = (int) ($session->client_reference_id ?? 0);
        if ($userId <= 0 || $session->customer === null) {
            return;
        }

        $this->users->syncStripeSubscription($userId, [
            'plan' => Features::PRO,
            'stripe_customer_id' => $session->customer,
            'stripe_subscription_id' => $session->subscription,
            'subscription_status' => 'active',
            'cancel_at_period_end' => false,
        ]);
    }

    private function handleSubscriptionChange(object $subscription, bool $isDeleted): void
    {
        $user = $this->users->findByStripeCustomerId($subscription->customer);
        if ($user === null) {
            return;
        }

        $isActive = !$isDeleted && in_array($subscription->status, ['active', 'trialing'], true);

        $this->users->syncStripeSubscription((int) $user['id'], [
            'plan' => $isActive ? Features::PRO : Features::FREE,
            'stripe_customer_id' => $subscription->customer,
            'stripe_subscription_id' => $isDeleted ? null : $subscription->id,
            'subscription_status' => $isDeleted ? 'canceled' : $subscription->status,
            'cancel_at_period_end' => $isDeleted ? false : (bool) $subscription->cancel_at_period_end,
        ]);
    }
}
