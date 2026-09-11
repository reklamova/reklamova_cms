<?php

declare(strict_types=1);

namespace Reklamova\Cms\Commerce\Import;

final class WordPressImporter
{
    public function __construct(
        private CommerceImportRepository $repository,
        private ?ProductMediaMigrator $mediaMigrator = null,
    ) {
    }

    /**
     * @param array<string, mixed> $snapshot
     * @param array<string, mixed> $store
     * @return array<string, mixed>
     */
    public function run(array $snapshot, array $store, bool $dryRun = true): array
    {
        $this->validateSnapshot($snapshot);
        $conflicts = $this->conflicts($snapshot);
        $report = [
            'mode' => $dryRun ? 'dry_run' : 'import',
            'source' => [
                'system' => $snapshot['source_system'] ?? 'woocommerce',
                'version' => $snapshot['source_version'] ?? null,
                'captured_at' => $snapshot['captured_at'] ?? null,
            ],
            'source_counts' => $snapshot['counts'],
            'planned' => [
                'store' => 1,
                'tax_classes' => count($snapshot['tax_rates'] ?? []),
                'categories' => count($snapshot['categories']),
                'attributes' => count($snapshot['attributes']),
                'products' => count($snapshot['products']),
                'variants' => count($snapshot['variants']),
                'images' => (int) ($snapshot['counts']['images'] ?? 0),
            ],
            'result' => $this->emptyCounters(),
            'conflicts' => $conflicts,
            'errors' => [],
        ];
        if ($conflicts !== []) {
            $report['status'] = 'blocked';

            return $report;
        }
        if ($dryRun) {
            $report['status'] = 'dry_run_ok';

            return $report;
        }
        if ($this->mediaMigrator === null && (int) ($snapshot['counts']['images'] ?? 0) > 0) {
            throw new \RuntimeException('A product media migrator is required for a real import.');
        }

        try {
            $report = $this->repository->transaction(function () use ($snapshot, $store, $report): array {
                $storeId = $this->repository->ensureStore($store);
                $runId = $this->repository->startRun($storeId, 'import', $snapshot);
                $report['result']['stores']['updated']++;

                $taxClasses = $this->importTaxes($storeId, $snapshot['tax_rates'] ?? [], $report);
                $categories = $this->importCategories($storeId, $snapshot['categories'], $runId, $report);
                $attributes = $this->importAttributes($storeId, $snapshot['attributes'], $runId, $report);
                $products = $this->importProducts(
                    $storeId,
                    $snapshot['products'],
                    $taxClasses,
                    $categories,
                    $attributes,
                    $runId,
                    $report
                );
                $this->importVariants($storeId, $snapshot['variants'], $products, $runId, $report);
                $report['status'] = 'imported';
                $this->repository->finishRun($runId, 'completed', $report);

                return $report;
            });
        } catch (\Throwable $exception) {
            $report['status'] = 'failed';
            $report['errors'][] = [
                'code' => 'import_failed',
                'message' => $exception->getMessage(),
            ];
        }

        return $report;
    }

