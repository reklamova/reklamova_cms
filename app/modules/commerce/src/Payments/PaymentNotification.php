<?php

declare(strict_types=1);

namespace Reklamova\Cms\Commerce\Payments;

use Reklamova\Cms\Commerce\Shared\Money;

final readonly class PaymentNotification
{
    public function __construct(
        public string $eventKey,
        public string $providerTransactionId,
        public string $orderId,
        public Money $amount,
        public string $status,
        public bool $signatureValid,
        public string $payloadHash,
    ) {
        if ($eventKey === '' || $providerTransactionId === '' || $orderId === '' || $payloadHash === '') {
            throw new \InvalidArgumentException('Payment notification identifiers are required.');
        }
    }
}
