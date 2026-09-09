<?php

declare(strict_types=1);

namespace Kartenlink\App\Support;

use Stripe\BillingPortal\Session as BillingPortalSession;
use Stripe\Checkout\Session as CheckoutSession;
use Stripe\Event;
use Stripe\Stripe;
use Stripe\SubscriptionItem;
use Stripe\Webhook;

final class StripeService
{
    public function __construct(
        private string $secretKey,
        private string $priceIdProMonthly,
        private string $priceIdProYearly,
        private string $priceIdFirmaMonthly,
        private string $appUrl
    ) {
        if ($this->secretKey !== '') {
            Stripe::setApiKey($this->secretKey);
        }
    }

    public function isConfigured(): bool
    {
        return $this->secretKey !== '' && $this->priceIdProMonthly !== '' && $this->priceIdProYearly !== '';
    }

    public function isFirmaConfigured(): bool
    {
        return $this->secretKey !== '' && $this->priceIdFirmaMonthly !== '';
    }

    public function createCheckoutSession(array $user, string $interval): CheckoutSession
    {
        $priceId = $interval === 'yearly' ? $this->priceIdProYearly : $this->priceIdProMonthly;

        $params = [
            'mode' => 'subscription',
            'line_items' => [[
                'price' => $priceId,
                'quantity' => 1,
            ]],
            'success_url' => $this->appUrl . '/billing/success?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $this->appUrl . '/pricing',
            'client_reference_id' => (string) $user['id'],
        ];

        if (!empty($user['stripe_customer_id'])) {
            $params['customer'] = $user['stripe_customer_id'];
        } else {
            $params['customer_email'] = $user['email'];
        }

        return CheckoutSession::create($params);
    }

    public function createFirmaCheckoutSession(array $org, int $seats): CheckoutSession
    {
        $params = [
            'mode' => 'subscription',
            'line_items' => [[
                'price' => $this->priceIdFirmaMonthly,
                'quantity' => $seats,
            ]],
            'success_url' => $this->appUrl . '/team',
            'cancel_url' => $this->appUrl . '/team',
            'metadata' => [
                'organization_id' => (string) $org['id'],
            ],
        ];

        if (!empty($org['stripe_customer_id'])) {
            $params['customer'] = $org['stripe_customer_id'];
        } elseif (!empty($org['owner_email'])) {
            $params['customer_email'] = $org['owner_email'];
        }

        return CheckoutSession::create($params);
    }

    public function updateSubscriptionItemQuantity(string $subscriptionItemId, int $quantity): void
    {
        SubscriptionItem::update($subscriptionItemId, ['quantity' => $quantity]);
    }

    public function createBillingPortalSession(string $customerId): BillingPortalSession
    {
        return BillingPortalSession::create([
            'customer' => $customerId,
            'return_url' => $this->appUrl . '/dashboard',
        ]);
    }

    public function constructWebhookEvent(string $payload, string $sigHeader, string $webhookSecret): Event
    {
        return Webhook::constructEvent($payload, $sigHeader, $webhookSecret);
    }
}
