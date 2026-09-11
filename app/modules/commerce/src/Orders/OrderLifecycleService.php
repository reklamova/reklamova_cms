<?php

declare(strict_types=1);

namespace Reklamova\Cms\Commerce\Orders;

use PDO;
use Reklamova\Cms\Commerce\Notifications\NotificationOutbox;

final class OrderLifecycleService
{
    public function __construct(private PDO $pdo, private ?StatusTransitionGuard $guard = null)
    {
        $this->guard ??= new StatusTransitionGuard();
    }

    public function changeOrderStatus(
        int $orderId,
        int $storeId,
        OrderStatus $target,
        string $actorType = 'system',
        ?int $actorId = null,
        string $reason = 'status_change',
    ): bool {
        if ($orderId <= 0 || $storeId <= 0 || !in_array($actorType, ['system', 'admin', 'customer', 'webhook', 'import'], true)) {
            throw new \InvalidArgumentException('Invalid order status change.');
        }

        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare(
                'SELECT id, order_status, stock_released_at
                 FROM commerce_orders WHERE id = ? AND store_id = ? LIMIT 1 FOR UPDATE'
            );
            $statement->execute([$orderId, $storeId]);
            $order = $statement->fetch(PDO::FETCH_ASSOC);
            if (!$order) {
                throw new \DomainException('Order does not exist in this store.');
            }
            $current = OrderStatus::from((string) $order['order_status']);
            $this->guard->assertOrder($current, $target);
            if ($current === $target) {
                $this->pdo->commit();
                return false;
            }

            if ($target === OrderStatus::Cancelled && $order['stock_released_at'] === null) {
                $this->releaseStock($orderId);
            }
            $update = $this->pdo->prepare(
                'UPDATE commerce_orders
                 SET order_status = ?,
                     cancelled_at = IF(? = "cancelled", COALESCE(cancelled_at, CURRENT_TIMESTAMP), cancelled_at),
                     stock_released_at = IF(? = "cancelled", COALESCE(stock_released_at, CURRENT_TIMESTAMP), stock_released_at),
                     completed_at = IF(? = "completed", COALESCE(completed_at, CURRENT_TIMESTAMP), completed_at),
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = ? AND store_id = ?'
            );
            $update->execute([$target->value, $target->value, $target->value, $target->value, $orderId, $storeId]);
            $this->pdo->prepare(
                'INSERT INTO commerce_order_status_history
                    (order_id, dimension, from_status, to_status, actor_type, actor_id, reason)
                 VALUES (?, "order", ?, ?, ?, ?, ?)'
            )->execute([$orderId, $current->value, $target->value, $actorType, $actorId, substr($reason, 0, 500)]);
            if ($target === OrderStatus::Cancelled) {
                (new NotificationOutbox($this->pdo))->enqueueOrder($orderId, 'commerce.order.cancelled');
            } elseif ($target === OrderStatus::Shipped) {
                (new NotificationOutbox($this->pdo))->enqueueOrder($orderId, 'commerce.order.shipped', ['status' => $target->value]);
            } else {
                (new NotificationOutbox($this->pdo))->enqueueOrder($orderId, 'commerce.order.status_changed', ['status' => $target->value]);
            }
            $this->pdo->commit();

            return true;
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    private function releaseStock(int $orderId): void
    {
        $statement = $this->pdo->prepare(
            'SELECT product_id, variant_id, quantity, product_snapshot_json
             FROM commerce_order_items WHERE order_id = ? ORDER BY id FOR UPDATE'
        );
        $statement->execute([$orderId]);
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $item) {
            $snapshot = json_decode((string) $item['product_snapshot_json'], true);
            if (!is_array($snapshot) || empty($snapshot['stock_tracked'])) {
                continue;
            }
            $scope = (string) ($snapshot['stock_scope'] ?? '');
            $id = $scope === 'variant' ? (int) ($item['variant_id'] ?? 0) : (int) ($item['product_id'] ?? 0);
            $table = $scope === 'variant' ? 'commerce_product_variants' : 'commerce_products';
            if ($id <= 0 || !in_array($scope, ['variant', 'product'], true)) {
                continue;
            }
            $update = $this->pdo->prepare(
                "UPDATE {$table}
                 SET stock_quantity = stock_quantity + ?, stock_status = 'in_stock', updated_at = CURRENT_TIMESTAMP
                 WHERE id = ? AND stock_quantity IS NOT NULL"
            );
            $update->execute([(int) $item['quantity'], $id]);
            if ($update->rowCount() !== 1) {
                throw new \RuntimeException('Tracked stock could not be released exactly once.');
            }
        }
    }

}
