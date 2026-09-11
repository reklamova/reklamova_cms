<?php

declare(strict_types=1);

namespace Reklamova\Cms\Commerce\Orders;

use PDO;

final class OrderAccessRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return array<string, mixed>|null */
    public function findByCheckoutToken(int $orderId, string $rawToken, string $storeCode): ?array
    {
        if ($orderId <= 0 || strlen($rawToken) < 16 || strlen($rawToken) > 500 || trim($storeCode) === '') {
            return null;
        }
        $statement = $this->pdo->prepare(
            'SELECT o.id, o.order_number, o.order_status, o.payment_status, o.currency,
                    o.subtotal_minor, o.discount_minor, o.shipping_minor, o.net_minor,
                    o.tax_minor, o.total_minor, o.shipping_method_name, o.payment_method_code,
                    o.coupon_code, o.placed_at, o.paid_at
             FROM commerce_orders o
             INNER JOIN commerce_stores s ON s.id = o.store_id
             WHERE o.id = ? AND o.checkout_key = ? AND s.code = ? LIMIT 1'
        );
        $statement->execute([$orderId, hash('sha256', $rawToken), $storeCode]);
        $order = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$order) {
            return null;
        }
        return $this->hydrateOrder($order);
    }

    /** @return array<string, mixed>|null */
    public function findByCustomer(int $orderId, int $customerId, string $storeCode): ?array
    {
        if ($orderId <= 0 || $customerId <= 0 || trim($storeCode) === '') {
            return null;
        }
        $statement = $this->pdo->prepare(
            'SELECT o.id, o.order_number, o.order_status, o.payment_status, o.currency,
                    o.subtotal_minor, o.discount_minor, o.shipping_minor, o.net_minor,
                    o.tax_minor, o.total_minor, o.shipping_method_name, o.payment_method_code,
                    o.coupon_code, o.placed_at, o.paid_at
             FROM commerce_orders o
             INNER JOIN commerce_stores s ON s.id = o.store_id
             INNER JOIN commerce_customers c ON c.id = o.customer_id AND c.store_id = o.store_id
             WHERE o.id = ? AND o.customer_id = ? AND c.status = "active" AND s.code = ? LIMIT 1'
        );
        $statement->execute([$orderId, $customerId, $storeCode]);
        $order = $statement->fetch(PDO::FETCH_ASSOC);

        return $order ? $this->hydrateOrder($order) : null;
    }

    /** @param array<string, mixed> $order @return array<string, mixed> */
    private function hydrateOrder(array $order): array
    {
        $orderId = (int) $order['id'];
        foreach (['id', 'subtotal_minor', 'discount_minor', 'shipping_minor', 'net_minor', 'tax_minor', 'total_minor'] as $field) {
            $order[$field] = (int) $order[$field];
        }
        $items = $this->pdo->prepare(
            'SELECT id, product_id, product_name, variant_name, sku, quantity, unit_price_minor,
                    discount_minor, net_minor, tax_minor, total_minor, tax_rate_bps,
                    options_json, requires_files
             FROM commerce_order_items WHERE order_id = ? ORDER BY id'
        );
        $items->execute([$orderId]);
        $order['items'] = array_map(static function (array $row): array {
            foreach (['id', 'product_id', 'quantity', 'unit_price_minor', 'discount_minor', 'net_minor', 'tax_minor', 'total_minor', 'tax_rate_bps'] as $field) {
                $row[$field] = (int) $row[$field];
            }
            $row['requires_files'] = (bool) $row['requires_files'];
            $decoded = json_decode((string) ($row['options_json'] ?? ''), true);
            $row['options'] = is_array($decoded) ? $decoded : [];
            unset($row['options_json']);

            return $row;
        }, $items->fetchAll(PDO::FETCH_ASSOC));
        $productIds = array_values(array_unique(array_filter(array_map(
            static fn (array $item): int => (int) $item['product_id'],
            $order['items'],
        ))));
        $requirements = [];
        if ($productIds !== []) {
            $placeholders = implode(',', array_fill(0, count($productIds), '?'));
            $requirementStatement = $this->pdo->prepare(
                "SELECT id, product_id, code, label, required, upload_timing,
                        allowed_extensions_json, max_bytes, max_files
                 FROM commerce_product_file_requirements
                 WHERE product_id IN ({$placeholders}) ORDER BY sort_order, id"
            );
            $requirementStatement->execute($productIds);
            foreach ($requirementStatement->fetchAll(PDO::FETCH_ASSOC) as $requirement) {
                $requirement['id'] = (int) $requirement['id'];
                $requirement['product_id'] = (int) $requirement['product_id'];
                $requirement['required'] = (bool) $requirement['required'];
                $requirement['max_bytes'] = (int) $requirement['max_bytes'];
                $requirement['max_files'] = (int) $requirement['max_files'];
                $decoded = json_decode((string) $requirement['allowed_extensions_json'], true);
                $requirement['allowed_extensions'] = is_array($decoded) ? $decoded : [];
                unset($requirement['allowed_extensions_json']);
                $requirements[$requirement['product_id']][] = $requirement;
            }
        }
        $fileStatement = $this->pdo->prepare(
            'SELECT id, order_item_id, requirement_id, original_name, mime_type, size_bytes,
                    status, scan_status, created_at
             FROM commerce_order_files WHERE order_id = ? AND status <> "deleted" ORDER BY id'
        );
        $fileStatement->execute([$orderId]);
        $files = [];
        foreach ($fileStatement->fetchAll(PDO::FETCH_ASSOC) as $file) {
            foreach (['id', 'order_item_id', 'requirement_id', 'size_bytes'] as $field) {
                $file[$field] = (int) $file[$field];
            }
            $files[$file['order_item_id']][] = $file;
        }
        foreach ($order['items'] as &$item) {
            $item['file_requirements'] = $requirements[$item['product_id']] ?? [];
            $item['files'] = $files[$item['id']] ?? [];
        }

        return $order;
    }
}
