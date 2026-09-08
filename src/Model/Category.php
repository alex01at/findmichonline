<?php

declare(strict_types=1);

namespace Kartenlink\App\Model;

use PDO;

final class Category
{
    public function __construct(private PDO $db)
    {
    }

    public function all(): array
    {
        return $this->db->query('SELECT * FROM categories ORDER BY sort_order ASC, name_de ASC')->fetchAll();
    }

    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM categories WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    public function exists(int $id): bool
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM categories WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return (int) $stmt->fetchColumn() > 0;
    }

    public function create(string $nameDe, string $nameEn, int $sortOrder): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO categories (name_de, name_en, sort_order, created_at) VALUES (:name_de, :name_en, :sort_order, NOW())'
        );
        $stmt->execute(['name_de' => $nameDe, 'name_en' => $nameEn, 'sort_order' => $sortOrder]);
    }

    public function update(int $id, string $nameDe, string $nameEn, int $sortOrder): void
    {
        $stmt = $this->db->prepare(
            'UPDATE categories SET name_de = :name_de, name_en = :name_en, sort_order = :sort_order WHERE id = :id'
        );
        $stmt->execute(['id' => $id, 'name_de' => $nameDe, 'name_en' => $nameEn, 'sort_order' => $sortOrder]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->db->prepare('DELETE FROM categories WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }
}
