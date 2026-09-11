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
        $customers = $this->customers();
        $coupons = $this->coupons();
        $orders = $this->orders();
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
            'customers' => $customers,
            'coupons' => $coupons,
            'orders' => $orders,
            'counts' => [
                'categories' => count($categories),
                'attributes' => count($attributes),
                'tax_rates' => count($taxRates),
                'products' => count($products),
                'published_products' => count(array_filter($products, static fn (array $row): bool => $row['status'] === 'publish')),
                'variants' => count($variants),
                'published_variants' => count(array_filter($variants, static fn (array $row): bool => $row['status'] === 'publish')),
                'images' => count(array_unique(array_filter($images))),
                'customers' => count($customers),
                'coupons' => count($coupons),
                'orders' => count($orders),
                'order_items' => array_sum(array_map(
                    static fn (array $order): int => count($order['items'] ?? []),
                    $orders,
                )),
            ],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function customers(): array
    {
        $rows = $this->pdo->query(
            "SELECT DISTINCT u.ID, u.user_email, u.display_name, u.user_registered
             FROM {$this->prefix}users u
             INNER JOIN {$this->prefix}postmeta pm
                ON CAST(pm.meta_value AS UNSIGNED) = u.ID AND pm.meta_key = '_customer_user'
             INNER JOIN {$this->prefix}posts p ON p.ID = pm.post_id AND p.post_type = 'shop_order'
             WHERE u.user_email <> '' ORDER BY u.ID"
        )->fetchAll(PDO::FETCH_ASSOC);
        if ($rows === []) {
            return [];
        }
        $ids = array_map(static fn (array $row): int => (int) $row['ID'], $rows);
        $list = implode(',', $ids);
        $metaRows = $this->pdo->query(
            "SELECT user_id, meta_key, meta_value FROM {$this->prefix}usermeta
             WHERE user_id IN ({$list}) AND meta_key IN (
                'first_name', 'last_name', 'billing_first_name', 'billing_last_name', 'billing_company',
                'billing_vat_number', 'billing_nip', 'billing_address_1', 'billing_address_2', 'billing_postcode',
                'billing_city', 'billing_country', 'billing_phone', 'shipping_first_name', 'shipping_last_name',
                'shipping_company', 'shipping_address_1', 'shipping_address_2', 'shipping_postcode',
                'shipping_city', 'shipping_country', 'shipping_phone'
             )"
        )->fetchAll(PDO::FETCH_ASSOC);
        $meta = [];
        foreach ($metaRows as $row) {
            $meta[(int) $row['user_id']][(string) $row['meta_key']] = (string) $row['meta_value'];
        }

        return array_map(function (array $row) use ($meta): array {
            $userMeta = $meta[(int) $row['ID']] ?? [];
            $firstName = $this->firstNonEmpty($userMeta, ['billing_first_name', 'first_name']);
            $lastName = $this->firstNonEmpty($userMeta, ['billing_last_name', 'last_name']);

            return [
                'external_id' => (string) $row['ID'],
                'email' => strtolower((string) $row['user_email']),
                'first_name' => $firstName,
                'last_name' => $lastName,
                'display_name' => (string) $row['display_name'],
                'phone' => $this->nullableString($userMeta['billing_phone'] ?? null),
                'company' => $this->nullableString($userMeta['billing_company'] ?? null),
                'tax_id' => $this->nullableString($userMeta['billing_vat_number'] ?? $userMeta['billing_nip'] ?? null),
                'registered_at' => $this->date($row['user_registered'] ?? null),
                'password_reset_required' => true,
                'addresses' => array_values(array_filter([
                    $this->customerAddress($userMeta, 'billing'),
                    $this->customerAddress($userMeta, 'shipping'),
                ])),
            ];
        }, $rows);
    }

    /** @return array<int, array<string, mixed>> */
    public function coupons(): array
    {
        $posts = $this->pdo->query(
            "SELECT ID, post_title, post_status, post_date_gmt
             FROM {$this->prefix}posts
             WHERE post_type = 'shop_coupon' AND post_status NOT IN ('trash', 'auto-draft')
             ORDER BY ID"
        )->fetchAll(PDO::FETCH_ASSOC);
        if ($posts === []) {
            return [];
        }
        $meta = $this->metaForPostIds(array_map(static fn (array $row): int => (int) $row['ID'], $posts));

        return array_map(function (array $post) use ($meta): array {
            $postMeta = $meta[(int) $post['ID']] ?? [];
            $type = (string) ($postMeta['discount_type'] ?? 'percent');
            $amount = $postMeta['coupon_amount'] ?? '0';

            return [
                'external_id' => (string) $post['ID'],
                'code' => strtoupper(trim((string) $post['post_title'])),
                'status' => (string) $post['post_status'],
                'type' => $type,
                'value_minor' => $type === 'fixed_cart' ? ($this->money->parse($amount) ?? 0) : null,
                'value_bps' => $type === 'percent' ? ($this->money->parse($amount) ?? 0) : null,
                'minimum_minor' => $this->money->parse($postMeta['minimum_amount'] ?? null),
                'maximum_discount_minor' => $this->money->parse($postMeta['maximum_amount'] ?? null),
                'usage_limit' => $this->positiveIntOrNull($postMeta['usage_limit'] ?? null),
                'usage_limit_per_customer' => $this->positiveIntOrNull($postMeta['usage_limit_per_user'] ?? null),
                'usage_count' => (int) ($postMeta['usage_count'] ?? 0),
                'starts_at' => $this->date($post['post_date_gmt'] ?? null),
                'ends_at' => $this->unixDate($postMeta['date_expires'] ?? null),
                'individual_use' => ($postMeta['individual_use'] ?? 'no') === 'yes',
                'exclude_sale_items' => ($postMeta['exclude_sale_items'] ?? 'no') === 'yes',
                'free_shipping' => ($postMeta['free_shipping'] ?? 'no') === 'yes',
            ];
        }, $posts);
    }

    /** @return array<int, array<string, mixed>> */
    public function orders(): array
    {
        $posts = $this->pdo->query(
            "SELECT ID, post_status, post_date_gmt, post_modified_gmt, post_excerpt
             FROM {$this->prefix}posts
             WHERE post_type = 'shop_order' AND post_status NOT IN ('trash', 'auto-draft')
             ORDER BY ID"
        )->fetchAll(PDO::FETCH_ASSOC);
        if ($posts === []) {
            return [];
        }
        $ids = array_map(static fn (array $row): int => (int) $row['ID'], $posts);
        $meta = $this->metaForPostIds($ids);
        $items = $this->orderItems($ids);

        return array_map(function (array $post) use ($meta, $items): array {
            $id = (int) $post['ID'];
            $postMeta = $meta[$id] ?? [];
            $orderItems = $items[$id] ?? [];
            $lines = array_values(array_filter($orderItems, static fn (array $item): bool => $item['type'] === 'line_item'));
            $shipping = array_values(array_filter($orderItems, static fn (array $item): bool => $item['type'] === 'shipping'));
            $coupons = array_values(array_filter($orderItems, static fn (array $item): bool => $item['type'] === 'coupon'));
            $lineSubtotal = 0;
            foreach ($lines as $line) {
                $lineSubtotal += (int) $line['subtotal_minor'] + (int) $line['subtotal_tax_minor'];
            }
            $discount = ($this->money->parse($postMeta['_cart_discount'] ?? '0') ?? 0)
                + ($this->money->parse($postMeta['_cart_discount_tax'] ?? '0') ?? 0);
            $shippingMinor = ($this->money->parse($postMeta['_order_shipping'] ?? '0') ?? 0)
                + ($this->money->parse($postMeta['_order_shipping_tax'] ?? '0') ?? 0);
            $taxMinor = ($this->money->parse($postMeta['_order_tax'] ?? '0') ?? 0)
                + ($this->money->parse($postMeta['_order_shipping_tax'] ?? '0') ?? 0);
            $totalMinor = $this->money->parse($postMeta['_order_total'] ?? '0') ?? 0;
            $subtotalMinor = $lineSubtotal > 0 ? $lineSubtotal : max(0, $totalMinor + $discount - $shippingMinor);
            $shippingItem = $shipping[0] ?? null;

            return [
                'external_id' => (string) $id,
                'order_number' => (string) $id,
                'source_status' => (string) $post['post_status'],
                'currency' => strtoupper((string) ($postMeta['_order_currency'] ?? 'PLN')),
                'customer_external_id' => (int) ($postMeta['_customer_user'] ?? 0) > 0
                    ? (string) $postMeta['_customer_user']
                    : null,
                'customer_email' => strtolower((string) ($postMeta['_billing_email'] ?? '')),
                'customer_phone' => $this->nullableString($postMeta['_billing_phone'] ?? null),
                'billing_address' => $this->orderAddress($postMeta, 'billing'),
                'shipping_address' => $this->orderAddress($postMeta, 'shipping'),
                'subtotal_minor' => $subtotalMinor,
                'discount_minor' => $discount,
                'shipping_minor' => $shippingMinor,
                'net_minor' => max(0, $totalMinor - $taxMinor),
                'tax_minor' => $taxMinor,
                'total_minor' => $totalMinor,
                'shipping_method_code' => $shippingItem['method_id'] ?? null,
                'shipping_method_name' => $shippingItem['name'] ?? null,
                'pickup_point' => $this->pickupPoint($postMeta, $shippingItem),
                'payment_method_code' => $this->nullableString($postMeta['_payment_method'] ?? null),
                'payment_method_name' => $this->nullableString($postMeta['_payment_method_title'] ?? null),
                'provider_transaction_id' => $this->nullableString($postMeta['imoje_transaction_uuid'] ?? null),
                'coupon_codes' => array_values(array_map(static fn (array $item): string => (string) $item['name'], $coupons)),
                'customer_note' => $this->nullableString($post['post_excerpt'] ?? null),
                'created_at' => $this->date($post['post_date_gmt'] ?? null),
                'updated_at' => $this->date($post['post_modified_gmt'] ?? null),
                'paid_at' => $this->date($postMeta['_date_paid'] ?? $postMeta['_paid_date'] ?? null),
                'completed_at' => $this->date($postMeta['_date_completed'] ?? $postMeta['_completed_date'] ?? null),
                'items' => $lines,
                'source_meta' => [
                    'created_via' => (string) ($postMeta['_created_via'] ?? ''),
                    'apaczka_present' => isset($postMeta['_apaczka']),
                ],
            ];
        }, $posts);
    }

    /** @param array<int, int> $orderIds @return array<int, array<int, array<string, mixed>>> */
    private function orderItems(array $orderIds): array
    {
        $list = implode(',', array_map('intval', $orderIds));
        $rows = $this->pdo->query(
            "SELECT oi.order_id, oi.order_item_id, oi.order_item_name, oi.order_item_type,
                    oim.meta_key, oim.meta_value
             FROM {$this->prefix}woocommerce_order_items oi
             LEFT JOIN {$this->prefix}woocommerce_order_itemmeta oim ON oim.order_item_id = oi.order_item_id
             WHERE oi.order_id IN ({$list}) ORDER BY oi.order_id, oi.order_item_id, oim.meta_id"
        )->fetchAll(PDO::FETCH_ASSOC);
        $raw = [];
        foreach ($rows as $row) {
            $orderId = (int) $row['order_id'];
            $itemId = (int) $row['order_item_id'];
            $raw[$orderId][$itemId] ??= [
                'external_id' => (string) $itemId,
                'name' => (string) $row['order_item_name'],
                'type' => (string) $row['order_item_type'],
                'meta' => [],
            ];
            if ($row['meta_key'] !== null) {
                $raw[$orderId][$itemId]['meta'][(string) $row['meta_key']] = (string) $row['meta_value'];
            }
        }
        $result = [];
        foreach ($raw as $orderId => $orderRows) {
            foreach ($orderRows as $item) {
                $itemMeta = $item['meta'];
                if ($item['type'] === 'line_item') {
                    $quantity = max(1, (int) ($itemMeta['_qty'] ?? 1));
                    $total = $this->money->parse($itemMeta['_line_total'] ?? '0') ?? 0;
                    $tax = $this->money->parse($itemMeta['_line_tax'] ?? '0') ?? 0;
                    $subtotal = $this->money->parse($itemMeta['_line_subtotal'] ?? '0') ?? 0;
                    $subtotalTax = $this->lineSubtotalTax($itemMeta);
                    $gross = $total + $tax;
                    $unit = intdiv($gross + intdiv($quantity, 2), $quantity);
                    $attributes = [];
                    foreach ($itemMeta as $key => $value) {
                        if (!str_starts_with($key, '_') && !in_array($key, ['Adres', 'Numer telefonu'], true)) {
                            $attributes[$key] = $value;
                        }
                    }
                    $result[$orderId][] = $item + [
                        'product_external_id' => (string) ($itemMeta['_product_id'] ?? '0'),
                        'variant_external_id' => (int) ($itemMeta['_variation_id'] ?? 0) > 0
                            ? (string) $itemMeta['_variation_id']
                            : null,
                        'quantity' => $quantity,
                        'unit_price_minor' => $unit,
                        'subtotal_minor' => $subtotal,
                        'subtotal_tax_minor' => $subtotalTax,
                        'net_minor' => $total,
                        'tax_minor' => $tax,
                        'total_minor' => $gross,
                        'discount_minor' => max(0, ($subtotal + $subtotalTax) - $gross),
                        'tax_class' => (string) ($itemMeta['_tax_class'] ?? ''),
                        'attributes' => $attributes,
                    ];
                    continue;
                }
                $result[$orderId][] = $item + [
                    'method_id' => $this->nullableString($itemMeta['method_id'] ?? null),
                    'instance_id' => $this->nullableString($itemMeta['instance_id'] ?? null),
                    'cost_minor' => $this->money->parse($itemMeta['cost'] ?? '0') ?? 0,
                    'tax_minor' => $this->money->parse($itemMeta['total_tax'] ?? '0') ?? 0,
                    'pickup_location' => $this->nullableString($itemMeta['pickup_location'] ?? null),
                    'pickup_address' => $this->nullableString($itemMeta['pickup_address'] ?? null),
                    'phone' => $this->nullableString($itemMeta['Numer telefonu'] ?? null),
                ];
            }
        }

        return $result;
    }

    /** @param array<string, string> $meta */
    private function lineSubtotalTax(array $meta): int
    {
        $taxData = $meta['_line_tax_data'] ?? null;
        if (is_string($taxData) && $taxData !== '') {
            $decoded = @unserialize($taxData, ['allowed_classes' => false]);
            if (is_array($decoded['subtotal'] ?? null)) {
                $sum = 0;
                foreach ($decoded['subtotal'] as $amount) {
                    $sum += $this->money->parse($amount) ?? 0;
                }

                return $sum;
            }
        }

        return $this->money->parse($meta['_line_tax'] ?? '0') ?? 0;
    }

    /** @param array<int, int> $ids @return array<int, array<string, string>> */
    private function metaForPostIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $list = implode(',', array_map('intval', $ids));
        $rows = $this->pdo->query(
            "SELECT post_id, meta_key, meta_value FROM {$this->prefix}postmeta
             WHERE post_id IN ({$list}) ORDER BY meta_id"
        )->fetchAll(PDO::FETCH_ASSOC);
        $meta = [];
        foreach ($rows as $row) {
            $meta[(int) $row['post_id']][(string) $row['meta_key']] = (string) $row['meta_value'];
        }

        return $meta;
    }

    /** @param array<string, string> $meta @return array<string, string|null>|null */
    private function customerAddress(array $meta, string $type): ?array
    {
        $line1 = trim((string) ($meta[$type . '_address_1'] ?? ''));
        $postal = trim((string) ($meta[$type . '_postcode'] ?? ''));
        $city = trim((string) ($meta[$type . '_city'] ?? ''));
        if ($line1 === '' || $postal === '' || $city === '') {
            return null;
        }

        return [
            'type' => $type,
            'first_name' => $this->nullableString($meta[$type . '_first_name'] ?? null),
            'last_name' => $this->nullableString($meta[$type . '_last_name'] ?? null),
            'company' => $this->nullableString($meta[$type . '_company'] ?? null),
            'tax_id' => $type === 'billing'
                ? $this->nullableString($meta['billing_vat_number'] ?? $meta['billing_nip'] ?? null)
                : null,
            'address_line1' => $line1,
            'address_line2' => $this->nullableString($meta[$type . '_address_2'] ?? null),
            'postal_code' => $postal,
            'city' => $city,
            'country_code' => strtoupper((string) ($meta[$type . '_country'] ?? 'PL')),
            'phone' => $this->nullableString($meta[$type . '_phone'] ?? null),
        ];
    }

    /** @param array<string, string> $meta @return array<string, string|null> */
    private function orderAddress(array $meta, string $type): array
    {
        return [
            'first_name' => $this->nullableString($meta['_' . $type . '_first_name'] ?? null),
            'last_name' => $this->nullableString($meta['_' . $type . '_last_name'] ?? null),
            'company' => $this->nullableString($meta['_' . $type . '_company'] ?? null),
            'tax_id' => $type === 'billing' ? $this->nullableString($meta['_billing_vat_number'] ?? null) : null,
            'address_line1' => $this->nullableString($meta['_' . $type . '_address_1'] ?? null),
            'address_line2' => $this->nullableString($meta['_' . $type . '_address_2'] ?? null),
            'postal_code' => $this->nullableString($meta['_' . $type . '_postcode'] ?? null),
            'city' => $this->nullableString($meta['_' . $type . '_city'] ?? null),
            'country_code' => strtoupper((string) ($meta['_' . $type . '_country'] ?? 'PL')),
            'phone' => $this->nullableString($meta['_' . $type . '_phone'] ?? null),
        ];
    }

    /** @param array<string, string> $meta @param array<string, mixed>|null $shipping @return array<string, mixed>|null */
    private function pickupPoint(array $meta, ?array $shipping): ?array
    {
        $code = $this->nullableString($meta['apaczka_delivery_point'] ?? $shipping['pickup_location'] ?? null);
        $address = $this->nullableString($shipping['pickup_address'] ?? null);
        if ($code === null && $address === null) {
            return null;
        }

        return ['code' => $code, 'address' => $address, 'provider' => 'apaczka'];
    }

    /** @param array<string, string> $meta @param array<int, string> $keys */
    private function firstNonEmpty(array $meta, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $this->nullableString($meta[$key] ?? null);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    private function date(mixed $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '' || str_starts_with($value, '0000-00-00')) {
            return null;
        }
        if (preg_match('/^\d+$/', $value) === 1) {
            return gmdate('Y-m-d H:i:s', (int) $value);
        }
        $timestamp = strtotime($value . ' UTC');

        return $timestamp === false ? null : gmdate('Y-m-d H:i:s', $timestamp);
    }

    private function unixDate(mixed $value): ?string
    {
        $timestamp = (int) $value;

        return $timestamp > 0 ? gmdate('Y-m-d H:i:s', $timestamp) : null;
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

    private function positiveIntOrNull(mixed $value): ?int
    {
        $value = $this->nullableInt($value);

        return $value !== null && $value > 0 ? $value : null;
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
