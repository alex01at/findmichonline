<?php

declare(strict_types=1);

namespace Kartenlink\App\Model;

use PDO;

final class BusinessCard
{
    public const AVAILABLE_DESIGNS = ['classic', 'modern', 'professional', 'playful'];

    // Slugs that would collide with a real application route, since
    // cards are published at the domain root (/{slug}).
    public const RESERVED_SLUGS = [
        'login', 'register', 'logout', 'dashboard', 'pricing', 'card',
        'billing', 'lang', 'webhook', 'account', 'c', 'public', 'admin',
        'forgot-password', 'reset-password',
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

    public function incrementViewCount(int $id): void
    {
        $stmt = $this->db->prepare('UPDATE business_cards SET view_count = view_count + 1 WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    /** link type => column. Only these exact columns can ever be reached from incrementClickCount(). */
    public const CLICK_COLUMNS = [
        'phone' => 'clicks_phone',
        'email' => 'clicks_email',
        'website' => 'clicks_website',
        'address' => 'clicks_address',
        'linkedin' => 'clicks_linkedin',
        'instagram' => 'clicks_instagram',
        'facebook' => 'clicks_facebook',
        'youtube' => 'clicks_youtube',
    ];

    public function incrementClickCount(int $id, string $type): void
    {
        $column = self::CLICK_COLUMNS[$type] ?? null;
        if ($column === null) {
            return;
        }

        $stmt = $this->db->prepare("UPDATE business_cards SET {$column} = {$column} + 1 WHERE id = :id");
        $stmt->execute(['id' => $id]);
    }

    public function findPublishedBySlug(string $slug): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT c.*, u.plan AS owner_plan
             FROM business_cards c
             JOIN users u ON u.id = c.user_id
             WHERE c.slug = :slug AND c.is_published = 1'
        );
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
                    (user_id, slug, display_name, job_title, company, email, phone, website, address, bio, opening_hours, logo_path, linkedin_url, instagram_url, facebook_url, youtube_url, design, use_custom_colors, color_background, color_header, color_content, color_footer, is_published, created_at, updated_at)
                 VALUES
                    (:user_id, :slug, :display_name, :job_title, :company, :email, :phone, :website, :address, :bio, :opening_hours, :logo_path, :linkedin_url, :instagram_url, :facebook_url, :youtube_url, :design, :use_custom_colors, :color_background, :color_header, :color_content, :color_footer, :is_published, NOW(), NOW())'
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
                    opening_hours = :opening_hours,
                    logo_path = :logo_path,
                    linkedin_url = :linkedin_url,
                    instagram_url = :instagram_url,
                    facebook_url = :facebook_url,
                    youtube_url = :youtube_url,
                    design = :design,
                    use_custom_colors = :use_custom_colors,
                    color_background = :color_background,
                    color_header = :color_header,
                    color_content = :color_content,
                    color_footer = :color_footer,
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
            'opening_hours' => $data['opening_hours'],
            'logo_path' => $data['logo_path'],
            'linkedin_url' => $data['linkedin_url'],
            'instagram_url' => $data['instagram_url'],
            'facebook_url' => $data['facebook_url'],
            'youtube_url' => $data['youtube_url'],
            'use_custom_colors' => $data['use_custom_colors'] ? 1 : 0,
            'color_background' => $data['color_background'],
            'color_header' => $data['color_header'],
            'color_content' => $data['color_content'],
            'color_footer' => $data['color_footer'],
            'is_published' => $data['is_published'] ? 1 : 0,
        ]);
    }

    public static function isValidHexColor(string $value): bool
    {
        return preg_match('/^#[0-9a-fA-F]{6}$/', $value) === 1;
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
