<?php

declare(strict_types=1);

namespace Reklamova\Cms\Modules\Trust;

use PDO;

final class TrustRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function all(bool $publishedOnly = false, ?string $type = null): array
    {
        $where = [];
        $params = [];
        if ($publishedOnly) {
            $where[] = 'status = "published"';
        }
        if ($type !== null && $type !== '') {
            $where[] = 'type = ?';
            $params[] = $type;
        }
        $sql = 'SELECT * FROM trust_items' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY sort_order ASC, id DESC';
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        return $statement->fetchAll();
    }

    public function find(int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM trust_items WHERE id = ? LIMIT 1');
        $statement->execute([$id]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    public function save(array $data, ?int $id = null): int
    {
        $payload = [
            'type' => trim((string) ($data['type'] ?? 'certificate')) ?: 'certificate',
            'title' => trim((string) ($data['title'] ?? '')),
            'subtitle' => trim((string) ($data['subtitle'] ?? '')),
            'description' => trim((string) ($data['description'] ?? '')),
            'value' => trim((string) ($data['value'] ?? '')),
            'image' => trim((string) ($data['image'] ?? '')),
            'file_url' => trim((string) ($data['file_url'] ?? '')),
            'external_url' => trim((string) ($data['external_url'] ?? '')),
            'is_featured' => !empty($data['is_featured']) ? 1 : 0,
            'status' => in_array((string) ($data['status'] ?? 'draft'), ['draft', 'published'], true) ? (string) $data['status'] : 'draft',
            'sort_order' => (int) ($data['sort_order'] ?? 100),
        ];

        if ($id) {
            $sets = implode(', ', array_map(static fn (string $field): string => "{$field} = ?", array_keys($payload)));
            $statement = $this->pdo->prepare("UPDATE trust_items SET {$sets}, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
            $statement->execute([...array_values($payload), $id]);
            return $id;
        }

        $columns = implode(', ', array_keys($payload));
        $placeholders = implode(', ', array_fill(0, count($payload), '?'));
        $statement = $this->pdo->prepare("INSERT INTO trust_items ({$columns}) VALUES ({$placeholders})");
        $statement->execute(array_values($payload));
        return (int) $this->pdo->lastInsertId();
    }

    public function delete(int $id): void
    {
        $statement = $this->pdo->prepare('DELETE FROM trust_items WHERE id = ?');
        $statement->execute([$id]);
    }
}
