<?php

declare(strict_types=1);

namespace Kartenlink\App\Model;

use PDO;

final class LegalPage
{
    public const PAGES = ['impressum', 'datenschutz'];

    public function __construct(private PDO $db)
    {
    }

    public function find(string $slug): ?array
    {
        if (!in_array($slug, self::PAGES, true)) {
            return null;
        }

        $stmt = $this->db->prepare('SELECT * FROM legal_pages WHERE slug = :slug');
        $stmt->execute(['slug' => $slug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    /** @return array<string, array<string, mixed>> keyed by slug */
    public function all(): array
    {
        $rows = $this->db->query('SELECT * FROM legal_pages')->fetchAll(PDO::FETCH_ASSOC);

        $bySlug = [];
        foreach ($rows as $row) {
            $bySlug[$row['slug']] = $row;
        }

        return $bySlug;
    }

    public function update(string $slug, string $contentDe, string $contentEn): void
    {
        if (!in_array($slug, self::PAGES, true)) {
            return;
        }

        $stmt = $this->db->prepare(
            'UPDATE legal_pages SET content_de = :de, content_en = :en, updated_at = NOW() WHERE slug = :slug'
        );
        $stmt->execute(['de' => $contentDe, 'en' => $contentEn, 'slug' => $slug]);
    }
}
