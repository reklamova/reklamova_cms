<?php

declare(strict_types=1);

namespace Reklamova\Cms\Commerce\Import;

use PDO;

final class CommerceImportRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function transaction(callable $callback): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $callback();
            $this->pdo->commit();

            return $result;
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    /**
     * @param array<string, mixed> $store
     */
    public function ensureStore(array $store): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO commerce_stores (code, name, currency, country_code, prices_include_tax)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                name = VALUES(name),
                currency = VALUES(currency),
                country_code = VALUES(country_code),
                prices_include_tax = VALUES(prices_include_tax),
                updated_at = CURRENT_TIMESTAMP'
        );
        $statement->execute([
            $store['code'],
            $store['name'],
            strtoupper((string) $store['currency']),
            strtoupper((string) $store['country_code']),
            !empty($store['prices_include_tax']) ? 1 : 0,
        ]);

        $find = $this->pdo->prepare('SELECT id FROM commerce_stores WHERE code = ? LIMIT 1');
        $find->execute([$store['code']]);

        return (int) $find->fetchColumn();
    }

    public function findStoreId(string $code): ?int
    {
        $statement = $this->pdo->prepare('SELECT id FROM commerce_stores WHERE code = ? LIMIT 1');
        $statement->execute([$code]);
        $id = $statement->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    /**
     * @return array{local_id: int, source_hash: ?string}|null
     */
    public function mapping(int $storeId, string $sourceType, string $externalId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT local_id, source_hash
             FROM commerce_import_mappings
             WHERE store_id = ? AND source_system = "woocommerce" AND source_type = ? AND external_id = ?
             LIMIT 1'
        );
        $statement->execute([$storeId, $sourceType, $externalId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row ? ['local_id' => (int) $row['local_id'], 'source_hash' => $row['source_hash'] ?: null] : null;
    }

    public function saveMapping(
        int $storeId,
        string $sourceType,
        string $externalId,
        string $localType,
        int $localId,
        string $sourceHash,
        ?int $runId,
    ): void {
        $statement = $this->pdo->prepare(
            'INSERT INTO commerce_import_mappings
                (store_id, source_system, source_type, external_id, local_type, local_id, source_hash, last_import_run_id)
             VALUES (?, "woocommerce", ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                local_type = VALUES(local_type),
                local_id = VALUES(local_id),
                source_hash = VALUES(source_hash),
                last_import_run_id = VALUES(last_import_run_id),
                updated_at = CURRENT_TIMESTAMP'
        );
        $statement->execute([$storeId, $sourceType, $externalId, $localType, $localId, $sourceHash, $runId]);
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    public function startRun(int $storeId, string $mode, array $snapshot): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO commerce_import_runs
                (store_id, source_system, mode, status, source_snapshot, counters_json)
             VALUES (?, "woocommerce", ?, "running", ?, ?)'
        );
        $statement->execute([
            $storeId,
            $mode,
            (string) ($snapshot['captured_at'] ?? ''),
            $this->json($snapshot['counts'] ?? []),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param array<string, mixed> $report
     */
    public function finishRun(int $runId, string $status, array $report): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE commerce_import_runs
             SET status = ?, report_json = ?, finished_at = CURRENT_TIMESTAMP
             WHERE id = ?'
        );
        $statement->execute([$status, $this->json($report), $runId]);
    }

    /**
     * @param array<string, mixed> $tax
     */
    public function upsertTaxClass(int $storeId, array $tax): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO commerce_tax_classes (store_id, code, name, rate_bps, applies_to_shipping)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                name = VALUES(name),
                rate_bps = VALUES(rate_bps),
                applies_to_shipping = VALUES(applies_to_shipping),
                updated_at = CURRENT_TIMESTAMP'
        );
        $statement->execute([$storeId, $tax['code'], $tax['name'], $tax['rate_bps'], !empty($tax['shipping']) ? 1 : 0]);
        $find = $this->pdo->prepare('SELECT id FROM commerce_tax_classes WHERE store_id = ? AND code = ? LIMIT 1');
        $find->execute([$storeId, $tax['code']]);

        return (int) $find->fetchColumn();
    }

    /**
     * @param array<string, mixed> $category
     */
    public function saveCategory(int $storeId, array $category, ?int $localId): int
    {
        $values = [
            $storeId,
            $category['parent_id'],
            $category['name'],
            $category['slug'],
            $category['full_path'],
            $category['description'],
            $category['status'],
        ];
        if ($localId) {
            $statement = $this->pdo->prepare(
                'UPDATE commerce_categories
                 SET parent_id = ?, name = ?, slug = ?, full_path = ?, description = ?, status = ?, updated_at = CURRENT_TIMESTAMP
                 WHERE id = ? AND store_id = ?'
            );
            $statement->execute([
                $category['parent_id'], $category['name'], $category['slug'], $category['full_path'],
                $category['description'], $category['status'], $localId, $storeId,
            ]);

            return $localId;
        }
        $statement = $this->pdo->prepare(
            'INSERT INTO commerce_categories
                (store_id, parent_id, name, slug, full_path, description, status)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute($values);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param array<string, mixed> $attribute
     */
    public function saveAttribute(int $storeId, array $attribute, ?int $localId): int
    {
        if ($localId) {
            $statement = $this->pdo->prepare(
                'UPDATE commerce_attributes
                 SET code = ?, name = ?, input_type = ?, is_variant = ?, settings_json = ?, updated_at = CURRENT_TIMESTAMP
                 WHERE id = ? AND store_id = ?'
            );
            $statement->execute([
                $attribute['code'], $attribute['name'], $attribute['input_type'], !empty($attribute['is_variant']) ? 1 : 0,
                $this->json($attribute['settings'] ?? []), $localId, $storeId,
            ]);

            return $localId;
        }
        $statement = $this->pdo->prepare(
            'INSERT INTO commerce_attributes (store_id, code, name, input_type, is_variant, settings_json)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $storeId, $attribute['code'], $attribute['name'], $attribute['input_type'],
            !empty($attribute['is_variant']) ? 1 : 0, $this->json($attribute['settings'] ?? []),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param array<string, mixed> $term
     */
    public function saveAttributeTerm(int $attributeId, array $term, ?int $localId): int
    {
        if ($localId) {
            $statement = $this->pdo->prepare(
                'UPDATE commerce_attribute_terms SET value = ?, slug = ? WHERE id = ? AND attribute_id = ?'
            );
            $statement->execute([$term['name'], $term['slug'], $localId, $attributeId]);

            return $localId;
        }
        $statement = $this->pdo->prepare(
            'INSERT INTO commerce_attribute_terms (attribute_id, value, slug) VALUES (?, ?, ?)'
        );
        $statement->execute([$attributeId, $term['name'], $term['slug']]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param array<string, mixed> $product
     */
    public function saveProduct(int $storeId, array $product, ?int $localId): int
    {
        $fields = [
            'tax_class_id', 'type', 'name', 'slug', 'sku', 'summary', 'description', 'status',
            'base_price_minor', 'sale_price_minor', 'currency', 'track_stock', 'stock_quantity',
            'stock_status', 'weight_grams', 'width_mm', 'height_mm', 'length_mm', 'featured_image',
            'gallery_json', 'content_sections_json', 'sort_order',
        ];
        $values = array_map(static fn (string $field): mixed => $product[$field] ?? null, $fields);
        if ($localId) {
            $sets = implode(', ', array_map(static fn (string $field): string => "{$field} = ?", $fields));
            $statement = $this->pdo->prepare(
                "UPDATE commerce_products SET {$sets}, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND store_id = ?"
            );
            $statement->execute([...$values, $localId, $storeId]);

            return $localId;
        }
        $columns = implode(', ', $fields);
        $placeholders = implode(', ', array_fill(0, count($fields) + 1, '?'));
        $statement = $this->pdo->prepare(
            "INSERT INTO commerce_products (store_id, {$columns}) VALUES ({$placeholders})"
        );
        $statement->execute([$storeId, ...$values]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param array<int, int> $categoryIds
     */
    public function syncProductCategories(int $productId, array $categoryIds): void
    {
        $this->pdo->prepare('DELETE FROM commerce_product_categories WHERE product_id = ?')->execute([$productId]);
        $insert = $this->pdo->prepare(
            'INSERT INTO commerce_product_categories (product_id, category_id, is_primary, sort_order)
             VALUES (?, ?, ?, ?)'
        );
        foreach (array_values(array_unique($categoryIds)) as $index => $categoryId) {
            $insert->execute([$productId, $categoryId, $index === 0 ? 1 : 0, ($index + 1) * 10]);
        }
    }

    /**
     * @param array<int, array{attribute_id: int, visible: bool, variant: bool}> $attributes
     */
    public function syncProductAttributes(int $productId, array $attributes): void
    {
        $this->pdo->prepare('DELETE FROM commerce_product_attributes WHERE product_id = ?')->execute([$productId]);
        $insert = $this->pdo->prepare(
            'INSERT INTO commerce_product_attributes
                (product_id, attribute_id, is_visible, is_variant, sort_order)
             VALUES (?, ?, ?, ?, ?)'
        );
        foreach (array_values($attributes) as $index => $attribute) {
            $insert->execute([
                $productId,
                $attribute['attribute_id'],
                $attribute['visible'] ? 1 : 0,
                $attribute['variant'] ? 1 : 0,
                ($index + 1) * 10,
            ]);
        }
    }

    /**
     * @param array<string, mixed> $variant
     */
    public function saveVariant(int $storeId, array $variant, ?int $localId): int
    {
        $fields = [
            'product_id', 'sku', 'name', 'status', 'price_minor', 'sale_price_minor', 'track_stock',
            'stock_quantity', 'stock_status', 'weight_grams', 'width_mm', 'height_mm', 'length_mm',
            'image', 'attributes_json', 'sort_order',
        ];
        $values = array_map(static fn (string $field): mixed => $variant[$field] ?? null, $fields);
        if ($localId) {
            $sets = implode(', ', array_map(static fn (string $field): string => "{$field} = ?", $fields));
            $statement = $this->pdo->prepare(
                "UPDATE commerce_product_variants SET {$sets}, updated_at = CURRENT_TIMESTAMP
                 WHERE id = ? AND store_id = ?"
            );
            $statement->execute([...$values, $localId, $storeId]);

            return $localId;
        }
        $columns = implode(', ', $fields);
        $placeholders = implode(', ', array_fill(0, count($fields) + 1, '?'));
        $statement = $this->pdo->prepare(
            "INSERT INTO commerce_product_variants (store_id, {$columns}) VALUES ({$placeholders})"
        );
        $statement->execute([$storeId, ...$values]);

        return (int) $this->pdo->lastInsertId();
    }

    public function json(mixed $value): string
    {
        return json_encode(
            $value,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        );
    }
}
