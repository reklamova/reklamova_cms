<?php

declare(strict_types=1);

namespace Reklamova\Cms\Commerce\Payments;

use Reklamova\Cms\Commerce\Shared\Money;

final readonly class PaymentRequest
{
    public function __construct(
        public string $orderId,
        public string $orderNumber,
        public Money $amount,
        public string $customerEmail,
        public string $returnUrl,
        public string $notificationUrl,
        public string $idempotencyKey,
    ) {
        if ($orderId === '' || $orderNumber === '' || $idempotencyKey === '') {
            throw new \InvalidArgumentException('Payment order identifiers are required.');
        }
        if ($amount->amountMinor <= 0) {
            throw new \InvalidArgumentException('Payment amount must be positive.');
        }
        if (!filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Customer email is invalid.');
        }
        foreach ([$returnUrl, $notificationUrl] as $url) {
            if (!filter_var($url, FILTER_VALIDATE_URL) || strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https') {
                throw new \InvalidArgumentException('Payment callback URLs must use HTTPS.');
            }
        }
    }
}
