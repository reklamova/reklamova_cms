<?php

declare(strict_types=1);

namespace Reklamova\Cms\Commerce\Payments;

use Reklamova\Cms\Commerce\Orders\PaymentStatus;
use Reklamova\Cms\Commerce\Shared\Money;

final readonly class PaymentAttempt
{
    public function __construct(
        public int $id,
        public int $orderId,
        public string $orderNumber,
        public string $provider,
        public string $providerTransactionId,
        public Money $expectedAmount,
        public PaymentStatus $paymentStatus,
    ) {
    }
}
