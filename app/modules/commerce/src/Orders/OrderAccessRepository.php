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
        foreach (['id', 'subtotal_minor', 'discount_minor', 'shipping_minor', 'net_minor', 'tax_minor', 'total_minor'] as $field) {
            $order[$field] = (int) $order[$field];
        }
        $items = $this->pdo->prepare(
            'SELECT id, product_name, variant_name, sku, quantity, unit_price_minor,
                    discount_minor, net_minor, tax_minor, total_minor, tax_rate_bps,
                    options_json, requires_files
             FROM commerce_order_items WHERE order_id = ? ORDER BY id'
        );
        $items->execute([$orderId]);
        $order['items'] = array_map(static function (array $row): array {
            foreach (['id', 'quantity', 'unit_price_minor', 'discount_minor', 'net_minor', 'tax_minor', 'total_minor', 'tax_rate_bps'] as $field) {
                $row[$field] = (int) $row[$field];
            }
            $row['requires_files'] = (bool) $row['requires_files'];
            $decoded = json_decode((string) ($row['options_json'] ?? ''), true);
            $row['options'] = is_array($decoded) ? $decoded : [];
            unset($row['options_json']);

            return $row;
        }, $items->fetchAll(PDO::FETCH_ASSOC));

        return $order;
    }
}