    /**
     * @param array<int, array<string, mixed>> $rates
     * @param array<string, mixed> $report
     * @return array<string, int>
     */
    private function importTaxes(int $storeId, array $rates, array &$report): array
    {
        $result = [];
        if ($rates === []) {
            $rates[] = ['class' => 'standard', 'name' => 'VAT', 'rate_bps' => 2300, 'shipping' => true];
        }
        foreach ($rates as $rate) {
            $code = trim((string) ($rate['class'] ?? '')) ?: 'standard';
            if (isset($result[$code])) {
                continue;
            }
            $result[$code] = $this->repository->upsertTaxClass($storeId, [
                'code' => $code,
                'name' => (string) (($rate['name'] ?? '') ?: strtoupper($code)),
                'rate_bps' => (int) ($rate['rate_bps'] ?? 0),
                'shipping' => !empty($rate['shipping']),
            ]);
            $report['result']['tax_classes']['updated']++;
        }

        return $result;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, mixed> $report
     * @return array<string, array{id: int, path: string}>
     */
    private function importCategories(int $storeId, array $rows, int $runId, array &$report): array
    {
        $pending = [];
        foreach ($rows as $row) {
            $pending[(string) $row['external_id']] = $row;
        }
        $imported = [];
        while ($pending !== []) {
            $progress = false;
            foreach ($pending as $externalId => $row) {
                $parentExternalId = $row['parent_external_id'] ?? null;
                if ($parentExternalId !== null && !isset($imported[$parentExternalId])) {
                    continue;
                }
                $parent = $parentExternalId !== null ? $imported[$parentExternalId] : null;
                $path = trim(($parent['path'] ?? '') . '/' . $row['slug'], '/');
                $hash = $this->hash($row + ['full_path' => $path]);
                $mapping = $this->repository->mapping($storeId, 'category', $externalId);
                $state = $this->state($mapping, $hash);
                $localId = $mapping['local_id'] ?? null;
                if ($state !== 'unchanged') {
                    $localId = $this->repository->saveCategory($storeId, [
                        'parent_id' => $parent['id'] ?? null,
                        'name' => $row['name'],
                        'slug' => $row['slug'],
                        'full_path' => $path,
                        'description' => $row['description'] ?? '',
                        'status' => 'published',
                    ], $localId);
                    $this->repository->saveMapping($storeId, 'category', $externalId, 'category', $localId, $hash, $runId);
                }
                $report['result']['categories'][$state]++;
                $imported[$externalId] = ['id' => (int) $localId, 'path' => $path];
                unset($pending[$externalId]);
                $progress = true;
            }
            if (!$progress) {
                throw new \RuntimeException('Category hierarchy contains an unresolved parent or cycle.');
            }
        }

        return $imported;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, mixed> $report
     * @return array<string, array{id: int, terms: array<string, int>}>
     */
    private function importAttributes(int $storeId, array $rows, int $runId, array &$report): array
    {
        $imported = [];
        foreach ($rows as $row) {
            $externalId = (string) $row['external_id'];
            $hash = $this->hash($row);
            $mapping = $this->repository->mapping($storeId, 'attribute', $externalId);
            $state = $this->state($mapping, $hash);
            $localId = $mapping['local_id'] ?? null;
            if ($state !== 'unchanged') {
                $localId = $this->repository->saveAttribute($storeId, [
                    'code' => $row['code'],
                    'name' => $row['name'],
                    'input_type' => $row['input_type'] ?: 'select',
                    'is_variant' => true,
                    'settings' => ['orderby' => $row['orderby'] ?? 'menu_order'],
                ], $localId);
                $this->repository->saveMapping($storeId, 'attribute', $externalId, 'attribute', $localId, $hash, $runId);
            }
            $report['result']['attributes'][$state]++;
            $terms = [];
            foreach ($row['terms'] ?? [] as $term) {
                $termExternalId = (string) $term['external_id'];
                $termHash = $this->hash($term);
                $termMapping = $this->repository->mapping($storeId, 'attribute_term', $termExternalId);
                $termState = $this->state($termMapping, $termHash);
                $termId = $termMapping['local_id'] ?? null;
                if ($termState !== 'unchanged') {
                    $termId = $this->repository->saveAttributeTerm((int) $localId, $term, $termId);
                    $this->repository->saveMapping(
                        $storeId,
                        'attribute_term',
                        $termExternalId,
                        'attribute_term',
                        $termId,
                        $termHash,
                        $runId
                    );
                }
                $terms[(string) $term['slug']] = (int) $termId;
            }
            $imported[(string) $row['taxonomy']] = ['id' => (int) $localId, 'terms' => $terms];
        }

        return $imported;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, int> $taxClasses
     * @param array<string, array{id: int, path: string}> $categories
     * @param array<string, array{id: int, terms: array<string, int>}> $attributes
     * @param array<string, mixed> $report
     * @return array<string, int>
     */
    private function importProducts(
        int $storeId,
        array $rows,
        array $taxClasses,
        array $categories,
        array $attributes,
        int $runId,
        array &$report,
    ): array {
        $imported = [];
        foreach ($rows as $row) {
            $externalId = (string) $row['external_id'];
            $hash = $this->hash($row);
            $mapping = $this->repository->mapping($storeId, 'product', $externalId);
            $state = $this->state($mapping, $hash);
            $localId = $mapping['local_id'] ?? null;
            if ($state !== 'unchanged') {
                $featured = $this->media((string) ($row['featured_image_relative'] ?? ''), $report);
                $gallery = [];
                foreach ($row['gallery_relative'] ?? [] as $path) {
                    $url = $this->media((string) $path, $report);
                    if ($url !== null) {
                        $gallery[] = $url;
                    }
                }
                $taxCode = trim((string) ($row['tax_class'] ?? '')) ?: 'standard';
                $basePrice = $row['regular_price_minor'] ?? $row['current_price_minor'];
                $salePrice = $row['sale_price_minor'] ?? null;
                $localId = $this->repository->saveProduct($storeId, [
                    'tax_class_id' => $taxClasses[$taxCode] ?? $taxClasses['standard'] ?? null,
                    'type' => $row['type'],
                    'name' => $row['name'],
                    'slug' => $row['slug'],
                    'sku' => $row['sku'],
                    'summary' => $row['summary'],
                    'description' => $row['description'],
                    'status' => $this->contentStatus((string) $row['status']),
                    'base_price_minor' => $basePrice,
                    'sale_price_minor' => $salePrice,
                    'currency' => 'PLN',
                    'track_stock' => $row['track_stock'] ? 1 : 0,
                    'stock_quantity' => $row['stock_quantity'],
                    'stock_status' => $this->stockStatus((string) $row['stock_status']),
                    'weight_grams' => $row['weight_grams'],
                    'width_mm' => $row['width_mm'],
                    'height_mm' => $row['height_mm'],
                    'length_mm' => $row['length_mm'],
                    'featured_image' => $featured,
                    'gallery_json' => $this->repository->json($gallery),
                    'content_sections_json' => $this->repository->json([]),
                    'sort_order' => $row['sort_order'],
                ], $localId);
                $categoryIds = [];
                foreach ($row['category_external_ids'] ?? [] as $categoryExternalId) {
                    if (isset($categories[$categoryExternalId])) {
                        $categoryIds[] = $categories[$categoryExternalId]['id'];
                    }
                }
                $this->repository->syncProductCategories($localId, $categoryIds);
                $productAttributes = [];
                foreach ($row['attributes'] ?? [] as $code => $settings) {
                    if (!isset($attributes[$code])) {
                        continue;
                    }
                    $productAttributes[] = [
                        'attribute_id' => $attributes[$code]['id'],
                        'visible' => !empty($settings['visible']),
                        'variant' => !empty($settings['variant']),
                    ];
                }
                $this->repository->syncProductAttributes($localId, $productAttributes);
                $this->repository->saveMapping($storeId, 'product', $externalId, 'product', $localId, $hash, $runId);
            }
            $report['result']['products'][$state]++;
            $imported[$externalId] = (int) $localId;
        }

        return $imported;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, int> $products
     * @param array<string, mixed> $report
     */
    private function importVariants(
        int $storeId,
        array $rows,
        array $products,
        int $runId,
        array &$report,
    ): void {
        foreach ($rows as $row) {
            $parentExternalId = (string) $row['product_external_id'];
            if (!isset($products[$parentExternalId])) {
                throw new \RuntimeException("Variant {$row['external_id']} has no imported product.");
            }
            $externalId = (string) $row['external_id'];
            $hash = $this->hash($row);
            $mapping = $this->repository->mapping($storeId, 'variant', $externalId);
            $state = $this->state($mapping, $hash);
            $localId = $mapping['local_id'] ?? null;
            if ($state !== 'unchanged') {
                $localId = $this->repository->saveVariant($storeId, [
                    'product_id' => $products[$parentExternalId],
                    'sku' => $row['sku'],
                    'name' => $row['name'] ?: null,
                    'status' => $this->contentStatus((string) $row['status']) === 'published' ? 'active' : 'inactive',
                    'price_minor' => $row['regular_price_minor'] ?? $row['current_price_minor'],
                    'sale_price_minor' => $row['sale_price_minor'],
                    'track_stock' => $row['track_stock'] ? 1 : 0,
                    'stock_quantity' => $row['stock_quantity'],
                    'stock_status' => $this->stockStatus((string) $row['stock_status']),
                    'weight_grams' => $row['weight_grams'],
                    'width_mm' => $row['width_mm'],
                    'height_mm' => $row['height_mm'],
                    'length_mm' => $row['length_mm'],
                    'image' => $this->media((string) ($row['featured_image_relative'] ?? ''), $report),
                    'attributes_json' => $this->repository->json($row['attributes'] ?? []),
                    'sort_order' => $row['sort_order'],
                ], $localId);
                $this->repository->saveMapping($storeId, 'variant', $externalId, 'variant', $localId, $hash, $runId);
            }
            $report['result']['variants'][$state]++;
        }
    }

    /**
     * @param array<string, mixed>|null $mapping
     */
    private function state(?array $mapping, string $hash): string
    {
        if ($mapping === null) {
            return 'created';
        }

        return hash_equals((string) ($mapping['source_hash'] ?? ''), $hash) ? 'unchanged' : 'updated';
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    private function validateSnapshot(array $snapshot): void
    {
        foreach (['categories', 'attributes', 'products', 'variants', 'counts'] as $key) {
            if (!isset($snapshot[$key]) || !is_array($snapshot[$key])) {
                throw new \InvalidArgumentException("WordPress snapshot is missing {$key}.");
            }
        }
    }

    /**
     * @param array<string, mixed> $snapshot
     * @return array<int, array<string, mixed>>
     */
    private function conflicts(array $snapshot): array
    {
        $conflicts = [];
        foreach (['products', 'variants'] as $type) {
            foreach (['slug', 'sku'] as $field) {
                if ($type === 'variants' && $field === 'slug') {
                    continue;
                }
                $seen = [];
                foreach ($snapshot[$type] as $row) {
                    $value = strtolower(trim((string) ($row[$field] ?? '')));
                    if ($value === '') {
                        continue;
                    }
                    if (isset($seen[$value])) {
                        $conflicts[] = [
                            'code' => "duplicate_{$type}_{$field}",
                            'value' => $value,
                            'external_ids' => [$seen[$value], (string) $row['external_id']],
                        ];
                    }
                    $seen[$value] = (string) $row['external_id'];
                }
            }
        }

        return $conflicts;
    }

    /**
     * @param array<string, mixed> $report
     */
    private function media(string $relativePath, array &$report): ?string
    {
        if ($relativePath === '') {
            return null;
        }
        if ($this->mediaMigrator === null) {
            throw new \RuntimeException('Product media migrator is unavailable.');
        }
        $result = $this->mediaMigrator->migrate($relativePath);
        $report['result']['images'][$result['copied'] ? 'created' : 'unchanged']++;

        return $result['url'];
    }

    private function hash(array $row): string
    {
        return hash('sha256', $this->repository->json($row));
    }

    private function contentStatus(string $status): string
    {
        return match ($status) {
            'publish' => 'published',
            'private' => 'archived',
            default => 'draft',
        };
    }

    private function stockStatus(string $status): string
    {
        return match ($status) {
            'outofstock' => 'out_of_stock',
            'onbackorder' => 'backorder',
            default => 'in_stock',
        };
    }

    /**
     * @return array<string, array<string, int>>
     */
    private function emptyCounters(): array
    {
        $counters = [];
        foreach (['stores', 'tax_classes', 'categories', 'attributes', 'products', 'variants', 'images'] as $type) {
            $counters[$type] = ['created' => 0, 'updated' => 0, 'unchanged' => 0];
        }

        return $counters;
    }
}
