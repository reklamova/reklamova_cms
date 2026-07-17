<?php

declare(strict_types=1);

namespace Reklamova\Cms\Modules\Landing;

use PDO;

final class LandingRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function all(bool $publishedOnly = false): array
    {
        $where = $publishedOnly ? 'WHERE status = "published"' : '';
        return $this->pdo->query("SELECT * FROM landing_pages {$where} ORDER BY COALESCE(published_at, created_at) DESC, id DESC")->fetchAll();
    }

    public function find(int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM landing_pages WHERE id = ? LIMIT 1');
        $statement->execute([$id]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    public function findBySlug(string $slug): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM landing_pages WHERE slug = ? AND status = "published" LIMIT 1');
        $statement->execute([$slug]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    public function save(array $data, ?int $id = null): int
    {
        $status = in_array((string) ($data['status'] ?? 'draft'), ['draft', 'published'], true) ? (string) $data['status'] : 'draft';
        $publishedAt = trim((string) ($data['published_at'] ?? ''));
        if ($status === 'published' && $publishedAt === '') {
            $publishedAt = date('Y-m-d H:i:s');
        }
        $payload = [
            'name' => trim((string) ($data['name'] ?? '')),
            'slug' => trim((string) ($data['slug'] ?? '')),
            'campaign_source' => trim((string) ($data['campaign_source'] ?? '')),
            'template_variant' => trim((string) ($data['template_variant'] ?? 'lead')) ?: 'lead',
            'hero_title' => trim((string) ($data['hero_title'] ?? '')),
            'hero_text' => trim((string) ($data['hero_text'] ?? '')),
            'hero_image' => trim((string) ($data['hero_image'] ?? '')),
            'benefits_json' => json_encode($this->lines((string) ($data['benefits'] ?? '')), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'sections_json' => json_encode($this->sections((string) ($data['sections'] ?? '')), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'faq_json' => json_encode($this->faq((string) ($data['faq'] ?? '')), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'form_enabled' => !empty($data['form_enabled']) ? 1 : 0,
            'form_title' => trim((string) ($data['form_title'] ?? '')),
            'cta_label' => trim((string) ($data['cta_label'] ?? '')),
            'thank_you_message' => trim((string) ($data['thank_you_message'] ?? '')),
            'meta_title' => trim((string) ($data['meta_title'] ?? '')),
            'meta_description' => trim((string) ($data['meta_description'] ?? '')),
            'status' => $status,
            'published_at' => $publishedAt !== '' ? $publishedAt : null,
        ];
        if ($payload['slug'] === '') {
            $payload['slug'] = $this->slugify($payload['name'] ?: $payload['hero_title']);
        }

        if ($id) {
            $sets = implode(', ', array_map(static fn (string $field): string => "{$field} = ?", array_keys($payload)));
            $statement = $this->pdo->prepare("UPDATE landing_pages SET {$sets}, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
            $statement->execute([...array_values($payload), $id]);
            return $id;
        }

        $columns = implode(', ', array_keys($payload));
        $placeholders = implode(', ', array_fill(0, count($payload), '?'));
        $statement = $this->pdo->prepare("INSERT INTO landing_pages ({$columns}) VALUES ({$placeholders})");
        $statement->execute(array_values($payload));
        return (int) $this->pdo->lastInsertId();
    }

    public function delete(int $id): void
    {
        $statement = $this->pdo->prepare('DELETE FROM landing_pages WHERE id = ?');
        $statement->execute([$id]);
    }

    public function slugify(string $value): string
    {
        $map = ['ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ż' => 'z', 'ź' => 'z'];
        $value = strtr(mb_strtolower(trim($value), 'UTF-8'), $map);
        $value = preg_replace('/[^a-z0-9]+/i', '-', $value) ?: 'landing-page';
        return trim($value, '-') ?: 'landing-page';
    }

    private function lines(string $value): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\R/', $value) ?: [])));
    }

    private function sections(string $value): array
    {
        $sections = [];
        foreach (preg_split('/\R\R+/', trim($value)) ?: [] as $chunk) {
            $lines = $this->lines($chunk);
            if (!$lines) {
                continue;
            }
            $sections[] = ['title' => array_shift($lines), 'text' => implode("\n", $lines)];
        }
        return $sections;
    }

    private function faq(string $value): array
    {
        $items = [];
        foreach (preg_split('/\R\R+/', trim($value)) ?: [] as $chunk) {
            $lines = $this->lines($chunk);
            if (count($lines) >= 2) {
                $items[] = ['question' => $lines[0], 'answer' => implode("\n", array_slice($lines, 1))];
            }
        }
        return $items;
    }
}
