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
