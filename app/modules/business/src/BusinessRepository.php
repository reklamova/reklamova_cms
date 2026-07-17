<?php

declare(strict_types=1);

namespace Reklamova\Cms\Modules\Business;

use PDO;

final class BusinessRepository
{
    private const TABLES = [
        'services' => 'business_services',
        'areas' => 'business_service_areas',
        'cases' => 'business_case_studies',
        'testimonials' => 'business_testimonials',
        'faqs' => 'business_faqs',
        'team' => 'business_team_members',
        'ctas' => 'business_ctas',
    ];

    private const FIELDS = [
        'services' => ['title', 'slug', 'summary', 'description', 'icon', 'featured_image', 'meta_title', 'meta_description', 'status', 'sort_order'],
        'areas' => ['name', 'slug', 'region', 'summary', 'description', 'meta_title', 'meta_description', 'status', 'sort_order'],
        'cases' => ['title', 'slug', 'client_name', 'industry', 'summary', 'challenge', 'solution', 'result', 'cover_image', 'meta_title', 'meta_description', 'status', 'sort_order'],
        'testimonials' => ['author', 'company', 'role', 'quote', 'rating', 'source_url', 'is_featured', 'status', 'sort_order'],
        'faqs' => ['question', 'answer', 'scope_type', 'scope_slug', 'status', 'sort_order'],
        'team' => ['name', 'role', 'bio', 'photo', 'email', 'phone', 'status', 'sort_order'],
        'ctas' => ['name', 'placement', 'headline', 'text', 'button_label', 'button_url', 'status', 'sort_order'],
    ];

    public function __construct(private PDO $pdo)
    {
    }

    public function all(string $type, bool $publishedOnly = false): array
    {
        $table = $this->table($type);
        $where = $publishedOnly ? 'WHERE status = "published"' : '';

        return $this->pdo->query("SELECT * FROM {$table} {$where} ORDER BY sort_order ASC, id DESC")->fetchAll();
    }

    public function find(string $type, int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM ' . $this->table($type) . ' WHERE id = ? LIMIT 1');
        $statement->execute([$id]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function findBySlug(string $type, string $slug): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM ' . $this->table($type) . ' WHERE slug = ? AND status = "published" LIMIT 1');
        $statement->execute([$slug]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function save(string $type, array $data, ?int $id = null): int
    {
        $fields = self::FIELDS[$type] ?? throw new \InvalidArgumentException('Unknown business type.');
        $payload = [];
        foreach ($fields as $field) {
            $payload[$field] = $this->normalize($field, $data[$field] ?? null);
        }

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

    public function delete(string $type, int $id): void
    {
        $statement = $this->pdo->prepare('DELETE FROM ' . $this->table($type) . ' WHERE id = ?');
        $statement->execute([$id]);
    }

    public function publishedFaqs(string $scopeType = 'global', ?string $scopeSlug = null): array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM business_faqs
             WHERE status = "published"
             AND (scope_type = "global" OR (scope_type = ? AND scope_slug = ?))
             ORDER BY sort_order ASC, id DESC'
        );
        $statement->execute([$scopeType, $scopeSlug]);

        return $statement->fetchAll();
    }

    public function publishedCta(string $placement = 'global'): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM business_ctas
             WHERE status = "published" AND placement IN ("global", ?)
             ORDER BY placement = ? DESC, sort_order ASC, id DESC
             LIMIT 1'
        );
        $statement->execute([$placement, $placement]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function slugify(string $value): string
    {
        $map = ['ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ż' => 'z', 'ź' => 'z'];
        $value = strtr(mb_strtolower(trim($value), 'UTF-8'), $map);
        $value = preg_replace('/[^a-z0-9]+/i', '-', $value) ?: 'item';

        return trim($value, '-') ?: 'item';
    }

    private function table(string $type): string
    {
        return self::TABLES[$type] ?? throw new \InvalidArgumentException('Unknown business type.');
    }

    private function normalize(string $field, mixed $value): mixed
    {
        if (in_array($field, ['sort_order', 'rating'], true)) {
            return $value === null || $value === '' ? null : max(0, (int) $value);
        }

        if ($field === 'is_featured') {
            return !empty($value) ? 1 : 0;
        }

        if ($field === 'status') {
            return in_array((string) $value, ['draft', 'published'], true) ? (string) $value : 'draft';
        }

        return is_string($value) ? trim($value) : $value;
    }
}
