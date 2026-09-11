<?php

declare(strict_types=1);

namespace Reklamova\Cms\Commerce\Catalog;

use PDO;

final class StorefrontRepository
{
    public function __construct(private PDO $pdo, private string $storeCode)
    {
        if (trim($storeCode) === '') {
            throw new \InvalidArgumentException('Store code is required.');
        }
    }

    /** @return array<string, mixed> */
    public function store(): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, code, name, currency, country_code, prices_include_tax, settings_json
             FROM commerce_stores WHERE code = ? AND status = "active" LIMIT 1'
        );
        $statement->execute([$this->storeCode]);
        $store = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$store) {
            throw new \DomainException('Store is unavailable.');
        }
        $store['id'] = (int) $store['id'];
        $store['prices_include_tax'] = (bool) $store['prices_include_tax'];
        $store['settings'] = $this->json((string) ($store['settings_json'] ?? ''));

        return $store;
    }

    /** @return array<int, array<string, mixed>> */
    public function categories(?int $parentId = null): array
    {
        $storeId = (int) $this->store()['id'];
        if ($parentId === null) {
            $statement = $this->pdo->prepare(
                'SELECT * FROM commerce_categories
                 WHERE store_id = ? AND parent_id IS NULL AND status = "published"
                 ORDER BY sort_order, name'
            );
            $statement->execute([$storeId]);
        } else {
            $statement = $this->pdo->prepare(
                'SELECT * FROM commerce_categories
                 WHERE store_id = ? AND parent_id = ? AND status = "published"
                 ORDER BY sort_order, name'
            );
            $statement->execute([$storeId, $parentId]);
        }

        return array_map(
            fn (array $row): array => $this->hydrateCategory($row),
            $statement->fetchAll(PDO::FETCH_ASSOC),
        );
    }

    /** @return array<string, mixed>|null */
    public function categoryByPath(string $path): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT c.* FROM commerce_categories c
             INNER JOIN commerce_stores s ON s.id = c.store_id
             WHERE s.code = ? AND c.full_path = ? AND c.status = "published" LIMIT 1'
        );
        $statement->execute([$this->storeCode, trim($path, '/')]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->hydrateCategory($row) : null;
    }

    /** @return array<int, array<string, mixed>> */
    public function products(?int $categoryId = null, int $limit = 60, int $offset = 0): array
    {
        $limit = max(1, min(120, $limit));
        $offset = max(0, $offset);
        $params = [$this->storeCode];
        $categoryJoin = '';
        $categoryWhere = '';
        if ($categoryId !== null) {
            $categoryJoin = ' INNER JOIN commerce_product_categories pc ON pc.product_id = p.id';
            $categoryWhere = ' AND pc.category_id = ?';
            $params[] = $categoryId;
        }
        $statement = $this->pdo->prepare(
            "SELECT DISTINCT p.id, p.name, p.slug, p.sku, p.summary, p.type, p.base_price_minor,
                    p.sale_price_minor, p.currency, p.stock_status, p.featured_image, p.gallery_json,
                    p.meta_title, p.meta_description, p.og_image, p.sort_order
             FROM commerce_products p
             INNER JOIN commerce_stores s ON s.id = p.store_id
             {$categoryJoin}
             WHERE s.code = ? AND p.status = 'published' AND p.visibility <> 'hidden'{$categoryWhere}
             ORDER BY p.sort_order, p.name LIMIT {$limit} OFFSET {$offset}"
        );
        $statement->execute($params);

        return array_map(fn (array $row): array => $this->hydrateProductCard($row), $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array<int, array<string, mixed>> */
    public function search(string $query, int $limit = 40): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }
        $limit = max(1, min(80, $limit));
        $like = '%' . $query . '%';
        $statement = $this->pdo->prepare(
            "SELECT p.id, p.name, p.slug, p.sku, p.summary, p.type, p.base_price_minor,
                    p.sale_price_minor, p.currency, p.stock_status, p.featured_image, p.gallery_json,
                    p.meta_title, p.meta_description, p.og_image, p.sort_order
             FROM commerce_products p
             INNER JOIN commerce_stores s ON s.id = p.store_id
             WHERE s.code = ? AND p.status = 'published' AND p.visibility <> 'hidden'
               AND (p.name LIKE ? OR p.sku LIKE ? OR p.summary LIKE ? OR p.description LIKE ?)
             ORDER BY CASE WHEN p.name LIKE ? THEN 0 ELSE 1 END, p.sort_order, p.name LIMIT {$limit}"
        );
        $statement->execute([$this->storeCode, $like, $like, $like, $like, $query . '%']);

        return array_map(fn (array $row): array => $this->hydrateProductCard($row), $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    public function productBySlug(string $slug): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT p.*, COALESCE(tc.rate_bps, 0) AS tax_rate_bps
             FROM commerce_products p
             INNER JOIN commerce_stores s ON s.id = p.store_id
             LEFT JOIN commerce_tax_classes tc ON tc.id = p.tax_class_id
             WHERE s.code = ? AND p.slug = ? AND p.status = "published" LIMIT 1'
        );
        $statement->execute([$this->storeCode, trim($slug)]);
        $product = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$product) {
            return null;
        }
        $product = $this->hydrateProductCard($product);
        $product['description'] = (string) ($product['description'] ?? '');
        $product['content_sections'] = $this->json((string) ($product['content_sections_json'] ?? ''));
        $product['schema'] = $this->json((string) ($product['schema_json'] ?? ''));
        $product['variants'] = $this->variants((int) $product['id']);
        $product['options'] = $this->options((int) $product['id']);
        $product['categories'] = $this->productCategories((int) $product['id']);
        $product['file_requirements'] = $this->fileRequirements((int) $product['id']);

        return $product;
    }

    /** @return array<int, array<string, mixed>> */
    public function shippingMethods(string $countryCode = 'PL'): array
    {
        $statement = $this->pdo->prepare(
            'SELECT sm.code, sm.name, sm.provider, sm.service_code, sm.type, sm.price_minor,
                    sm.tax_rate_bps, sm.cod_allowed, sm.countries_json
             FROM commerce_shipping_methods sm
             INNER JOIN commerce_stores s ON s.id = sm.store_id
             WHERE s.code = ? AND sm.active = 1 ORDER BY sm.sort_order, sm.name'
        );
        $statement->execute([$this->storeCode]);
        $countryCode = strtoupper($countryCode);
        $rows = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $countries = $this->json((string) ($row['countries_json'] ?? ''));
            if ($countries !== [] && !in_array($countryCode, array_map('strtoupper', $countries), true)) {
                continue;
            }
            $row['price_minor'] = (int) $row['price_minor'];
            $row['tax_rate_bps'] = (int) $row['tax_rate_bps'];
            $row['cod_allowed'] = (bool) $row['cod_allowed'];
            $row['countries'] = $countries;
            unset($row['countries_json']);
            $rows[] = $row;
        }

        return $rows;
    }

    /** @return array<int, array<string, mixed>> */
    public function paymentMethods(): array
    {
        $statement = $this->pdo->prepare(
            'SELECT pm.code, pm.name, pm.provider, pm.type, pm.rules_json
             FROM commerce_payment_methods pm
             INNER JOIN commerce_stores s ON s.id = pm.store_id
             WHERE s.code = ? AND pm.active = 1 ORDER BY pm.sort_order, pm.name'
        );
        $statement->execute([$this->storeCode]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['rules'] = $this->json((string) ($row['rules_json'] ?? ''));
            unset($row['rules_json']);
        }

        return $rows;
    }

    /** @return array<int, array{type: string, slug: string, updated_at: string}> */
    public function seoEntries(): array
    {
        $entries = [];
        $categories = $this->pdo->prepare(
            'SELECT c.full_path, c.updated_at
             FROM commerce_categories c
             INNER JOIN commerce_stores s ON s.id = c.store_id
             WHERE s.code = ? AND c.status = "published" AND c.full_path <> ""
             ORDER BY c.full_path'
        );
        $categories->execute([$this->storeCode]);
        foreach ($categories->fetchAll(PDO::FETCH_ASSOC) as $category) {
            $entries[] = [
                'type' => 'category',
                'slug' => trim((string) $category['full_path'], '/'),
                'updated_at' => (string) $category['updated_at'],
            ];
        }
        $products = $this->pdo->prepare(
            'SELECT p.slug, p.updated_at
             FROM commerce_products p
             INNER JOIN commerce_stores s ON s.id = p.store_id
             WHERE s.code = ? AND p.status = "published" AND p.visibility <> "hidden" AND p.slug <> ""
             ORDER BY p.slug'
        );
        $products->execute([$this->storeCode]);
        foreach ($products->fetchAll(PDO::FETCH_ASSOC) as $product) {
            $entries[] = [
                'type' => 'product',
                'slug' => (string) $product['slug'],
                'updated_at' => (string) $product['updated_at'],
            ];
        }

        return $entries;
    }

    /** @return array<int, array<string, mixed>> */
    private function variants(int $productId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, sku, name, price_minor, sale_price_minor, stock_status, stock_quantity,
                    image, attributes_json, sort_order
             FROM commerce_product_variants
             WHERE product_id = ? AND status = "active" ORDER BY sort_order, id'
        );
        $statement->execute([$productId]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
            $row['price_minor'] = $row['price_minor'] === null ? null : (int) $row['price_minor'];
            $row['sale_price_minor'] = $row['sale_price_minor'] === null ? null : (int) $row['sale_price_minor'];
            $row['stock_quantity'] = $row['stock_quantity'] === null ? null : (int) $row['stock_quantity'];
            $row['effective_price_minor'] = $row['sale_price_minor'] ?? $row['price_minor'];
            $row['has_sale_price'] = $row['sale_price_minor'] !== null;
            $row['attributes'] = $this->json((string) ($row['attributes_json'] ?? ''));
            unset($row['attributes_json']);
        }

        return $rows;
    }

    /** @return array<int, array<string, mixed>> */
    private function options(int $productId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT po.id, po.code, po.name, po.input_type, po.required, po.affects_price,
                    po.validation_json, po.sort_order, pov.code AS value_code, pov.label AS value_label,
                    pov.price_delta_minor
             FROM commerce_product_options po
             LEFT JOIN commerce_product_option_values pov ON pov.option_id = po.id
             WHERE po.product_id = ? ORDER BY po.sort_order, pov.sort_order, pov.id'
        );
        $statement->execute([$productId]);
        $options = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $id = (int) $row['id'];
            $options[$id] ??= [
                'id' => $id,
                'code' => (string) $row['code'],
                'name' => (string) $row['name'],
                'input_type' => (string) $row['input_type'],
                'required' => (bool) $row['required'],
                'affects_price' => (bool) $row['affects_price'],
                'validation' => $this->json((string) ($row['validation_json'] ?? '')),
                'sort_order' => (int) $row['sort_order'],
                'values' => [],
            ];
            if ($row['value_code'] !== null) {
                $options[$id]['values'][] = [
                    'code' => (string) $row['value_code'],
                    'label' => (string) $row['value_label'],
                    'price_delta_minor' => (int) $row['price_delta_minor'],
                ];
            }
        }

        return array_values($options);
    }

    /** @return array<int, array<string, mixed>> */
    private function productCategories(int $productId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT c.id, c.name, c.slug, c.full_path, pc.is_primary
             FROM commerce_product_categories pc
             INNER JOIN commerce_categories c ON c.id = pc.category_id
             WHERE pc.product_id = ? AND c.status = "published" ORDER BY pc.is_primary DESC, pc.sort_order'
        );
        $statement->execute([$productId]);

        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
            $row['is_primary'] = (bool) $row['is_primary'];
        }

        return $rows;
    }

    /** @return array<int, array<string, mixed>> */
    private function fileRequirements(int $productId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, code, label, required, upload_timing, allowed_extensions_json,
                    allowed_mime_types_json, max_bytes, max_files
             FROM commerce_product_file_requirements WHERE product_id = ? ORDER BY sort_order, id'
        );
        $statement->execute([$productId]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
            $row['required'] = (bool) $row['required'];
            $row['max_bytes'] = (int) $row['max_bytes'];
            $row['max_files'] = (int) $row['max_files'];
            $row['allowed_extensions'] = $this->json((string) $row['allowed_extensions_json']);
            $row['allowed_mime_types'] = $this->json((string) $row['allowed_mime_types_json']);
            unset($row['allowed_extensions_json'], $row['allowed_mime_types_json']);
        }

        return $rows;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function hydrateProductCard(array $row): array
    {
        foreach (['id', 'base_price_minor', 'sale_price_minor', 'tax_rate_bps'] as $field) {
            if (array_key_exists($field, $row)) {
                $row[$field] = $row[$field] === null ? null : (int) $row[$field];
            }
        }
        $row['gallery'] = $this->json((string) ($row['gallery_json'] ?? ''));
        $row['effective_price_minor'] = $row['sale_price_minor'] ?? $row['base_price_minor'];
        $row['has_sale_price'] = $row['sale_price_minor'] !== null;

        return $row;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function hydrateCategory(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['parent_id'] = $row['parent_id'] === null ? null : (int) $row['parent_id'];
        $row['store_id'] = (int) $row['store_id'];
        $row['sort_order'] = (int) $row['sort_order'];

        return $row;
    }

    /** @return array<mixed> */
    private function json(string $json): array
    {
        if ($json === '') {
            return [];
        }
        $value = json_decode($json, true);

        return is_array($value) ? $value : [];
    }
}
