<?php

declare(strict_types=1);

namespace Reklamova\Cms\Commerce\Pricing;

use Reklamova\Cms\Commerce\Shared\Money;

final readonly class TaxBreakdown
{
    public function __construct(
        public Money $net,
        public Money $tax,
        public Money $gross,
        public int $rateBasisPoints,
    ) {
        if ($rateBasisPoints < 0 || $rateBasisPoints > 100000) {
            throw new \InvalidArgumentException('Tax rate is out of range.');
        }
        if ($net->currency !== $tax->currency || $net->currency !== $gross->currency) {
            throw new \InvalidArgumentException('Tax breakdown currencies must match.');
        }
        if ($net->amountMinor + $tax->amountMinor !== $gross->amountMinor) {
            throw new \InvalidArgumentException('Tax breakdown does not balance.');
        }
    }
}
