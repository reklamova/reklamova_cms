<?php

declare(strict_types=1);

namespace Reklamova\Cms\Pages;

use PDO;

final class PageRepository
{
    /** @var array<string, bool> */
    private array $columnCache = [];

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        if (!$this->hasColumn('sort_order')) {
            return $this->pdo
                ->query('SELECT * FROM cms_pages ORDER BY updated_at DESC, id DESC')
                ->fetchAll(PDO::FETCH_ASSOC);
        }

        return $this->pdo
            ->query('SELECT * FROM cms_pages ORDER BY sort_order ASC, updated_at DESC, id DESC')
            ->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM cms_pages WHERE id = ? LIMIT 1');
        $statement->execute([$id]);
        $page = $statement->fetch(PDO::FETCH_ASSOC);

        return $page ?: null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findPublishedBySlug(string $slug): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM cms_pages WHERE slug = ? AND status = "published" LIMIT 1');
        $statement->execute([$slug]);
        $page = $statement->fetch(PDO::FETCH_ASSOC);

        return $page ?: null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function navigationPages(): array
    {
        if (!$this->hasColumn('show_in_menu')) {
            return [];
        }

        return $this->pdo
            ->query('SELECT title, slug, menu_label, sort_order FROM cms_pages WHERE status = "published" AND show_in_menu = 1 ORDER BY sort_order ASC, title ASC')
            ->fetchAll(PDO::FETCH_ASSOC);
    }

    public function save(array $data, ?int $id, ?int $userId = null): int
    {
        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            throw new \InvalidArgumentException('Tytuł strony jest wymagany.');
        }

        $slug = $this->normalizeSlug((string) ($data['slug'] ?? ''), $title);
        $status = in_array((string) ($data['status'] ?? 'draft'), ['draft', 'published', 'archived'], true)
            ? (string) $data['status']
            : 'draft';
        $publishedAt = trim((string) ($data['published_at'] ?? ''));
        if ($publishedAt === '' && $status === 'published') {
            $publishedAt = date('Y-m-d H:i:s');
        }

        $payload = [
            'title' => $title,
            'slug' => $slug,
            'excerpt' => trim((string) ($data['excerpt'] ?? '')),
            'content' => (string) ($data['content'] ?? ''),
            'status' => $status,
            'template' => $this->template((string) ($data['template'] ?? 'default')),
            'meta_title' => trim((string) ($data['meta_title'] ?? '')),
            'meta_description' => trim((string) ($data['meta_description'] ?? '')),
            'canonical_url' => trim((string) ($data['canonical_url'] ?? '')),
            'robots' => $this->robots((string) ($data['robots'] ?? 'index,follow')),
            'featured_image' => trim((string) ($data['featured_image'] ?? '')),
            'parent_id' => $this->nullableInt($data['parent_id'] ?? null),
            'sort_order' => (int) ($data['sort_order'] ?? 100),
            'show_in_menu' => !empty($data['show_in_menu']) ? 1 : 0,
            'menu_label' => trim((string) ($data['menu_label'] ?? '')),
            'published_at' => $publishedAt !== '' ? $publishedAt : null,
            'blocks_json' => $this->blocksJson($data),
            'settings_json' => $this->settingsJson($data),
        ];

        $payload = $this->withOptionalPageStudioPayload($payload, $data);
        $columns = array_keys($payload);

        if ($id !== null) {
            $this->createRevision($id, $userId);
            $assignments = array_map(static fn (string $column): string => $column . ' = :' . $column, $columns);
            $sql = 'UPDATE cms_pages SET ' . implode(', ', $assignments) . ', updated_at = CURRENT_TIMESTAMP WHERE id = :id';
            $statement = $this->pdo->prepare($sql);
            $payload['id'] = $id;
            $statement->execute($payload);

            return $id;
        }

