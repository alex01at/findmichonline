<?php

declare(strict_types=1);

namespace Kartenlink\App\Model;

use PDO;

final class BusinessCard
{
    public const AVAILABLE_DESIGNS = ['classic', 'modern'];

    // Slugs that would collide with a real application route, since
    // cards are published at the domain root (/{slug}).
    public const RESERVED_SLUGS = [
        'login', 'register', 'logout', 'dashboard', 'pricing', 'card',
        'billing', 'lang', 'webhook', 'account', 'c', 'public', 'admin',
    ];

    public function __construct(private PDO $db)
    {
    }

    public function findByUserId(int $userId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM business_cards WHERE user_id = :user_id');
        $stmt->execute(['user_id' => $userId]);
        $card = $stmt->fetch();
        return $card ?: null;
    }

    public function findPublishedBySlug(string $slug): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM business_cards WHERE slug = :slug AND is_published = 1');
        $stmt->execute(['slug' => $slug]);
        $card = $stmt->fetch();
        return $card ?: null;
    }

    public function deleteForUser(int $userId): void
    {
        $stmt = $this->db->prepare('DELETE FROM business_cards WHERE user_id = :user_id');
        $stmt->execute(['user_id' => $userId]);
    }

    public function slugExists(string $slug, ?int $excludeUserId = null): bool
    {
        if ($excludeUserId !== null) {
            $stmt = $this->db->prepare('SELECT COUNT(*) FROM business_cards WHERE slug = :slug AND user_id != :user_id');
            $stmt->execute(['slug' => $slug, 'user_id' => $excludeUserId]);
        } else {
            $stmt = $this->db->prepare('SELECT COUNT(*) FROM business_cards WHERE slug = :slug');
            $stmt->execute(['slug' => $slug]);
        }

        return (int) $stmt->fetchColumn() > 0;
    }

    public function upsertForUser(int $userId, array $data): void
    {
        $existing = $this->findByUserId($userId);

        if ($existing === null) {
            $stmt = $this->db->prepare(
                'INSERT INTO business_cards
                    (user_id, slug, display_name, job_title, company, email, phone, website, address, bio, design, is_published, created_at, updated_at)
                 VALUES
                    (:user_id, :slug, :display_name, :job_title, :company, :email, :phone, :website, :address, :bio, :design, :is_published, NOW(), NOW())'
            );
        } else {
            $stmt = $this->db->prepare(
                'UPDATE business_cards SET
                    slug = :slug,
                    display_name = :display_name,
                    job_title = :job_title,
                    company = :company,
                    email = :email,
                    phone = :phone,
                    website = :website,
                    address = :address,
                    bio = :bio,
                    design = :design,
                    is_published = :is_published,
                    updated_at = NOW()
                 WHERE user_id = :user_id'
            );
        }

        $stmt->execute([
            'user_id' => $userId,
            'slug' => $data['slug'],
            'display_name' => $data['display_name'],
            'job_title' => $data['job_title'],
            'company' => $data['company'],
            'email' => $data['email'],
            'phone' => $data['phone'],
            'website' => $data['website'],
            'design' => $data['design'],
            'address' => $data['address'],
            'bio' => $data['bio'],
            'is_published' => $data['is_published'] ? 1 : 0,
        ]);
    }

    public function slugify(string $text): string
    {
        $text = strtolower(trim($text));
        $replacements = ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss'];
        $text = strtr($text, $replacements);
        $text = preg_replace('/[^a-z0-9]+/', '-', $text) ?? '';
        $text = trim($text, '-');

        return $text !== '' ? $text : 'karte';
    }

    /** @param string[] $reservedSlugs */
    public function generateUniqueSlug(string $base, ?int $excludeUserId = null, array $reservedSlugs = []): string
    {
        $slug = $this->slugify($base);
        $candidate = $slug;
        $suffix = 2;

        while ($this->slugExists($candidate, $excludeUserId) || in_array($candidate, $reservedSlugs, true)) {
            $candidate = $slug . '-' . $suffix;
            $suffix++;
        }

        return $candidate;
    }
}
