<?php

declare(strict_types=1);

namespace Reklamova\Cms\Modules\Knowledge;

use PDO;

final class KnowledgeRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function categories(): array
    {
        return $this->pdo->query('SELECT * FROM knowledge_categories ORDER BY sort_order ASC, name ASC')->fetchAll();
    }

    public function authors(): array
    {
        return $this->pdo->query('SELECT * FROM knowledge_authors ORDER BY name ASC')->fetchAll();
    }

    public function articles(bool $publishedOnly = false, string $search = ''): array
    {
        $where = [];
        $params = [];
        if ($publishedOnly) {
            $where[] = 'a.status = "published"';
        }
        if ($search !== '') {
            $where[] = '(a.title LIKE ? OR a.excerpt LIKE ? OR a.content LIKE ?)';
            $like = '%' . $search . '%';
            $params = [$like, $like, $like];
        }
        $sql = 'SELECT a.*, c.name AS category_name, au.name AS author_name
            FROM knowledge_articles a
            LEFT JOIN knowledge_categories c ON c.id = a.category_id
            LEFT JOIN knowledge_authors au ON au.id = a.author_id'
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
            . ' ORDER BY COALESCE(a.published_at, a.created_at) DESC, a.id DESC';
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll();
    }

    public function find(string $type, int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM ' . $this->table($type) . ' WHERE id = ? LIMIT 1');
        $statement->execute([$id]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function articleBySlug(string $slug): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT a.*, c.name AS category_name, c.slug AS category_slug, au.name AS author_name, au.bio AS author_bio
             FROM knowledge_articles a
             LEFT JOIN knowledge_categories c ON c.id = a.category_id
             LEFT JOIN knowledge_authors au ON au.id = a.author_id
             WHERE a.slug = ? AND a.status = "published" LIMIT 1'
        );
        $statement->execute([$slug]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function saveCategory(array $data, ?int $id = null): int
    {
        return $this->save('categories', [
            'name' => trim((string) ($data['name'] ?? '')),
            'slug' => trim((string) ($data['slug'] ?? '')),
            'description' => trim((string) ($data['description'] ?? '')),
            'sort_order' => (int) ($data['sort_order'] ?? 100),
        ], $id);
    }

    public function saveAuthor(array $data, ?int $id = null): int
    {
        return $this->save('authors', [
            'name' => trim((string) ($data['name'] ?? '')),
            'slug' => trim((string) ($data['slug'] ?? '')),
            'bio' => trim((string) ($data['bio'] ?? '')),
            'photo' => trim((string) ($data['photo'] ?? '')),
            'role' => trim((string) ($data['role'] ?? '')),
        ], $id);
    }

    public function saveArticle(array $data, ?int $id = null): int
    {
        $title = trim((string) ($data['title'] ?? ''));
        $status = in_array((string) ($data['status'] ?? 'draft'), ['draft', 'published'], true) ? (string) $data['status'] : 'draft';
        $publishedAt = trim((string) ($data['published_at'] ?? ''));
        if ($status === 'published' && $publishedAt === '') {
            $publishedAt = date('Y-m-d H:i:s');
        }

        return $this->save('articles', [
            'title' => $title,
            'slug' => trim((string) ($data['slug'] ?? '')),
            'excerpt' => trim((string) ($data['excerpt'] ?? '')),
            'content' => (string) ($data['content'] ?? ''),
            'cover_image' => trim((string) ($data['cover_image'] ?? '')),
            'category_id' => $this->nullableInt($data['category_id'] ?? null),
            'author_id' => $this->nullableInt($data['author_id'] ?? null),
            'tags_json' => json_encode($this->tags((string) ($data['tags'] ?? '')), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'related_service_slug' => trim((string) ($data['related_service_slug'] ?? '')),
            'related_area_slug' => trim((string) ($data['related_area_slug'] ?? '')),
            'meta_title' => trim((string) ($data['meta_title'] ?? '')),
            'meta_description' => trim((string) ($data['meta_description'] ?? '')),
            'status' => $status,
            'published_at' => $publishedAt !== '' ? $publishedAt : null,
        ], $id);
    }

    public function delete(string $type, int $id): void
    {
        $statement = $this->pdo->prepare('DELETE FROM ' . $this->table($type) . ' WHERE id = ?');
        $statement->execute([$id]);
    }

    public function slugify(string $value): string
    {
        $map = ['ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ż' => 'z', 'ź' => 'z'];
        $value = strtr(mb_strtolower(trim($value), 'UTF-8'), $map);
        $value = preg_replace('/[^a-z0-9]+/i', '-', $value) ?: 'article';

        return trim($value, '-') ?: 'article';
    }

    private function save(string $type, array $payload, ?int $id): int
    {
        if (isset($payload['slug']) && $payload['slug'] === '') {
            $payload['slug'] = $this->slugify((string) ($payload['title'] ?? $payload['name'] ?? 'item'));
        }

        if ($id) {
            $sets = implode(', ', array_map(static fn (string $field): string => "{$field} = ?", array_keys($payload)));
            $statement = $this->pdo->prepare('UPDATE ' . $this->table($type) . " SET {$sets}, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
            $statement->execute([...array_values($payload), $id]);
            return $id;
        }

        $columns = implode(', ', array_keys($payload));
        $placeholders = implode(', ', array_fill(0, count($payload), '?'));
        $statement = $this->pdo->prepare('INSERT INTO ' . $this->table($type) . " ({$columns}) VALUES ({$placeholders})");
        $statement->execute(array_values($payload));

        return (int) $this->pdo->lastInsertId();
    }

    private function table(string $type): string
    {
        return match ($type) {
            'articles' => 'knowledge_articles',
            'categories' => 'knowledge_categories',
            'authors' => 'knowledge_authors',
            default => throw new \InvalidArgumentException('Unknown knowledge type.'),
        };
    }

    private function nullableInt(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }

    private function tags(string $value): array
    {
        $tags = array_map('trim', explode(',', $value));
        return array_values(array_filter($tags, static fn (string $tag): bool => $tag !== ''));
    }
}
