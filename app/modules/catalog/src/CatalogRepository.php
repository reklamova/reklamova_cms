<?php

declare(strict_types=1);

namespace Reklamova\Cms\Modules\Catalog;

use PDO;

final class CatalogRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function categories(bool $publishedOnly = false): array
    {
        $where = $publishedOnly ? 'WHERE status = "published"' : '';

        return $this->pdo
            ->query("SELECT * FROM catalog_categories {$where} ORDER BY parent_id IS NOT NULL, parent_id ASC, sort_order ASC, name ASC")
            ->fetchAll(PDO::FETCH_ASSOC);
    }

    public function categoryTreeOptions(?int $selected = null, ?int $excludeId = null): string
    {
        $categories = $this->categories(false);
        $byParent = [];
        foreach ($categories as $category) {
            if ($excludeId !== null && (int) $category['id'] === $excludeId) {
                continue;
            }
            $byParent[(int) ($category['parent_id'] ?? 0)][] = $category;
        }

        $html = '<option value="">Brak</option>';
        $walk = function (int $parentId, int $level) use (&$walk, &$html, $byParent, $selected): void {
            foreach ($byParent[$parentId] ?? [] as $category) {
                $prefix = str_repeat('-- ', $level);
                $id = (int) $category['id'];
                $html .= '<option value="' . $id . '"' . ($selected === $id ? ' selected' : '') . '>' . htmlspecialchars($prefix . (string) $category['name'], ENT_QUOTES) . '</option>';
                $walk($id, $level + 1);
            }
        };
        $walk(0, 0);

        return $html;
    }

    public function products(bool $publishedOnly = false): array
    {
        $where = $publishedOnly ? 'WHERE p.status = "published"' : '';

        return $this->pdo
            ->query("SELECT p.*, c.name AS category_name, c.full_path AS category_path
                FROM catalog_products p
                LEFT JOIN catalog_categories c ON c.id = p.category_id
                {$where}
                ORDER BY c.full_path ASC, p.sort_order ASC, p.name ASC")
            ->fetchAll(PDO::FETCH_ASSOC);
    }

    public function productsForCategory(int $categoryId, bool $publishedOnly = true): array
    {
        $where = $publishedOnly ? 'AND status = "published"' : '';
        $statement = $this->pdo->prepare("SELECT * FROM catalog_products WHERE category_id = ? {$where} ORDER BY sort_order ASC, name ASC");
        $statement->execute([$categoryId]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function childCategories(?int $parentId, bool $publishedOnly = true): array
    {
        $where = $publishedOnly ? 'AND status = "published"' : '';
        if ($parentId === null) {
            $statement = $this->pdo->query("SELECT * FROM catalog_categories WHERE parent_id IS NULL {$where} ORDER BY sort_order ASC, name ASC");
            return $statement->fetchAll(PDO::FETCH_ASSOC);
        }

        $statement = $this->pdo->prepare("SELECT * FROM catalog_categories WHERE parent_id = ? {$where} ORDER BY sort_order ASC, name ASC");
        $statement->execute([$parentId]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findCategory(int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM catalog_categories WHERE id = ? LIMIT 1');
        $statement->execute([$id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function findProduct(int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT p.*, c.name AS category_name, c.full_path AS category_path FROM catalog_products p LEFT JOIN catalog_categories c ON c.id = p.category_id WHERE p.id = ? LIMIT 1');
        $statement->execute([$id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function findCategoryByPath(string $path): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM catalog_categories WHERE full_path = ? AND status = "published" LIMIT 1');
        $statement->execute([trim($path, '/')]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function findProductByPath(string $path): ?array
    {
        $statement = $this->pdo->prepare('SELECT p.*, c.name AS category_name, c.full_path AS category_path FROM catalog_products p LEFT JOIN catalog_categories c ON c.id = p.category_id WHERE p.full_path = ? AND p.status = "published" LIMIT 1');
        $statement->execute([trim($path, '/')]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function saveCategory(array $data, ?int $id = null): int
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw new \InvalidArgumentException('Nazwa kategorii jest wymagana.');
        }

        $parentId = $this->nullableInt($data['parent_id'] ?? null);
        $slug = trim((string) ($data['slug'] ?? '')) ?: $this->slugify($name);
        $fullPath = $this->categoryFullPath($slug, $parentId);
        $payload = [
            'parent_id' => $parentId,
            'name' => $name,
            'slug' => $slug,
            'full_path' => $fullPath,
            'summary' => trim((string) ($data['summary'] ?? '')),
            'description' => trim((string) ($data['description'] ?? '')),
            'featured_image' => trim((string) ($data['featured_image'] ?? '')),
            'icon' => trim((string) ($data['icon'] ?? '')),
            'status' => $this->status((string) ($data['status'] ?? 'draft')),
            'sort_order' => (int) ($data['sort_order'] ?? 100),
            'meta_title' => trim((string) ($data['meta_title'] ?? '')),
            'meta_description' => trim((string) ($data['meta_description'] ?? '')),
            'og_image' => trim((string) ($data['og_image'] ?? '')),
            'settings_json' => $this->jsonOrNull($data['settings_json'] ?? null),
        ];

        if ($id) {
            $this->update('catalog_categories', $payload, $id);
            $this->rebuildCategoryDescendants($id);
            return $id;
        }

        return $this->insert('catalog_categories', $payload);
    }

    public function saveProduct(array $data, ?int $id = null): int
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw new \InvalidArgumentException('Nazwa produktu jest wymagana.');
        }

        $categoryId = $this->nullableInt($data['category_id'] ?? null);
        $slug = trim((string) ($data['slug'] ?? '')) ?: $this->slugify($name);
        $payload = [
            'category_id' => $categoryId,
            'name' => $name,
            'slug' => $slug,
            'full_path' => $this->productFullPath($slug, $categoryId),
            'sku' => trim((string) ($data['sku'] ?? '')),
            'brand' => trim((string) ($data['brand'] ?? '')),
            'model' => trim((string) ($data['model'] ?? '')),
            'summary' => trim((string) ($data['summary'] ?? '')),
            'description' => trim((string) ($data['description'] ?? '')),
            'specs_json' => $this->specsJson((string) ($data['specs'] ?? ''), $data['specs_json'] ?? null),
            'gallery_json' => $this->listJson((string) ($data['gallery'] ?? ''), $data['gallery_json'] ?? null),
            'documents_json' => $this->listJson((string) ($data['documents'] ?? ''), $data['documents_json'] ?? null),
            'featured_image' => trim((string) ($data['featured_image'] ?? '')),
            'status' => $this->status((string) ($data['status'] ?? 'draft')),
            'is_featured' => !empty($data['is_featured']) ? 1 : 0,
            'sort_order' => (int) ($data['sort_order'] ?? 100),
            'meta_title' => trim((string) ($data['meta_title'] ?? '')),
            'meta_description' => trim((string) ($data['meta_description'] ?? '')),
            'og_image' => trim((string) ($data['og_image'] ?? '')),
            'schema_json' => $this->jsonOrNull($data['schema_json'] ?? null),
        ];

        if ($id) {
            $this->update('catalog_products', $payload, $id);
            return $id;
        }

        return $this->insert('catalog_products', $payload);
    }

    public function duplicateProduct(int $id): int
    {
        $product = $this->findProduct($id);
        if (!$product) {
            throw new \InvalidArgumentException('Produkt nie istnieje.');
        }

        $categoryId = $this->nullableInt($product['category_id'] ?? null);
        $baseSlug = $this->slugify((string) ($product['slug'] ?? $product['name'] ?? 'produkt'));
        $slug = $this->uniqueProductSlug($baseSlug . '-kopia', $categoryId);
        $payload = [
            'category_id' => $categoryId,
            'name' => trim((string) $product['name']) . ' - kopia',
            'slug' => $slug,
            'full_path' => $this->productFullPath($slug, $categoryId),
            'sku' => trim((string) ($product['sku'] ?? '')),
            'brand' => trim((string) ($product['brand'] ?? '')),
            'model' => trim((string) ($product['model'] ?? '')),
            'summary' => trim((string) ($product['summary'] ?? '')),
            'description' => trim((string) ($product['description'] ?? '')),
            'specs_json' => $this->jsonOrNull($product['specs_json'] ?? null),
            'gallery_json' => $this->jsonOrNull($product['gallery_json'] ?? null),
            'documents_json' => $this->jsonOrNull($product['documents_json'] ?? null),
            'featured_image' => trim((string) ($product['featured_image'] ?? '')),
            'status' => 'draft',
            'is_featured' => 0,
            'sort_order' => (int) ($product['sort_order'] ?? 100) + 1,
            'meta_title' => trim((string) ($product['meta_title'] ?? '')),
            'meta_description' => trim((string) ($product['meta_description'] ?? '')),
            'og_image' => trim((string) ($product['og_image'] ?? '')),
            'schema_json' => $this->jsonOrNull($product['schema_json'] ?? null),
        ];

        return $this->insert('catalog_products', $payload);
    }

    public function deleteCategory(int $id): void
    {
        $statement = $this->pdo->prepare('DELETE FROM catalog_categories WHERE id = ?');
        $statement->execute([$id]);
    }

    public function deleteProduct(int $id): void
    {
        $statement = $this->pdo->prepare('DELETE FROM catalog_products WHERE id = ?');
        $statement->execute([$id]);
    }

    public function importCsv(string $csv): array
    {
        $createdCategories = 0;
        $createdProducts = 0;
        foreach (preg_split('/\R/', trim($csv)) ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }
            $columns = str_getcsv($line, ';');
            if (count($columns) < 2 || strtolower(trim((string) $columns[0])) === 'category_path') {
                continue;
            }

            $categoryPath = trim((string) ($columns[0] ?? ''));
            $productName = trim((string) ($columns[1] ?? ''));
            if ($categoryPath === '' || $productName === '') {
                continue;
            }

            $categoryId = $this->ensureCategoryPath($categoryPath);
            $createdCategories++;
            $existing = $this->findProductByFullPath($this->productFullPath($this->slugify($productName), $categoryId));
            if ($existing) {
                continue;
            }

            $this->saveProduct([
                'category_id' => $categoryId,
                'name' => $productName,
                'sku' => trim((string) ($columns[2] ?? '')),
                'brand' => trim((string) ($columns[3] ?? '')),
                'featured_image' => trim((string) ($columns[4] ?? '')),
                'summary' => trim((string) ($columns[5] ?? '')),
                'status' => 'draft',
            ]);
            $createdProducts++;
        }

        return ['categories_touched' => $createdCategories, 'products_created' => $createdProducts];
    }

    public function slugify(string $value): string
    {
        $value = strtr(mb_strtolower(trim($value), 'UTF-8'), [
            'ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ż' => 'z', 'ź' => 'z',
            'Ą' => 'a', 'Ć' => 'c', 'Ę' => 'e', 'Ł' => 'l', 'Ń' => 'n', 'Ó' => 'o', 'Ś' => 's', 'Ż' => 'z', 'Ź' => 'z',
        ]);
        $value = preg_replace('/[^a-z0-9]+/i', '-', $value) ?: 'item';

        return trim($value, '-') ?: 'item';
    }

    private function ensureCategoryPath(string $path): int
    {
        $parentId = null;
        $currentPath = '';
        foreach (array_filter(array_map('trim', explode('>', $path))) as $part) {
            $slug = $this->slugify($part);
            $currentPath = trim($currentPath . '/' . $slug, '/');
            $category = $this->findAnyCategoryByPath($currentPath);
            if (!$category) {
                $parentId = $this->saveCategory(['name' => $part, 'slug' => $slug, 'parent_id' => $parentId, 'status' => 'draft']);
                continue;
            }
            $parentId = (int) $category['id'];
        }

        return (int) $parentId;
    }

    private function categoryFullPath(string $slug, ?int $parentId): string
    {
        if ($parentId === null) {
            return $slug;
        }

        $parent = $this->findCategory($parentId);

        return trim((string) ($parent['full_path'] ?? '') . '/' . $slug, '/');
    }

    private function productFullPath(string $slug, ?int $categoryId): string
    {
        if ($categoryId === null) {
            return $slug;
        }

        $category = $this->findCategory($categoryId);

        return trim((string) ($category['full_path'] ?? '') . '/' . $slug, '/');
    }

    private function rebuildCategoryDescendants(int $categoryId): void
    {
        foreach ($this->childCategories($categoryId, false) as $child) {
            $fullPath = $this->categoryFullPath((string) $child['slug'], (int) $child['parent_id']);
            $statement = $this->pdo->prepare('UPDATE catalog_categories SET full_path = ? WHERE id = ?');
            $statement->execute([$fullPath, (int) $child['id']]);
            $this->rebuildCategoryDescendants((int) $child['id']);
        }

        foreach ($this->productsForCategory($categoryId, false) as $product) {
            $statement = $this->pdo->prepare('UPDATE catalog_products SET full_path = ? WHERE id = ?');
            $statement->execute([$this->productFullPath((string) $product['slug'], $categoryId), (int) $product['id']]);
        }
    }

    private function findAnyCategoryByPath(string $path): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM catalog_categories WHERE full_path = ? LIMIT 1');
        $statement->execute([trim($path, '/')]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    private function findProductByFullPath(string $path): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM catalog_products WHERE full_path = ? LIMIT 1');
        $statement->execute([trim($path, '/')]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    private function uniqueProductSlug(string $baseSlug, ?int $categoryId): string
    {
        $baseSlug = $this->slugify($baseSlug);
        $slug = $baseSlug;
        $counter = 2;
        while ($this->findProductByFullPath($this->productFullPath($slug, $categoryId))) {
            $slug = $baseSlug . '-' . $counter;
            $counter++;
        }

        return $slug;
    }

    private function insert(string $table, array $payload): int
    {
        $columns = implode(', ', array_keys($payload));
        $placeholders = implode(', ', array_fill(0, count($payload), '?'));
        $statement = $this->pdo->prepare("INSERT INTO {$table} ({$columns}) VALUES ({$placeholders})");
        $statement->execute(array_values($payload));

        return (int) $this->pdo->lastInsertId();
    }

    private function update(string $table, array $payload, int $id): void
    {
        $sets = implode(', ', array_map(static fn (string $field): string => "{$field} = ?", array_keys($payload)));
        $statement = $this->pdo->prepare("UPDATE {$table} SET {$sets}, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        $statement->execute([...array_values($payload), $id]);
    }

    private function specsJson(string $lines, mixed $existing = null): ?string
    {
        if (trim($lines) === '' && is_string($existing) && $existing !== '') {
            return $existing;
        }

        $specs = [];
        foreach (preg_split('/\R/', trim($lines)) ?: [] as $line) {
            $parts = array_map('trim', explode('|', $line, 2));
            if (($parts[0] ?? '') === '') {
                continue;
            }
            $specs[] = ['name' => $parts[0], 'value' => $parts[1] ?? ''];
        }

        return $specs ? json_encode($specs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
    }

    private function listJson(string $lines, mixed $existing = null): ?string
    {
        if (trim($lines) === '' && is_string($existing) && $existing !== '') {
            return $existing;
        }

        $items = [];
        foreach (preg_split('/\R/', trim($lines)) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '') {
                $items[] = $line;
            }
        }

        return $items ? json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
    }

    private function jsonOrNull(mixed $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        json_decode($value, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \InvalidArgumentException('Niepoprawny JSON.');
        }

        return $value;
    }

    private function status(string $status): string
    {
        return in_array($status, ['draft', 'published', 'archived'], true) ? $status : 'draft';
    }

    private function nullableInt(mixed $value): ?int
    {
        $value = trim((string) $value);

        return $value === '' || $value === '0' ? null : (int) $value;
    }
}
