<?php

declare(strict_types=1);

namespace Reklamova\Cms\Commerce\Payments;

use Reklamova\Cms\Commerce\Shared\Money;

final readonly class PaymentReservation
{
    public function __construct(
        public int $attemptId,
        public int $orderId,
        public string $orderNumber,
        public Money $amount,
        public string $customerEmail,
        public string $customerFirstName,
        public string $customerLastName,
        public string $idempotencyKey,
        public ?PaymentRedirect $existingRedirect = null,
    ) {
    }
}
