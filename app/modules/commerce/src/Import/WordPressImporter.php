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
                'customers' => count($snapshot['customers'] ?? []),
                'coupons' => count($snapshot['coupons'] ?? []),
                'orders' => count($snapshot['orders'] ?? []),
                'order_items' => (int) ($snapshot['counts']['order_items'] ?? 0),
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
                $variants = $this->importVariants($storeId, $snapshot['variants'], $products, $runId, $report);
                $customers = $this->importCustomers($storeId, $snapshot['customers'] ?? [], $runId, $report);
                $this->importCoupons($storeId, $snapshot['coupons'] ?? [], $runId, $report);
                $this->importOrders(
                    $storeId,
                    $snapshot['orders'] ?? [],
                    $products,
                    $variants,
                    $customers,
                    $runId,
                    $report,
                );
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
    ): array {
        $imported = [];
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
            $imported[$externalId] = (int) $localId;
        }

        return $imported;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, mixed> $report
     * @return array<string, int>
     */
    private function importCustomers(int $storeId, array $rows, int $runId, array &$report): array
    {
        $imported = [];
        foreach ($rows as $row) {
            $externalId = (string) $row['external_id'];
            $hash = $this->hash($row);
            $mapping = $this->repository->mapping($storeId, 'customer', $externalId);
            $state = $this->state($mapping, $hash);
            $localId = $mapping['local_id'] ?? null;
            if ($state !== 'unchanged') {
                $localId = $this->repository->saveCustomer($storeId, $row, $localId);
                $this->repository->replaceCustomerAddresses((int) $localId, $row['addresses'] ?? []);
                $this->repository->saveMapping(
                    $storeId,
                    'customer',
                    $externalId,
                    'customer',
                    (int) $localId,
                    $hash,
                    $runId,
                );
            }
            $report['result']['customers'][$state]++;
            $imported[$externalId] = (int) $localId;
        }

        return $imported;
    }

    /** @param array<int, array<string, mixed>> $rows @param array<string, mixed> $report */
    private function importCoupons(int $storeId, array $rows, int $runId, array &$report): void
    {
        foreach ($rows as $row) {
            $externalId = (string) $row['external_id'];
            $hash = $this->hash($row);
            $mapping = $this->repository->mapping($storeId, 'coupon', $externalId);
            $state = $this->state($mapping, $hash);
            $localId = $mapping['local_id'] ?? null;
            if ($state !== 'unchanged') {
                $rules = array_filter([
                    'individual_use' => !empty($row['individual_use']) ?: null,
                    'exclude_sale_items' => !empty($row['exclude_sale_items']) ?: null,
                    'free_shipping' => !empty($row['free_shipping']) ?: null,
                ], static fn (mixed $value): bool => $value !== null);
                $type = (string) ($row['type'] ?? 'percent');
                $localId = $this->repository->saveCoupon($storeId, [
                    'code' => strtoupper((string) $row['code']),
                    'type' => $type === 'percent' ? 'percentage' : $type,
                    'value_minor' => $row['value_minor'] ?? null,
                    'value_bps' => $row['value_bps'] ?? null,
                    'minimum_minor' => $row['minimum_minor'] ?? null,
                    'maximum_discount_minor' => $row['maximum_discount_minor'] ?? null,
                    'usage_limit' => $row['usage_limit'] ?? null,
                    'usage_limit_per_customer' => $row['usage_limit_per_customer'] ?? null,
                    'starts_at' => $row['starts_at'] ?? null,
                    'ends_at' => $row['ends_at'] ?? null,
                    'active' => (string) ($row['status'] ?? '') === 'publish' ? 1 : 0,
                    'rules_json' => $this->repository->json($rules),
                ], $localId);
                $this->repository->saveMapping(
                    $storeId,
                    'coupon',
                    $externalId,
                    'coupon',
                    (int) $localId,
                    $hash,
                    $runId,
                );
            }
            $report['result']['coupons'][$state]++;
        }
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, int> $products
     * @param array<string, int> $variants
     * @param array<string, int> $customers
     * @param array<string, mixed> $report
     */
    private function importOrders(
        int $storeId,
        array $rows,
        array $products,
        array $variants,
        array $customers,
        int $runId,
        array &$report,
    ): void {
        foreach ($rows as $row) {
            $externalId = (string) $row['external_id'];
            $hash = $this->hash($row);
            $mapping = $this->repository->mapping($storeId, 'order', $externalId);
            $state = $this->state($mapping, $hash);
            $localId = $mapping['local_id'] ?? null;
            if ($state !== 'unchanged') {
                $sourceStatus = (string) $row['source_status'];
                $orderStatus = $this->orderStatus($sourceStatus);
                $paymentStatus = $this->paymentStatus($sourceStatus, $row['paid_at'] ?? null);
                $customerExternalId = $row['customer_external_id'] ?? null;
                $couponCodes = array_values(array_filter(array_map('strval', $row['coupon_codes'] ?? [])));
                $localId = $this->repository->saveOrder($storeId, [
                    'customer_id' => $customerExternalId !== null ? ($customers[(string) $customerExternalId] ?? null) : null,
                    'order_number' => (string) $row['order_number'],
                    'order_status' => $orderStatus,
                    'payment_status' => $paymentStatus,
                    'currency' => (string) $row['currency'],
                    'subtotal_minor' => (int) $row['subtotal_minor'],
                    'discount_minor' => (int) $row['discount_minor'],
                    'shipping_minor' => (int) $row['shipping_minor'],
                    'net_minor' => (int) $row['net_minor'],
                    'tax_minor' => (int) $row['tax_minor'],
                    'total_minor' => (int) $row['total_minor'],
                    'customer_email' => (string) $row['customer_email'],
                    'customer_phone' => $row['customer_phone'],
                    'billing_address_json' => $this->repository->json($row['billing_address'] ?? []),
                    'shipping_address_json' => $this->repository->json($row['shipping_address'] ?? []),
                    'shipping_method_code' => $row['shipping_method_code'],
                    'shipping_method_name' => $row['shipping_method_name'],
                    'pickup_point_json' => empty($row['pickup_point'])
                        ? null
                        : $this->repository->json($row['pickup_point']),
                    'payment_method_code' => $this->paymentProviderCode((string) ($row['payment_method_code'] ?? '')),
                    'coupon_code' => $couponCodes[0] ?? null,
                    'customer_note' => $row['customer_note'],
                    'internal_note' => 'Imported from WooCommerce order ' . $externalId,
                    'placed_at' => $row['created_at'],
                    'paid_at' => $row['paid_at'],
                    'cancelled_at' => $orderStatus === 'cancelled' ? $row['updated_at'] : null,
                    'completed_at' => $row['completed_at'],
                    'created_at' => $row['created_at'],
                    'updated_at' => $row['updated_at'],
                ], $localId);
                $items = [];
                foreach ($row['items'] ?? [] as $item) {
                    $productExternalId = (string) ($item['product_external_id'] ?? '');
                    $variantExternalId = $item['variant_external_id'] ?? null;
                    $netMinor = (int) ($item['net_minor'] ?? 0);
                    $taxMinor = (int) ($item['tax_minor'] ?? 0);
                    $taxRate = $netMinor > 0
                        ? intdiv($taxMinor * 10000 + intdiv($netMinor, 2), $netMinor)
                        : 0;
                    $items[] = [
                        'product_id' => $products[$productExternalId] ?? null,
                        'variant_id' => $variantExternalId !== null ? ($variants[(string) $variantExternalId] ?? null) : null,
                        'product_name' => (string) $item['name'],
                        'variant_name' => null,
                        'sku' => null,
                        'quantity' => (int) $item['quantity'],
                        'unit_price_minor' => (int) $item['unit_price_minor'],
                        'discount_minor' => (int) $item['discount_minor'],
                        'net_minor' => $netMinor,
                        'tax_minor' => $taxMinor,
                        'total_minor' => (int) $item['total_minor'],
                        'tax_rate_bps' => $taxRate,
                        'options_json' => $this->repository->json($item['attributes'] ?? []),
                        'product_snapshot_json' => $this->repository->json([
                            'source' => 'woocommerce',
                            'external_order_item_id' => (string) $item['external_id'],
                            'external_product_id' => $productExternalId,
                            'external_variant_id' => $variantExternalId,
                            'subtotal_minor' => (int) $item['subtotal_minor'],
                            'subtotal_tax_minor' => (int) $item['subtotal_tax_minor'],
                        ]),
                        'requires_files' => false,
                    ];
                }
                $this->repository->replaceOrderItems((int) $localId, $items);
                if (!empty($row['provider_transaction_id'])) {
                    $this->repository->upsertImportedPayment((int) $localId, [
                        'provider' => $this->paymentProviderCode((string) ($row['payment_method_code'] ?? '')),
                        'idempotency_key' => 'woocommerce-order-' . $externalId,
                        'provider_transaction_id' => (string) $row['provider_transaction_id'],
                        'status' => $paymentStatus,
                        'amount_minor' => (int) $row['total_minor'],
                        'currency' => (string) $row['currency'],
                        'settled_at' => $row['paid_at'],
                    ]);
                }
                $this->repository->saveMapping(
                    $storeId,
                    'order',
                    $externalId,
                    'order',
                    (int) $localId,
                    $hash,
                    $runId,
                );
            }
            $report['result']['orders'][$state]++;
            $report['result']['order_items'][$state] += count($row['items'] ?? []);
        }
    }

    private function orderStatus(string $sourceStatus): string
    {
        return match ($sourceStatus) {
            'wc-completed' => 'completed',
            'wc-processing' => 'in_production',
            'wc-cancelled', 'wc-failed', 'wc-refunded' => 'cancelled',
            default => 'new',
        };
    }

    private function paymentStatus(string $sourceStatus, mixed $paidAt): string
    {
        if ($paidAt !== null || in_array($sourceStatus, ['wc-completed', 'wc-processing'], true)) {
            return 'paid';
        }

        return match ($sourceStatus) {
            'wc-cancelled' => 'cancelled',
            'wc-failed' => 'failed',
            'wc-refunded' => 'refunded',
            default => 'unpaid',
        };
    }

    private function paymentProviderCode(string $source): string
    {
        $source = strtolower(trim($source));

        return in_array($source, ['imoje', 'ing', 'ing_pay'], true) ? 'ing_pay' : ($source ?: 'legacy');
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
        foreach ([
            'stores', 'tax_classes', 'categories', 'attributes', 'products', 'variants', 'images',
            'customers', 'coupons', 'orders', 'order_items',
        ] as $type) {
            $counters[$type] = ['created' => 0, 'updated' => 0, 'unchanged' => 0];
        }

        return $counters;
    }
}
