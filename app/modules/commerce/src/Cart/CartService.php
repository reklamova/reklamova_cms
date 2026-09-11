<?php

declare(strict_types=1);

namespace Reklamova\Cms\Commerce\Cart;

final class CartService
{
    public function __construct(
        private CartRepositoryInterface $repository,
        private int $ttlSeconds = 2592000,
    ) {
        if ($ttlSeconds < 3600 || $ttlSeconds > 31536000) {
            throw new \InvalidArgumentException('Cart TTL must be between one hour and one year.');
        }
    }

    /** @return array{token: string, cart: array<string, mixed>} */
    public function create(int $storeId, ?int $customerId = null): array
    {
        if ($storeId <= 0 || ($customerId !== null && $customerId <= 0)) {
            throw new \InvalidArgumentException('Cart store or customer ID is invalid.');
        }
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $cart = $this->repository->transaction(fn (): array => $this->repository->create(
            $storeId,
            $customerId,
            hash('sha256', $token),
            gmdate('Y-m-d H:i:s', time() + $this->ttlSeconds),
        ));

        return ['token' => $token, 'cart' => $cart];
    }

    /** @param array<string, mixed> $options @return array<string, mixed> */
    public function add(
        string $token,
        int $productId,
        ?int $variantId,
        int $quantity,
        array $options = [],
    ): array {
        $this->assertToken($token);
        $this->assertLine($productId, $variantId, $quantity);
        $normalized = $this->normalize($options);
        $encoded = json_encode(
            $normalized,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
        );
        if (strlen($encoded) > 32768) {
            throw new \InvalidArgumentException('Cart options are too large.');
        }
        $configurationHash = hash('sha256', implode('|', [
            (string) $productId,
            (string) ($variantId ?? 0),
            $encoded,
        ]));

        return $this->repository->transaction(fn (): array => $this->repository->addItem(
            hash('sha256', $token),
            $productId,
            $variantId,
            $quantity,
            $normalized,
            $configurationHash,
        ));
    }

    /** @return array<string, mixed> */
    public function setQuantity(string $token, int $itemId, int $quantity): array
    {
        $this->assertToken($token);
        if ($itemId <= 0 || $quantity <= 0 || $quantity > 100000) {
            throw new \InvalidArgumentException('Cart item or quantity is invalid.');
        }

        return $this->repository->transaction(
            fn (): array => $this->repository->setQuantity(hash('sha256', $token), $itemId, $quantity),
        );
    }

    /** @return array<string, mixed> */
    public function remove(string $token, int $itemId): array
    {
        $this->assertToken($token);
        if ($itemId <= 0) {
            throw new \InvalidArgumentException('Cart item ID is invalid.');
        }

        return $this->repository->transaction(
            fn (): array => $this->repository->removeItem(hash('sha256', $token), $itemId),
        );
    }

    /** @return array<string, mixed> */
    public function snapshot(string $token): array
    {
        $this->assertToken($token);

        return $this->repository->transaction(
            fn (): array => $this->repository->snapshot(hash('sha256', $token)),
        );
    }

    private function assertToken(string $token): void
    {
        if (strlen($token) < 32 || strlen($token) > 500) {
            throw new \InvalidArgumentException('Cart token has an invalid length.');
        }
    }

    private function assertLine(int $productId, ?int $variantId, int $quantity): void
    {
        if ($productId <= 0 || ($variantId !== null && $variantId <= 0) || $quantity <= 0 || $quantity > 100000) {
            throw new \InvalidArgumentException('Cart line is invalid.');
        }
    }

    private function normalize(mixed $value, int $depth = 0): mixed
    {
        if ($depth > 8) {
            throw new \InvalidArgumentException('Cart options are nested too deeply.');
        }
        if (!is_array($value)) {
            if ($value === null || is_scalar($value)) {
                return $value;
            }
            throw new \InvalidArgumentException('Cart options contain an unsupported value.');
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->normalize($item, $depth + 1), $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            if (!is_string($key) || preg_match('/^[A-Za-z0-9_.-]{1,120}$/', $key) !== 1) {
                throw new \InvalidArgumentException('Cart option key is invalid.');
            }
            $value[$key] = $this->normalize($item, $depth + 1);
        }

        return $value;
    }
}
