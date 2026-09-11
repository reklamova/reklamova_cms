<?php

declare(strict_types=1);

namespace Reklamova\Cms\Commerce\Cart;

use PDO;

final class CartViewRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return array<string, mixed>|null */
    public function find(string $rawToken, string $storeCode): ?array
    {
        if (strlen($rawToken) < 32 || strlen($rawToken) > 500 || trim($storeCode) === '') {
            return null;
        }
        $statement = $this->pdo->prepare(
            'SELECT c.id, c.currency, c.version, c.expires_at
             FROM commerce_carts c
             INNER JOIN commerce_stores s ON s.id = c.store_id
             WHERE c.token_hash = ? AND s.code = ? AND s.status = "active" AND c.status = "active"
               AND (c.expires_at IS NULL OR c.expires_at > CURRENT_TIMESTAMP)
             LIMIT 1'
        );
        $statement->execute([hash('sha256', $rawToken), $storeCode]);
        $cart = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$cart) {
            return null;
        }

        $items = $this->pdo->prepare(
            'SELECT ci.id, ci.product_id, ci.variant_id, ci.quantity, ci.options_json,
                    p.name AS product_name, p.slug AS product_slug, p.sku AS product_sku,
                    p.status AS product_status, p.base_price_minor, p.sale_price_minor,
                    p.featured_image, p.stock_status AS product_stock_status,
                    v.name AS variant_name, v.sku AS variant_sku, v.status AS variant_status,
                    v.price_minor AS variant_price_minor, v.sale_price_minor AS variant_sale_price_minor,
                    v.stock_status AS variant_stock_status
             FROM commerce_cart_items ci
             INNER JOIN commerce_products p ON p.id = ci.product_id
             LEFT JOIN commerce_product_variants v ON v.id = ci.variant_id AND v.product_id = p.id
             WHERE ci.cart_id = ? ORDER BY ci.id'
        );
        $items->execute([(int) $cart['id']]);
        $rows = $items->fetchAll(PDO::FETCH_ASSOC);
        $definitions = $this->optionDefinitions(array_values(array_unique(array_map(
            static fn (array $row): int => (int) $row['product_id'],
            $rows,
        ))));
        $subtotal = 0;
        $quantity = 0;
        $lines = [];
        foreach ($rows as $row) {
            $variantId = $row['variant_id'] === null ? null : (int) $row['variant_id'];
            $basePrice = $variantId === null
                ? ($row['sale_price_minor'] ?? $row['base_price_minor'])
                : ($row['variant_sale_price_minor'] ?? $row['variant_price_minor']);
            $options = $this->decode((string) ($row['options_json'] ?? ''));
            [$optionLabels, $optionDelta] = $this->optionSummary(
                $options,
                $definitions[(int) $row['product_id']] ?? [],
            );
            $unitPrice = max(0, (int) ($basePrice ?? 0) + $optionDelta);
            $lineQuantity = (int) $row['quantity'];
            $lineTotal = $unitPrice * $lineQuantity;
            $subtotal += $lineTotal;
            $quantity += $lineQuantity;
            $variantAvailable = $variantId === null || (string) $row['variant_status'] === 'active';
            $lines[] = [
                'id' => (int) $row['id'],
                'product_id' => (int) $row['product_id'],
                'variant_id' => $variantId,
                'product_name' => (string) $row['product_name'],
                'product_slug' => (string) $row['product_slug'],
                'sku' => (string) (($row['variant_sku'] ?? '') ?: ($row['product_sku'] ?? '')),
                'variant_name' => $row['variant_name'] === null ? null : (string) $row['variant_name'],
                'featured_image' => $row['featured_image'] === null ? null : (string) $row['featured_image'],
                'quantity' => $lineQuantity,
                'options' => $options,
                'option_labels' => $optionLabels,
                'unit_price_minor' => $unitPrice,
                'line_total_minor' => $lineTotal,
                'available' => (string) $row['product_status'] === 'published' && $variantAvailable,
                'stock_status' => (string) ($variantId === null
                    ? $row['product_stock_status']
                    : $row['variant_stock_status']),
            ];
        }

        return [
            'id' => (int) $cart['id'],
            'currency' => (string) $cart['currency'],
            'version' => (int) $cart['version'],
            'expires_at' => $cart['expires_at'],
            'quantity' => $quantity,
            'subtotal_minor' => $subtotal,
            'items' => $lines,
        ];
    }

    /** @param array<int, int> $productIds @return array<int, array<string, array<string, mixed>>> */
    private function optionDefinitions(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        $statement = $this->pdo->prepare(
            "SELECT po.product_id, po.code AS option_code, po.name AS option_name,
                    pov.code AS value_code, pov.label AS value_label, pov.price_delta_minor
             FROM commerce_product_options po
             LEFT JOIN commerce_product_option_values pov ON pov.option_id = po.id
             WHERE po.product_id IN ({$placeholders})"
        );
        $statement->execute($productIds);
        $result = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $productId = (int) $row['product_id'];
            $optionCode = (string) $row['option_code'];
            $result[$productId][$optionCode]['name'] = (string) $row['option_name'];
            if ($row['value_code'] !== null) {
                $result[$productId][$optionCode]['values'][(string) $row['value_code']] = [
                    'label' => (string) $row['value_label'],
                    'price_delta_minor' => (int) $row['price_delta_minor'],
                ];
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $selected
     * @param array<string, array<string, mixed>> $definitions
     * @return array{0: array<int, string>, 1: int}
     */
    private function optionSummary(array $selected, array $definitions): array
    {
        $labels = [];
        $delta = 0;
        foreach ($selected as $code => $value) {
            $definition = $definitions[$code] ?? ['name' => $code, 'values' => []];
            $values = is_array($value) ? $value : [$value];
            $rendered = [];
            foreach ($values as $selectedValue) {
                $key = (string) $selectedValue;
                $known = $definition['values'][$key] ?? null;
                $rendered[] = (string) ($known['label'] ?? $key);
                $delta += (int) ($known['price_delta_minor'] ?? 0);
            }
            $labels[] = (string) $definition['name'] . ': ' . implode(', ', $rendered);
        }

        return [$labels, $delta];
    }

    /** @return array<string, mixed> */
    private function decode(string $json): array
    {
        if ($json === '') {
            return [];
        }
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : [];
    }
}
