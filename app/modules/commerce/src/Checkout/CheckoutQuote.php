<?php

declare(strict_types=1);

namespace Reklamova\Cms\Commerce\Checkout;

final readonly class CheckoutQuote
{
    /** @param array<int, CheckoutLine> $lines */
    public function __construct(
        public int $storeId,
        public string $storeCode,
        public int $cartId,
        public int $cartVersion,
        public string $currency,
        public bool $pricesIncludeTax,
        public array $lines,
        public string $shippingMethodCode,
        public string $shippingMethodName,
        public int $shippingPriceMinor,
        public int $shippingTaxRateBps,
        public ?int $couponId = null,
        public ?string $couponCode = null,
    ) {
        if ($storeId <= 0 || $cartId <= 0 || $cartVersion <= 0 || $lines === []) {
            throw new \InvalidArgumentException('Checkout quote identity is invalid.');
        }
        if (preg_match('/^[A-Z]{3}$/', strtoupper($currency)) !== 1) {
            throw new \InvalidArgumentException('Checkout quote currency is invalid.');
        }
        foreach ($lines as $line) {
            if (!$line instanceof CheckoutLine) {
                throw new \InvalidArgumentException('Checkout quote contains an invalid line.');
            }
        }
        if ($shippingPriceMinor < 0 || $shippingTaxRateBps < 0) {
            throw new \InvalidArgumentException('Checkout shipping values cannot be negative.');
        }
    }
}
