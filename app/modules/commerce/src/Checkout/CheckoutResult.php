<?php

declare(strict_types=1);

namespace Reklamova\Cms\Commerce\Checkout;

final readonly class CheckoutResult
{
    /** @param array<string, mixed> $totals */
    public function __construct(
        public int $orderId,
        public string $orderNumber,
        public string $orderStatus,
        public string $paymentStatus,
        public array $totals,
        public bool $alreadyExisted = false,
    ) {
    }
}
