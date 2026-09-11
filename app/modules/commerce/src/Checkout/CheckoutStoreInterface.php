<?php

declare(strict_types=1);

namespace Reklamova\Cms\Commerce\Checkout;

interface CheckoutStoreInterface
{
    public function transaction(callable $callback): mixed;

    public function existing(string $checkoutKey): ?CheckoutResult;

    public function lockQuote(string $cartToken, CheckoutData $data): CheckoutQuote;

    /** @param array<string, mixed> $calculation */
    public function createOrder(
        string $checkoutKey,
        string $orderNumber,
        CheckoutQuote $quote,
        CheckoutData $data,
        array $calculation,
    ): CheckoutResult;

    public function completeCart(int $cartId, int $expectedVersion): void;
}