        $placeholders = array_map(static fn (string $column): string => ':' . $column, $columns);
        $statement = $this->pdo->prepare(
            'INSERT INTO cms_pages (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')'
        );
        $statement->execute($payload);

        return (int) $this->pdo->lastInsertId();
    }

    public function delete(int $id): void
    {
        $statement = $this->pdo->prepare('DELETE FROM cms_pages WHERE id = ? AND slug <> "home"');
        $statement->execute([$id]);
    }

    public function duplicate(int $id, ?int $userId = null): ?int
    {
        $page = $this->find($id);
        if (!$page) {
            return null;
        }

        $copy = $page;
        unset($copy['id'], $copy['created_at'], $copy['updated_at']);
        $copy['title'] = (string) $page['title'] . ' - kopia';
        $copy['slug'] = (string) $page['slug'] . '-kopia-' . date('His');
        $copy['status'] = 'draft';
        $copy['show_in_menu'] = 0;

        return $this->save($copy, null, $userId);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function revisions(int $pageId, int $limit = 8): array
    {
        if (!$this->tableExists('cms_page_revisions')) {
            return [];
        }

        $statement = $this->pdo->prepare('SELECT * FROM cms_page_revisions WHERE page_id = ? ORDER BY created_at DESC, id DESC LIMIT ' . max(1, $limit));
        $statement->execute([$pageId]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function restoreRevision(int $revisionId, ?int $userId = null): ?int
    {
        $statement = $this->pdo->prepare('SELECT * FROM cms_page_revisions WHERE id = ? LIMIT 1');
        $statement->execute([$revisionId]);
        $revision = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$revision) {
            return null;
        }

        $meta = json_decode((string) ($revision['meta_json'] ?? '{}'), true);
        if (!is_array($meta)) {
            $meta = [];
        }

        $data = array_merge($meta, [
            'title' => (string) $revision['title'],
            'slug' => (string) $revision['slug'],
            'content' => (string) $revision['content'],
            'blocks_json' => (string) ($revision['blocks_json'] ?? ''),
        ]);
        $settings = json_decode((string) ($meta['settings_json'] ?? '{}'), true);
        if (is_array($settings)) {
            foreach ($settings as $key => $value) {
                $data[$key] = is_bool($value) ? ($value ? '1' : '') : (string) $value;
            }
        }

        return $this->save($data, (int) $revision['page_id'], $userId);
    }

    private function createRevision(int $pageId, ?int $userId): void
    {
        if (!$this->tableExists('cms_page_revisions')) {
            return;
        }

        $page = $this->find($pageId);
        if (!$page) {
            return;
        }

        $statement = $this->pdo->prepare('SELECT COALESCE(MAX(version_number), 0) + 1 FROM cms_page_revisions WHERE page_id = ?');
        $statement->execute([$pageId]);
        $version = (int) $statement->fetchColumn();
        $meta = [
            'excerpt' => $page['excerpt'] ?? '',
            'status' => $page['status'] ?? 'draft',
            'template' => $page['template'] ?? 'default',
            'meta_title' => $page['meta_title'] ?? '',
            'meta_description' => $page['meta_description'] ?? '',
            'canonical_url' => $page['canonical_url'] ?? '',
            'robots' => $page['robots'] ?? 'index,follow',
            'featured_image' => $page['featured_image'] ?? '',
            'og_image' => $page['og_image'] ?? '',
            'parent_id' => $page['parent_id'] ?? null,
            'sort_order' => $page['sort_order'] ?? 100,
            'show_in_menu' => $page['show_in_menu'] ?? 0,
            'menu_label' => $page['menu_label'] ?? '',
            'published_at' => $page['published_at'] ?? null,
            'settings_json' => $page['settings_json'] ?? null,
            'schema_json' => $page['schema_json'] ?? null,
            'form_config_json' => $page['form_config_json'] ?? null,
            'cta_config_json' => $page['cta_config_json'] ?? null,
            'routing_priority' => $page['routing_priority'] ?? 100,
            'source_html' => $page['source_html'] ?? '',
        ];

        $insert = $this->pdo->prepare(
            'INSERT INTO cms_page_revisions (page_id, version_number, title, slug, content, blocks_json, meta_json, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $insert->execute([
            $pageId,
            $version,
            (string) $page['title'],
            (string) $page['slug'],
            (string) ($page['content'] ?? ''),
            $page['blocks_json'] ?? null,
            json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $userId,
        ]);
    }

    private function withOptionalPageStudioPayload(array $payload, array $data): array
    {
        $optional = [
            'og_image' => trim((string) ($data['og_image'] ?? '')),
            'schema_json' => $this->schemaJson($data),
            'form_config_json' => $this->formConfigJson($data),
            'cta_config_json' => $this->ctaConfigJson($data),
            'routing_priority' => (int) ($data['routing_priority'] ?? 100),
            'source_html' => (string) ($data['source_html'] ?? ''),
        ];

        foreach ($optional as $column => $value) {
            if ($this->hasColumn($column)) {
                $payload[$column] = $value;
            }
        }

        return $payload;
    }

    private function settingsJson(array $data): string
    {
        $existing = [];
        if (!isset($data['hide_title']) && isset($data['settings_json'])) {
            $decoded = json_decode((string) $data['settings_json'], true);
            $existing = is_array($decoded) ? $decoded : [];
        }

        return json_encode([
            'hide_title' => array_key_exists('hide_title', $data) ? !empty($data['hide_title']) : !empty($existing['hide_title']),
            'layout_width' => in_array((string) ($data['layout_width'] ?? ($existing['layout_width'] ?? 'default')), ['default', 'wide', 'narrow'], true) ? (string) ($data['layout_width'] ?? ($existing['layout_width'] ?? 'default')) : 'default',
            'schema_faq_enabled' => array_key_exists('schema_faq_enabled', $data) ? !empty($data['schema_faq_enabled']) : !empty($existing['schema_faq_enabled']),
            'schema_breadcrumb_enabled' => array_key_exists('schema_breadcrumb_enabled', $data) ? !empty($data['schema_breadcrumb_enabled']) : !empty($existing['schema_breadcrumb_enabled']),
            'schema_local_business_enabled' => array_key_exists('schema_local_business_enabled', $data) ? !empty($data['schema_local_business_enabled']) : !empty($existing['schema_local_business_enabled']),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function schemaJson(array $data): ?string
    {
        if (!isset($data['schema_business_name'], $data['schema_custom_jsonld']) && isset($data['schema_json'])) {
            return (string) $data['schema_json'];
        }

        $schema = [
            'local_business' => [
                'name' => trim((string) ($data['schema_business_name'] ?? '')),
                'address' => trim((string) ($data['schema_business_address'] ?? '')),
                'phone' => trim((string) ($data['schema_business_phone'] ?? '')),
                'email' => trim((string) ($data['schema_business_email'] ?? '')),
            ],
            'custom_jsonld' => trim((string) ($data['schema_custom_jsonld'] ?? '')),
        ];

        return json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function formConfigJson(array $data): ?string
    {
        if (!isset($data['page_form_enabled'], $data['page_form_type']) && isset($data['form_config_json'])) {
            return (string) $data['form_config_json'];
        }

        $config = [
            'enabled' => !empty($data['page_form_enabled']),
            'type' => in_array((string) ($data['page_form_type'] ?? 'contact'), ['contact', 'offer', 'newsletter', 'order'], true) ? (string) $data['page_form_type'] : 'contact',
            'title' => trim((string) ($data['page_form_title'] ?? '')),
            'target_email' => trim((string) ($data['page_form_target_email'] ?? '')),
            'marketing_consent' => !empty($data['page_form_marketing_consent']),
        ];

        return json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function ctaConfigJson(array $data): ?string
    {
        if (!isset($data['page_cta_enabled'], $data['page_cta_title']) && isset($data['cta_config_json'])) {
            return (string) $data['cta_config_json'];
        }

        $config = [
            'enabled' => !empty($data['page_cta_enabled']),
            'title' => trim((string) ($data['page_cta_title'] ?? '')),
            'text' => trim((string) ($data['page_cta_text'] ?? '')),
            'button_label' => trim((string) ($data['page_cta_button_label'] ?? '')),
            'button_url' => trim((string) ($data['page_cta_button_url'] ?? '')),
            'variant' => in_array((string) ($data['page_cta_variant'] ?? 'standard'), ['standard', 'soft', 'dark'], true) ? (string) $data['page_cta_variant'] : 'standard',
        ];

        return json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function blocksJson(array $data): ?string
    {
        if (isset($data['block_type']) && is_array($data['block_type'])) {
            $blocks = [];
            foreach ($data['block_type'] as $index => $type) {
                $type = $this->blockType((string) $type);
                if ($type === '') {
                    continue;
                }

                $block = [
                    'type' => $type,
                    'title' => trim((string) ($data['block_title'][$index] ?? '')),
                    'text' => trim((string) ($data['block_text'][$index] ?? '')),
                    'media_url' => trim((string) ($data['block_media_url'][$index] ?? '')),
                    'button_label' => trim((string) ($data['block_button_label'][$index] ?? '')),
                    'button_url' => trim((string) ($data['block_button_url'][$index] ?? '')),
                    'html' => (string) ($data['block_html'][$index] ?? ''),
                    'items' => $this->parseItems((string) ($data['block_items'][$index] ?? ''), $type),
                    'gallery' => $this->galleryItems($data, $index),
                    'map_address' => trim((string) ($data['block_map_address'][$index] ?? '')),
                    'map_embed_url' => trim((string) ($data['block_map_embed_url'][$index] ?? '')),
                    'form_type' => in_array((string) ($data['block_form_type'][$index] ?? 'contact'), ['contact', 'offer', 'newsletter', 'order'], true) ? (string) $data['block_form_type'][$index] : 'contact',
                    'cta_variant' => in_array((string) ($data['block_cta_variant'][$index] ?? 'standard'), ['standard', 'soft', 'dark'], true) ? (string) $data['block_cta_variant'][$index] : 'standard',
                    'schema_enabled' => !empty($data['block_schema_enabled'][$index]),
                ];
                if ($this->blockIsEmpty($block)) {
                    continue;
                }
                $blocks[] = $block;
            }

            if (!empty($data['import_html_as_block']) && trim((string) ($data['source_html'] ?? '')) !== '') {
                $blocks[] = [
                    'type' => 'html',
                    'title' => 'Import HTML',
                    'text' => '',
                    'media_url' => '',
                    'button_label' => '',
                    'button_url' => '',
                    'html' => (string) $data['source_html'],
                    'items' => [],
                    'gallery' => [],
                ];
            }

            return $blocks ? json_encode($blocks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
        }

        $raw = trim((string) ($data['blocks_json'] ?? ''));
        if ($raw === '') {
            return null;
        }

        json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \InvalidArgumentException('Bloki strony zawieraja niepoprawny JSON.');
        }

        return $raw;
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function parseItems(string $value, string $type): array
    {
        $items = [];
        foreach (preg_split('/\R/', trim($value)) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $parts = array_map('trim', explode('|', $line));
            if ($type === 'faq') {
                $items[] = [
                    'question' => $parts[0] ?? '',
                    'answer' => $parts[1] ?? '',
                ];
                continue;
            }

            $items[] = [
                'title' => $parts[0] ?? '',
                'text' => $parts[1] ?? '',
                'url' => $parts[2] ?? '',
            ];
        }

        return $items;
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function galleryItems(array $data, int $index): array
    {
        $items = [];
        $key = 'block_gallery_media_' . $index;
        foreach (($data[$key] ?? []) as $url) {
            $url = trim((string) $url);
            if ($url === '') {
                continue;
            }
            $items[] = ['url' => $url, 'alt' => ''];
        }

        return $items;
    }

    private function blockType(string $type): string
    {
        return in_array($type, ['hero', 'text', 'image_text', 'cards', 'faq', 'cta', 'gallery', 'map', 'form', 'html'], true) ? $type : '';
    }

    private function blockIsEmpty(array $block): bool
    {
        return trim((string) $block['title']) === ''
            && trim((string) $block['text']) === ''
            && trim((string) $block['media_url']) === ''
            && trim((string) $block['button_label']) === ''
            && trim((string) $block['button_url']) === ''
            && trim((string) $block['html']) === ''
            && trim((string) $block['map_address']) === ''
            && trim((string) $block['map_embed_url']) === ''
            && ($block['items'] ?? []) === []
            && ($block['gallery'] ?? []) === [];
    }

    private function normalizeSlug(string $slug, string $title): string
    {
        $slug = trim($slug) !== '' ? trim($slug) : $title;
        $slug = strtr($slug, [
            'ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ż' => 'z', 'ź' => 'z',
            'Ą' => 'a', 'Ć' => 'c', 'Ę' => 'e', 'Ł' => 'l', 'Ń' => 'n', 'Ó' => 'o', 'Ś' => 's', 'Ż' => 'z', 'Ź' => 'z',
        ]);
        $slug = strtolower($slug);
        $slug = preg_replace('/[^a-z0-9]+/i', '-', $slug) ?: 'page';

        return trim($slug, '-') ?: 'page';
    }

    private function template(string $template): string
    {
        return in_array($template, ['default', 'landing', 'legal', 'wide'], true) ? $template : 'default';
    }

    private function robots(string $robots): string
    {
        return in_array($robots, ['index,follow', 'noindex,follow', 'noindex,nofollow'], true) ? $robots : 'index,follow';
    }

    private function nullableInt(mixed $value): ?int
    {
        $value = trim((string) $value);

        return $value === '' || $value === '0' ? null : (int) $value;
    }

    private function hasColumn(string $column): bool
    {
        if (array_key_exists($column, $this->columnCache)) {
            return $this->columnCache[$column];
        }

        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = "cms_pages" AND COLUMN_NAME = ?'
        );
        $statement->execute([$column]);

        return $this->columnCache[$column] = ((int) $statement->fetchColumn() > 0);
    }

    private function tableExists(string $table): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        $statement->execute([$table]);

        return (int) $statement->fetchColumn() > 0;
    }
}
