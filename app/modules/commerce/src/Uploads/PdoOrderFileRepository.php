<?php

declare(strict_types=1);

namespace Reklamova\Cms\Commerce\Uploads;

use PDO;

final class PdoOrderFileRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function transaction(callable $callback): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $callback();
            $this->pdo->commit();

            return $result;
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    /** @return array<string, mixed> */
    public function lockRequirement(
        int $orderId,
        int $orderItemId,
        int $requirementId,
        string $checkoutTokenHash,
        string $storeCode,
    ): array {
        $statement = $this->pdo->prepare(
            'SELECT r.id, r.allowed_extensions_json, r.allowed_mime_types_json, r.max_bytes, r.max_files
             FROM commerce_orders o
             INNER JOIN commerce_stores s ON s.id = o.store_id
             INNER JOIN commerce_order_items oi ON oi.order_id = o.id
             INNER JOIN commerce_product_file_requirements r ON r.product_id = oi.product_id
             WHERE o.id = ? AND oi.id = ? AND r.id = ? AND o.checkout_key = ? AND s.code = ?
             LIMIT 1 FOR UPDATE'
        );
        $statement->execute([$orderId, $orderItemId, $requirementId, $checkoutTokenHash, $storeCode]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new \DomainException('File requirement is unavailable for this order.');
        }
        $count = $this->pdo->prepare(
            'SELECT COUNT(*) FROM commerce_order_files
             WHERE order_id = ? AND order_item_id = ? AND requirement_id = ? AND status <> "deleted"'
        );
        $count->execute([$orderId, $orderItemId, $requirementId]);
        $row['current_files'] = (int) $count->fetchColumn();

        return $row;
    }

    /** @return array<string, mixed> */
    public function lockRequirementForCustomer(
        int $orderId,
        int $orderItemId,
        int $requirementId,
        int $customerId,
        string $storeCode,
    ): array {
        $statement = $this->pdo->prepare(
            'SELECT r.id, r.allowed_extensions_json, r.allowed_mime_types_json, r.max_bytes, r.max_files
             FROM commerce_orders o
             INNER JOIN commerce_stores s ON s.id = o.store_id
             INNER JOIN commerce_customers c ON c.id = o.customer_id AND c.store_id = o.store_id
             INNER JOIN commerce_order_items oi ON oi.order_id = o.id
             INNER JOIN commerce_product_file_requirements r ON r.product_id = oi.product_id
             WHERE o.id = ? AND oi.id = ? AND r.id = ? AND o.customer_id = ?
               AND c.status = "active" AND s.code = ?
             LIMIT 1 FOR UPDATE'
        );
        $statement->execute([$orderId, $orderItemId, $requirementId, $customerId, $storeCode]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new \DomainException('File requirement is unavailable for this order.');
        }
        $count = $this->pdo->prepare(
            'SELECT COUNT(*) FROM commerce_order_files
             WHERE order_id = ? AND order_item_id = ? AND requirement_id = ? AND status <> "deleted"'
        );
        $count->execute([$orderId, $orderItemId, $requirementId]);
        $row['current_files'] = (int) $count->fetchColumn();

        return $row;
    }

    public function insert(array $file): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO commerce_order_files
                (order_id, order_item_id, requirement_id, storage_key, original_name,
                 extension, mime_type, size_bytes, checksum_sha256, status, scan_status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, "uploaded", "pending")'
        );
        $statement->execute([
            $file['order_id'], $file['order_item_id'], $file['requirement_id'], $file['storage_key'],
            $file['original_name'], $file['extension'], $file['mime_type'], $file['size_bytes'],
            $file['checksum_sha256'],
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @return array<string, mixed>|null */
    public function accessibleFile(int $fileId, string $checkoutTokenHash, string $storeCode): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT f.id, f.storage_key, f.original_name, f.mime_type, f.size_bytes, f.checksum_sha256
             FROM commerce_order_files f
             INNER JOIN commerce_orders o ON o.id = f.order_id
             INNER JOIN commerce_stores s ON s.id = o.store_id
             WHERE f.id = ? AND f.status <> "deleted" AND o.checkout_key = ? AND s.code = ? LIMIT 1'
        );
        $statement->execute([$fileId, $checkoutTokenHash, $storeCode]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /** @return array<string, mixed>|null */
    public function accessibleFileForCustomer(int $fileId, int $customerId, string $storeCode): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT f.id, f.storage_key, f.original_name, f.mime_type, f.size_bytes, f.checksum_sha256
             FROM commerce_order_files f
             INNER JOIN commerce_orders o ON o.id = f.order_id
             INNER JOIN commerce_stores s ON s.id = o.store_id
             INNER JOIN commerce_customers c ON c.id = o.customer_id AND c.store_id = o.store_id
             WHERE f.id = ? AND f.status <> "deleted" AND o.customer_id = ?
               AND c.status = "active" AND s.code = ? LIMIT 1'
        );
        $statement->execute([$fileId, $customerId, $storeCode]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }
}
