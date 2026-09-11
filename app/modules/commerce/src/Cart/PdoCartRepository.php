<?php

declare(strict_types=1);

namespace Reklamova\Cms\Commerce\Cart;

use PDO;

final class PdoCartRepository implements CartRepositoryInterface
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

    public function create(int $storeId, ?int $customerId, string $tokenHash, string $expiresAt): array
    {
        $store = $this->pdo->prepare(
            'SELECT id, currency FROM commerce_stores WHERE id = ? AND status = "active" LIMIT 1'
        );
        $store->execute([$storeId]);
        $storeRow = $store->fetch(PDO::FETCH_ASSOC);
        if (!$storeRow) {
            throw new \DomainException('Store is unavailable.');
        }
        if ($customerId !== null) {
            $customer = $this->pdo->prepare(
                'SELECT id FROM commerce_customers
                 WHERE id = ? AND store_id = ? AND status = "active" LIMIT 1'
            );
            $customer->execute([$customerId, $storeId]);
            if (!$customer->fetchColumn()) {
                throw new \DomainException('Customer is unavailable in this store.');
            }
        }
        $statement = $this->pdo->prepare(
            'INSERT INTO commerce_carts (store_id, customer_id, token_hash, currency, expires_at)
             VALUES (?, ?, ?, ?, ?)'
        );
        $statement->execute([$storeId, $customerId, $tokenHash, $storeRow['currency'], $expiresAt]);

        return [
            'id' => (int) $this->pdo->lastInsertId(),
            'store_id' => $storeId,
            'customer_id' => $customerId,
            'currency' => (string) $storeRow['currency'],
            'status' => 'active',
            'version' => 1,
            'expires_at' => $expiresAt,
            'items' => [],
        ];
    }

    public function addItem(
        string $tokenHash,
        int $productId,
        ?int $variantId,
        int $quantity,
        array $options,
        string $configurationHash,
    ): array {
        $cart = $this->cart($tokenHash);
        $product = $this->pdo->prepare(
            'SELECT p.id, p.type, p.status, v.id AS variant_exists, v.status AS variant_status
             FROM commerce_products p
             LEFT JOIN commerce_product_variants v ON v.id = ? AND v.product_id = p.id
             WHERE p.id = ? AND p.store_id = ? LIMIT 1'
        );
        $product->execute([$variantId, $productId, $cart['store_id']]);
        $row = $product->fetch(PDO::FETCH_ASSOC);
        if (!$row || (string) $row['status'] !== 'published') {
            throw new \DomainException('Product is unavailable.');
        }
        if ((string) $row['type'] === 'variable' && $variantId === null) {
            throw new \DomainException('Product requires a variant.');
        }
        if ($variantId !== null && ($row['variant_exists'] === null || (string) $row['variant_status'] !== 'active')) {
            throw new \DomainException('Product variant is unavailable.');
        }
        $statement = $this->pdo->prepare(
            'INSERT INTO commerce_cart_items
                (cart_id, product_id, variant_id, quantity, options_json, configuration_hash)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity), updated_at = CURRENT_TIMESTAMP'
        );
        $statement->execute([
            $cart['id'],
            $productId,
            $variantId,
            $quantity,
            $this->json($options),
            $configurationHash,
        ]);
        $quantityStatement = $this->pdo->prepare(
            'SELECT quantity FROM commerce_cart_items WHERE cart_id = ? AND configuration_hash = ? LIMIT 1'
        );
        $quantityStatement->execute([$cart['id'], $configurationHash]);
        if ((int) $quantityStatement->fetchColumn() > 100000) {
            throw new \DomainException('Cart item quantity exceeds the limit.');
        }
        $this->touch((int) $cart['id']);

        return $this->snapshot($tokenHash);
    }

    public function setQuantity(string $tokenHash, int $itemId, int $quantity): array
    {
        $cart = $this->cart($tokenHash);
        $exists = $this->pdo->prepare(
            'SELECT id FROM commerce_cart_items WHERE id = ? AND cart_id = ? LIMIT 1 FOR UPDATE'
        );
        $exists->execute([$itemId, $cart['id']]);
        if (!$exists->fetchColumn()) {
            throw new \DomainException('Cart item does not exist.');
        }
        $statement = $this->pdo->prepare(
            'UPDATE commerce_cart_items SET quantity = ?, updated_at = CURRENT_TIMESTAMP
             WHERE id = ? AND cart_id = ?'
        );
        $statement->execute([$quantity, $itemId, $cart['id']]);
        $this->touch((int) $cart['id']);

        return $this->snapshot($tokenHash);
    }

    public function removeItem(string $tokenHash, int $itemId): array
    {
        $cart = $this->cart($tokenHash);
        $statement = $this->pdo->prepare('DELETE FROM commerce_cart_items WHERE id = ? AND cart_id = ?');
        $statement->execute([$itemId, $cart['id']]);
        if ($statement->rowCount() !== 1) {
            throw new \DomainException('Cart item does not exist.');
        }
        $this->touch((int) $cart['id']);

        return $this->snapshot($tokenHash);
    }

    public function snapshot(string $tokenHash): array
    {
        $cart = $this->cart($tokenHash);
        $statement = $this->pdo->prepare(
            'SELECT id, product_id, variant_id, quantity, options_json, configuration_hash
             FROM commerce_cart_items WHERE cart_id = ? ORDER BY id'
        );
        $statement->execute([$cart['id']]);
        $items = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $items[] = [
                'id' => (int) $row['id'],
                'product_id' => (int) $row['product_id'],
                'variant_id' => $row['variant_id'] === null ? null : (int) $row['variant_id'],
                'quantity' => (int) $row['quantity'],
                'options' => $this->decode((string) $row['options_json']),
                'configuration_hash' => (string) $row['configuration_hash'],
            ];
        }

        return [
            'id' => (int) $cart['id'],
            'store_id' => (int) $cart['store_id'],
            'customer_id' => $cart['customer_id'] === null ? null : (int) $cart['customer_id'],
            'currency' => (string) $cart['currency'],
            'status' => (string) $cart['status'],
            'version' => (int) $cart['version'],
            'expires_at' => $cart['expires_at'],
            'items' => $items,
        ];
    }

    /** @return array<string, mixed> */
    private function cart(string $tokenHash): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, store_id, customer_id, currency, status, version, expires_at
             FROM commerce_carts WHERE token_hash = ? LIMIT 1 FOR UPDATE'
        );
        $statement->execute([$tokenHash]);
        $cart = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$cart || (string) $cart['status'] !== 'active') {
            throw new \DomainException('Cart is unavailable.');
        }
        if ($cart['expires_at'] !== null && strtotime((string) $cart['expires_at']) <= time()) {
            throw new \DomainException('Cart has expired.');
        }

        return $cart;
    }

    private function touch(int $cartId): void
    {
        $this->pdo->prepare(
            'UPDATE commerce_carts
             SET version = version + 1, expires_at = DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 30 DAY),
                 updated_at = CURRENT_TIMESTAMP WHERE id = ?'
        )->execute([$cartId]);
    }

    /** @return array<string, mixed> */
    private function decode(string $json): array
    {
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : [];
    }

    private function json(mixed $value): string
    {
        return json_encode(
            $value,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
        );
    }
}
