<?php

declare(strict_types=1);

namespace Reklamova\Cms\Commerce\Payments;

final readonly class PaymentRedirect
{
    public function __construct(
        public string $providerTransactionId,
        public string $redirectUrl,
        public string $status = 'pending',
    ) {
        if ($providerTransactionId === ''
            || !filter_var($redirectUrl, FILTER_VALIDATE_URL)
            || strtolower((string) parse_url($redirectUrl, PHP_URL_SCHEME)) !== 'https') {
            throw new \InvalidArgumentException('Provider transaction and HTTPS redirect URL are required.');
        }
    }
}
