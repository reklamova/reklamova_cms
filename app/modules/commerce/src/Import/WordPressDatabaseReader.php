<?php

declare(strict_types=1);

namespace Reklamova\Cms\Commerce\Import;

use PDO;

final class WordPressDatabaseReader
{
    private string $prefix;

    public function __construct(
        private PDO $pdo,
        string $tablePrefix = 'wp_',
        private DecimalMoneyParser $money = new DecimalMoneyParser(),
    ) {
        if (preg_match('/^[A-Za-z0-9_]+$/', $tablePrefix) !== 1) {
            throw new \InvalidArgumentException('Invalid WordPress table prefix.');
        }
        $this->prefix = $tablePrefix;
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        $categories = $this->categories();
        $attributes = $this->attributes();
        $taxRates = $this->taxRates();
        [$products, $variants] = $this->productsAndVariants();
        $images = array_column($products, 'featured_image_relative');
        foreach ($products as $product) {
            array_push($images, ...$product['gallery_relative']);
        }
        array_push($images, ...array_column($variants, 'featured_image_relative'));

        return [
            'source_system' => 'woocommerce',
            'source_version' => $this->option('woocommerce_version'),
            'site_url' => $this->option('siteurl'),
            'currency' => $this->option('woocommerce_currency') ?: 'PLN',
            'captured_at' => gmdate(DATE_ATOM),
            'categories' => $categories,
            'attributes' => $attributes,
            'tax_rates' => $taxRates,
            'products' => $products,
            'variants' => $variants,
            'counts' => [
                'categories' => count($categories),
                'attributes' => count($attributes),
                'tax_rates' => count($taxRates),
                'products' => count($products),
                'published_products' => count(array_filter($products, static fn (array $row): bool => $row['status'] === 'publish')),
                'variants' => count($variants),
                'published_variants' => count(array_filter($variants, static fn (array $row): bool => $row['status'] === 'publish')),
                'images' => count(array_unique(array_filter($images))),
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function taxRates(): array
    {
        $table = $this->prefix . 'woocommerce_tax_rates';
        if (!$this->tableExists($table)) {
            return [];
        }

        $rows = $this->pdo->query(
            "SELECT tax_rate_id, tax_rate_country, tax_rate_state, tax_rate, tax_rate_name,
                    tax_rate_priority, tax_rate_compound, tax_rate_shipping, tax_rate_class
             FROM {$table}
             ORDER BY tax_rate_priority, tax_rate_order, tax_rate_id"
        )->fetchAll(PDO::FETCH_ASSOC);

        return array_map(fn (array $row): array => [
            'external_id' => (string) $row['tax_rate_id'],
            'country_code' => (string) $row['tax_rate_country'],
            'state' => (string) $row['tax_rate_state'],
            'rate_bps' => $this->money->parse($row['tax_rate'], 2) ?? 0,
            'name' => (string) $row['tax_rate_name'],
            'priority' => (int) $row['tax_rate_priority'],
            'compound' => (bool) $row['tax_rate_compound'],
            'shipping' => (bool) $row['tax_rate_shipping'],
            'class' => (string) ($row['tax_rate_class'] ?: 'standard'),
        ], $rows);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function categories(): array
    {
        $sql = "SELECT t.term_id AS external_id, t.name, t.slug, tt.parent AS parent_external_id,
                       tt.description, tt.count
                FROM {$this->prefix}terms t
                INNER JOIN {$this->prefix}term_taxonomy tt ON tt.term_id = t.term_id
                WHERE tt.taxonomy = 'product_cat'
                ORDER BY tt.parent, t.term_id";

        return array_map(static fn (array $row): array => [
            'external_id' => (string) $row['external_id'],
            'parent_external_id' => (int) $row['parent_external_id'] > 0 ? (string) $row['parent_external_id'] : null,
            'name' => (string) $row['name'],
            'slug' => (string) $row['slug'],
            'description' => (string) ($row['description'] ?? ''),
            'count' => (int) $row['count'],
        ], $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function attributes(): array
    {
        $table = $this->prefix . 'woocommerce_attribute_taxonomies';
        if (!$this->tableExists($table)) {
            return [];
        }

        $attributes = [];
        $rows = $this->pdo->query(
            "SELECT attribute_id, attribute_name, attribute_label, attribute_type, attribute_orderby
             FROM {$table} ORDER BY attribute_id"
        )->fetchAll(PDO::FETCH_ASSOC);
        $termStatement = $this->pdo->prepare(
            "SELECT t.term_id AS external_id, t.name, t.slug
             FROM {$this->prefix}terms t
             INNER JOIN {$this->prefix}term_taxonomy tt ON tt.term_id = t.term_id
             WHERE tt.taxonomy = ?
             ORDER BY t.term_id"
        );
        foreach ($rows as $row) {
            $taxonomy = 'pa_' . (string) $row['attribute_name'];
            $termStatement->execute([$taxonomy]);
            $terms = array_map(static fn (array $term): array => [
                'external_id' => (string) $term['external_id'],
                'name' => (string) $term['name'],
                'slug' => (string) $term['slug'],
            ], $termStatement->fetchAll(PDO::FETCH_ASSOC));
            $attributes[] = [
                'external_id' => (string) $row['attribute_id'],
                'code' => (string) $row['attribute_name'],
                'taxonomy' => $taxonomy,
                'name' => (string) ($row['attribute_label'] ?: $row['attribute_name']),
                'input_type' => (string) ($row['attribute_type'] ?: 'select'),
                'orderby' => (string) ($row['attribute_orderby'] ?: 'menu_order'),
                'terms' => $terms,
            ];
        }

        return $attributes;
    }

    /**
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>}
     */
    public function productsAndVariants(): array
    {
        $posts = $this->pdo->query(
            "SELECT ID, post_parent, post_type, post_status, post_name, post_title, post_excerpt, post_content, menu_order
             FROM {$this->prefix}posts
             WHERE post_type IN ('product', 'product_variation')
               AND post_status NOT IN ('trash', 'auto-draft')
             ORDER BY post_type, ID"
        )->fetchAll(PDO::FETCH_ASSOC);
        if ($posts === []) {
            return [[], []];
        }

        $meta = $this->postMeta();
        $terms = $this->objectTerms();
        $attachmentIds = [];
        foreach ($posts as $post) {
            $id = (int) $post['ID'];
            $rowMeta = $meta[$id] ?? [];
            if (!empty($rowMeta['_thumbnail_id'])) {
                $attachmentIds[] = (int) $rowMeta['_thumbnail_id'];
            }
            foreach (explode(',', (string) ($rowMeta['_product_image_gallery'] ?? '')) as $attachmentId) {
                if ((int) $attachmentId > 0) {
                    $attachmentIds[] = (int) $attachmentId;
                }
            }
        }
        $attachments = $this->attachments(array_values(array_unique($attachmentIds)));

        $products = [];
        $variants = [];
        foreach ($posts as $post) {
            $id = (int) $post['ID'];
            $rowMeta = $meta[$id] ?? [];
            $featured = $attachments[(int) ($rowMeta['_thumbnail_id'] ?? 0)] ?? null;
            $base = [
                'external_id' => (string) $id,
                'status' => (string) $post['post_status'],
                'slug' => (string) $post['post_name'],
                'name' => (string) $post['post_title'],
                'sku' => $this->nullableString($rowMeta['_sku'] ?? null),
                'regular_price_minor' => $this->money->parse($rowMeta['_regular_price'] ?? null),
                'sale_price_minor' => $this->money->parse($rowMeta['_sale_price'] ?? null),
                'current_price_minor' => $this->money->parse($rowMeta['_price'] ?? null),
                'stock_status' => (string) ($rowMeta['_stock_status'] ?? 'instock'),
                'track_stock' => ($rowMeta['_manage_stock'] ?? 'no') === 'yes',
                'stock_quantity' => $this->nullableInt($rowMeta['_stock'] ?? null),
                'weight_grams' => $this->kilogramsToGrams($rowMeta['_weight'] ?? null),
                'width_mm' => $this->centimetersToMillimeters($rowMeta['_width'] ?? null),
                'height_mm' => $this->centimetersToMillimeters($rowMeta['_height'] ?? null),
                'length_mm' => $this->centimetersToMillimeters($rowMeta['_length'] ?? null),
                'featured_image_relative' => $featured['relative_path'] ?? null,
                'featured_image_url' => $featured['url'] ?? null,
                'sort_order' => (int) $post['menu_order'],
            ];

            if ($post['post_type'] === 'product_variation') {
                $variantAttributes = [];
                foreach ($rowMeta as $key => $value) {
                    if (str_starts_with($key, 'attribute_')) {
                        $variantAttributes[substr($key, strlen('attribute_'))] = (string) $value;
                    }
                }
                $variants[] = $base + [
                    'product_external_id' => (string) $post['post_parent'],
                    'attributes' => $variantAttributes,
                ];
                continue;
            }

            $gallery = [];
            foreach (explode(',', (string) ($rowMeta['_product_image_gallery'] ?? '')) as $attachmentId) {
                $attachment = $attachments[(int) $attachmentId] ?? null;
                if ($attachment) {
                    $gallery[] = $attachment;
                }
            }
            $objectTerms = $terms[$id] ?? [];
            $productType = 'simple';
            $categoryIds = [];
            $globalAttributes = [];
            foreach ($objectTerms as $term) {
                if ($term['taxonomy'] === 'product_type') {
                    $productType = (string) $term['slug'];
                } elseif ($term['taxonomy'] === 'product_cat') {
                    $categoryIds[] = (string) $term['external_id'];
                } elseif (str_starts_with((string) $term['taxonomy'], 'pa_')) {
                    $globalAttributes[(string) $term['taxonomy']][] = (string) $term['slug'];
                }
            }
            $products[] = $base + [
                'type' => in_array($productType, ['simple', 'variable'], true) ? $productType : 'simple',
                'summary' => (string) $post['post_excerpt'],
                'description' => (string) $post['post_content'],
                'category_external_ids' => array_values(array_unique($categoryIds)),
                'attributes' => $this->productAttributes($rowMeta['_product_attributes'] ?? null, $globalAttributes),
                'gallery_relative' => array_values(array_filter(array_column($gallery, 'relative_path'))),
                'gallery' => $gallery,
                'tax_class' => (string) ($rowMeta['_tax_class'] ?? ''),
            ];
        }

        return [$products, $variants];
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function objectTerms(): array
    {
        $rows = $this->pdo->query(
            "SELECT tr.object_id, tt.taxonomy, t.term_id AS external_id, t.name, t.slug
             FROM {$this->prefix}term_relationships tr
             INNER JOIN {$this->prefix}term_taxonomy tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
             INNER JOIN {$this->prefix}terms t ON t.term_id = tt.term_id
             INNER JOIN {$this->prefix}posts p ON p.ID = tr.object_id
             WHERE p.post_type = 'product'
               AND (tt.taxonomy IN ('product_cat', 'product_type') OR tt.taxonomy LIKE 'pa\\_%')"
        )->fetchAll(PDO::FETCH_ASSOC);
        $grouped = [];
        foreach ($rows as $row) {
            $grouped[(int) $row['object_id']][] = [
                'taxonomy' => (string) $row['taxonomy'],
                'external_id' => (string) $row['external_id'],
                'name' => (string) $row['name'],
                'slug' => (string) $row['slug'],
            ];
        }

        return $grouped;
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function postMeta(): array
    {
        $keys = [
            '_sku', '_regular_price', '_sale_price', '_price', '_stock_status', '_manage_stock', '_stock',
            '_weight', '_width', '_height', '_length', '_thumbnail_id', '_product_image_gallery',
            '_product_attributes', '_tax_class',
        ];
        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        $statement = $this->pdo->prepare(
            "SELECT pm.post_id, pm.meta_key, pm.meta_value
             FROM {$this->prefix}postmeta pm
             INNER JOIN {$this->prefix}posts p ON p.ID = pm.post_id
             WHERE (p.post_type IN ('product', 'product_variation')
               AND pm.meta_key IN ({$placeholders}))
                OR (p.post_type = 'product_variation' AND pm.meta_key LIKE 'attribute\\_%')"
        );
        $statement->execute($keys);
        $grouped = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $grouped[(int) $row['post_id']][(string) $row['meta_key']] = (string) $row['meta_value'];
        }

        return $grouped;
    }

    /**
     * @param array<int, int> $ids
     * @return array<int, array{relative_path: string, url: string, alt: string}>
     */
    private function attachments(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $ids = array_values(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0));
        $list = implode(',', $ids);
        $home = rtrim((string) ($this->option('home') ?: $this->option('siteurl')), '/');
        $uploadsUrl = rtrim((string) $this->option('upload_url_path'), '/');
        if ($uploadsUrl === '') {
            $uploadsUrl = $home . '/wp-content/uploads';
        }
        $rows = $this->pdo->query(
            "SELECT p.ID, attached.meta_value AS relative_path, alt.meta_value AS alt
             FROM {$this->prefix}posts p
             LEFT JOIN {$this->prefix}postmeta attached ON attached.post_id = p.ID AND attached.meta_key = '_wp_attached_file'
             LEFT JOIN {$this->prefix}postmeta alt ON alt.post_id = p.ID AND alt.meta_key = '_wp_attachment_image_alt'
             WHERE p.ID IN ({$list}) AND p.post_type = 'attachment'"
        )->fetchAll(PDO::FETCH_ASSOC);
        $result = [];
        foreach ($rows as $row) {
            $relative = ltrim(str_replace('\\', '/', (string) $row['relative_path']), '/');
            if ($relative === '') {
                continue;
            }
            $result[(int) $row['ID']] = [
                'relative_path' => $relative,
                'url' => $uploadsUrl . '/' . $relative,
                'alt' => (string) ($row['alt'] ?? ''),
            ];
        }

        return $result;
    }

    /**
     * @param array<string, array<int, string>> $globalAttributes
     * @return array<string, mixed>
     */
    private function productAttributes(mixed $serialized, array $globalAttributes): array
    {
        $attributes = [];
        if (is_string($serialized) && $serialized !== '') {
            $decoded = @unserialize($serialized, ['allowed_classes' => false]);
            if (is_array($decoded)) {
                foreach ($decoded as $code => $settings) {
                    if (!is_array($settings)) {
                        continue;
                    }
                    $attributes[(string) $code] = [
                        'name' => (string) ($settings['name'] ?? $code),
                        'values' => str_starts_with((string) $code, 'pa_')
                            ? ($globalAttributes[(string) $code] ?? [])
                            : array_values(array_filter(array_map('trim', explode('|', (string) ($settings['value'] ?? ''))))),
                        'visible' => !empty($settings['is_visible']),
                        'variant' => !empty($settings['is_variation']),
                        'position' => (int) ($settings['position'] ?? 0),
                    ];
                }
            }
        }

        return $attributes;
    }

    private function option(string $key): string
    {
        $statement = $this->pdo->prepare(
            "SELECT option_value FROM {$this->prefix}options WHERE option_name = ? LIMIT 1"
        );
        $statement->execute([$key]);

        return (string) ($statement->fetchColumn() ?: '');
    }

    private function tableExists(string $table): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?'
        );
        $statement->execute([$table]);

        return (int) $statement->fetchColumn() > 0;
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function nullableInt(mixed $value): ?int
    {
        $value = trim((string) $value);

        return $value === '' ? null : (int) $value;
    }

    private function kilogramsToGrams(mixed $value): ?int
    {
        $minor = $this->money->parse($value, 3);

        return $minor === null ? null : max(0, $minor);
    }

    private function centimetersToMillimeters(mixed $value): ?int
    {
        $minor = $this->money->parse($value, 1);

        return $minor === null ? null : max(0, $minor);
    }
}
