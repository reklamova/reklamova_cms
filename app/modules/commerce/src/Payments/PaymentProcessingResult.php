<?php

declare(strict_types=1);

namespace Reklamova\Cms\Commerce\Payments;

final readonly class PaymentProcessingResult
{
    public function __construct(
        public string $status,
        public ?string $reason = null,
        public ?int $orderId = null,
    ) {
    }
}
