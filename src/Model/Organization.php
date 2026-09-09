<?php

declare(strict_types=1);

namespace Kartenlink\App\Model;

use PDO;

final class Organization
{
    public function __construct(private PDO $db)
    {
    }

    public function create(string $name, int $ownerUserId, int $trialDays): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO organizations (name, owner_user_id, seats, trial_ends_at, created_at)
             VALUES (:name, :owner_user_id, 5, DATE_ADD(NOW(), INTERVAL :days DAY), NOW())'
        );
        $stmt->execute([
            'name' => $name,
            'owner_user_id' => $ownerUserId,
            'days' => $trialDays,
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM organizations WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $org = $stmt->fetch();
        return $org ?: null;
    }

    public function findByStripeCustomerId(string $customerId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM organizations WHERE stripe_customer_id = :customer_id');
        $stmt->execute(['customer_id' => $customerId]);
        $org = $stmt->fetch();
        return $org ?: null;
    }

    public function memberCount(int $organizationId): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM users WHERE organization_id = :organization_id');
        $stmt->execute(['organization_id' => $organizationId]);
        return (int) $stmt->fetchColumn();
    }

    /** @return array<int, array<string, mixed>> */
    public function listMembers(int $organizationId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, name, email, created_at FROM users WHERE organization_id = :organization_id ORDER BY created_at ASC'
        );
        $stmt->execute(['organization_id' => $organizationId]);
        return $stmt->fetchAll();
    }

    public function updateSeats(int $id, int $seats): void
    {
        $stmt = $this->db->prepare('UPDATE organizations SET seats = :seats WHERE id = :id');
        $stmt->execute(['seats' => $seats, 'id' => $id]);
    }

    public function updateBranding(int $id, ?string $logoPath, ?string $address, string $design): void
    {
        $stmt = $this->db->prepare(
            'UPDATE organizations SET logo_path = :logo_path, address = :address, design = :design WHERE id = :id'
        );
        $stmt->execute([
            'logo_path' => $logoPath,
            'address' => $address,
            'design' => $design,
            'id' => $id,
        ]);
    }

    /**
     * Resolves the branding fields (company name, logo, address, design) an
     * employee's card should display - always live from the organization,
     * never copied into business_cards, so an owner changing the branding
     * later takes effect immediately on every member's card without anyone
     * needing to re-save. No-op (returns $card unchanged) when $org is null,
     * which callers use for the owner's own card and any non-org card.
     */
    public static function applyBranding(array $card, ?array $org): array
    {
        if ($org === null) {
            return $card;
        }

        $card['company'] = $org['name'];
        $card['logo_path'] = $org['logo_path'] ?: null;
        $card['address'] = $org['address'] ?: null;
        $card['design'] = in_array($org['design'] ?? null, BusinessCard::AVAILABLE_DESIGNS, true)
            ? $org['design']
            : 'classic';

        return $card;
    }

    public function syncStripeSubscription(int $id, array $data): void
    {
        $stmt = $this->db->prepare(
            'UPDATE organizations SET
                stripe_customer_id = :stripe_customer_id,
                stripe_subscription_id = :stripe_subscription_id,
                stripe_subscription_item_id = :stripe_subscription_item_id,
                subscription_status = :subscription_status,
                cancel_at_period_end = :cancel_at_period_end
             WHERE id = :id'
        );
        $stmt->execute([
            'stripe_customer_id' => $data['stripe_customer_id'],
            'stripe_subscription_id' => $data['stripe_subscription_id'],
            'stripe_subscription_item_id' => $data['stripe_subscription_item_id'] ?? null,
            'subscription_status' => $data['subscription_status'],
            'cancel_at_period_end' => $data['cancel_at_period_end'] ? 1 : 0,
            'id' => $id,
        ]);
    }
}
