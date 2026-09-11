<?php

declare(strict_types=1);

namespace Reklamova\Cms\Commerce\Cart;

interface CartRepositoryInterface
{
    public function transaction(callable $callback): mixed;

    /** @return array<string, mixed> */
    public function create(int $storeId, ?int $customerId, string $tokenHash, string $expiresAt): array;

    /** @param array<string, mixed> $options @return array<string, mixed> */
    public function addItem(
        string $tokenHash,
        int $productId,
        ?int $variantId,
        int $quantity,
        array $options,
        string $configurationHash,
    ): array;

    /** @return array<string, mixed> */
    public function setQuantity(string $tokenHash, int $itemId, int $quantity): array;

    /** @return array<string, mixed> */
    public function removeItem(string $tokenHash, int $itemId): array;

    /** @return array<string, mixed> */
    public function snapshot(string $tokenHash): array;
}
