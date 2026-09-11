<?php

declare(strict_types=1);

namespace Reklamova\Cms\Commerce\Customers;

use PDO;
use Reklamova\Cms\Commerce\Orders\OrderAccessRepository;

final class CustomerAccountRepository
{
    public function __construct(private PDO $pdo, private string $storeCode)
    {
    }

    /** @return array<int, array<string, mixed>> */
    public function orders(int $customerId, int $limit = 50): array
    {
        if ($customerId <= 0) {
            return [];
        }
        $limit = max(1, min(100, $limit));
        $statement = $this->pdo->prepare(
            'SELECT o.id, o.order_number, o.order_status, o.payment_status, o.currency,
                    o.total_minor, o.placed_at, o.created_at
             FROM commerce_orders o
             INNER JOIN commerce_stores s ON s.id = o.store_id
             WHERE o.customer_id = ? AND s.code = ?
             ORDER BY COALESCE(o.placed_at, o.created_at) DESC, o.id DESC
             LIMIT ' . $limit
        );
        $statement->execute([$customerId, $this->storeCode]);

        return array_map(static function (array $order): array {
            $order['id'] = (int) $order['id'];
            $order['total_minor'] = (int) $order['total_minor'];
            return $order;
        }, $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array<string, mixed>|null */
    public function order(int $customerId, int $orderId): ?array
    {
        return (new OrderAccessRepository($this->pdo))->findByCustomer($orderId, $customerId, $this->storeCode);
    }
}
