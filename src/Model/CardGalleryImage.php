<?php

declare(strict_types=1);

namespace Kartenlink\App\Model;

use PDO;

final class CardGalleryImage
{
    public const MAX_IMAGES = 6;

    public function __construct(private PDO $db)
    {
    }

    public function findByCardId(int $cardId): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM card_gallery_images WHERE business_card_id = :card_id ORDER BY sort_order ASC, id ASC'
        );
        $stmt->execute(['card_id' => $cardId]);
        return $stmt->fetchAll();
    }

    public function countByCardId(int $cardId): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM card_gallery_images WHERE business_card_id = :card_id');
        $stmt->execute(['card_id' => $cardId]);
        return (int) $stmt->fetchColumn();
    }

    public function add(int $cardId, string $imagePath, int $sortOrder): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO card_gallery_images (business_card_id, image_path, sort_order, created_at)
             VALUES (:card_id, :path, :sort_order, NOW())'
        );
        $stmt->execute(['card_id' => $cardId, 'path' => $imagePath, 'sort_order' => $sortOrder]);
    }

    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM card_gallery_images WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    public function delete(int $id): void
    {
        $stmt = $this->db->prepare('DELETE FROM card_gallery_images WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }
}
