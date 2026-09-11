<?php

declare(strict_types=1);

namespace Reklamova\Cms\Commerce\Checkout;

final readonly class CheckoutLine
{
    /**
     * @param array<string, mixed> $options
     * @param array<string, mixed> $snapshot
     */
    public function __construct(
        public int $productId,
        public ?int $variantId,
        public string $productName,
        public ?string $variantName,
        public ?string $sku,
        public int $quantity,
        public int $unitPriceMinor,
        public int $discountMinor,
        public int $taxRateBps,
        public array $options = [],
        public array $snapshot = [],
        public bool $requiresFiles = false,
    ) {
        if ($productId <= 0 || $quantity <= 0 || trim($productName) === '') {
            throw new \InvalidArgumentException('Checkout line identity is invalid.');
        }
        if ($unitPriceMinor < 0 || $discountMinor < 0 || $taxRateBps < 0) {
            throw new \InvalidArgumentException('Checkout line financial values cannot be negative.');
        }
        if ($discountMinor > $unitPriceMinor * $quantity) {
            throw new \InvalidArgumentException('Checkout line discount exceeds line value.');
        }
    }
}
