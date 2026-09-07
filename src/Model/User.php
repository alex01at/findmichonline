<?php

declare(strict_types=1);

namespace Kartenlink\App\Model;

use PDO;

final class User
{
    public function __construct(private PDO $db)
    {
    }

    public function findByEmail(string $email): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM users WHERE email = :email');
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch();
        return $user ?: null;
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM users WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $user = $stmt->fetch();
        return $user ?: null;
    }

    public function create(string $name, string $email, string $passwordHash): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO users (name, email, password_hash, created_at) VALUES (:name, :email, :password_hash, NOW())'
        );
        $stmt->execute([
            'name' => $name,
            'email' => $email,
            'password_hash' => $passwordHash,
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function updatePlan(int $id, string $plan): void
    {
        $stmt = $this->db->prepare('UPDATE users SET plan = :plan WHERE id = :id');
        $stmt->execute(['plan' => $plan, 'id' => $id]);
    }

    /** @return array<int, array<string, mixed>> */
    public function findAllWithCardInfo(): array
    {
        $stmt = $this->db->query(
            'SELECT u.*, c.slug AS card_slug, c.is_published AS card_is_published
             FROM users u
             LEFT JOIN business_cards c ON c.user_id = u.id
             ORDER BY u.created_at DESC'
        );

        return $stmt->fetchAll();
    }

    public function updateProfile(int $id, string $name, string $email, string $plan, bool $isAdmin): void
    {
        $stmt = $this->db->prepare(
            'UPDATE users SET name = :name, email = :email, plan = :plan, is_admin = :is_admin WHERE id = :id'
        );
        $stmt->execute([
            'name' => $name,
            'email' => $email,
            'plan' => $plan,
            'is_admin' => $isAdmin ? 1 : 0,
            'id' => $id,
        ]);
    }

    public function updatePasswordHash(int $id, string $passwordHash): void
    {
        $stmt = $this->db->prepare('UPDATE users SET password_hash = :password_hash WHERE id = :id');
        $stmt->execute(['password_hash' => $passwordHash, 'id' => $id]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->db->prepare('DELETE FROM users WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    public function setPasswordResetToken(int $id, string $token, int $ttlSeconds): void
    {
        // Expiry is computed by MySQL itself (NOW() + INTERVAL) rather than
        // passed in from PHP, so the comparison in findByValidResetToken()
        // isn't thrown off by PHP and MySQL running in different timezones.
        $stmt = $this->db->prepare(
            'UPDATE users SET password_reset_token = :token, password_reset_expires_at = DATE_ADD(NOW(), INTERVAL :ttl SECOND) WHERE id = :id'
        );
        $stmt->execute(['token' => $token, 'ttl' => $ttlSeconds, 'id' => $id]);
    }

    public function findByValidResetToken(string $token): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM users WHERE password_reset_token = :token AND password_reset_expires_at > NOW()'
        );
        $stmt->execute(['token' => $token]);
        $user = $stmt->fetch();
        return $user ?: null;
    }

    public function resetPassword(int $id, string $passwordHash): void
    {
        $stmt = $this->db->prepare(
            'UPDATE users SET password_hash = :password_hash, password_reset_token = NULL, password_reset_expires_at = NULL WHERE id = :id'
        );
        $stmt->execute(['password_hash' => $passwordHash, 'id' => $id]);
    }

    public function findByStripeCustomerId(string $customerId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM users WHERE stripe_customer_id = :customer_id');
        $stmt->execute(['customer_id' => $customerId]);
        $user = $stmt->fetch();
        return $user ?: null;
    }

    public function syncStripeSubscription(int $id, array $data): void
    {
        $stmt = $this->db->prepare(
            'UPDATE users SET
                plan = :plan,
                stripe_customer_id = :stripe_customer_id,
                stripe_subscription_id = :stripe_subscription_id,
                subscription_status = :subscription_status,
                cancel_at_period_end = :cancel_at_period_end
             WHERE id = :id'
        );
        $stmt->execute([
            'plan' => $data['plan'],
            'stripe_customer_id' => $data['stripe_customer_id'],
            'stripe_subscription_id' => $data['stripe_subscription_id'],
            'subscription_status' => $data['subscription_status'],
            'cancel_at_period_end' => $data['cancel_at_period_end'] ? 1 : 0,
            'id' => $id,
        ]);
    }
}
