<?php

declare(strict_types=1);

namespace Reklamova\Cms\Commerce\Pricing;

use Reklamova\Cms\Commerce\Shared\Money;

final class TaxCalculator
{
    public function fromGross(Money $gross, int $rateBasisPoints): TaxBreakdown
    {
        $this->assertRate($rateBasisPoints);
        $netMinor = $this->roundDivide($gross->amountMinor * 10000, 10000 + $rateBasisPoints);
        $net = new Money($netMinor, $gross->currency);

        return new TaxBreakdown($net, $gross->subtract($net), $gross, $rateBasisPoints);
    }

    public function fromNet(Money $net, int $rateBasisPoints): TaxBreakdown
    {
        $this->assertRate($rateBasisPoints);
        $tax = new Money($this->roundDivide($net->amountMinor * $rateBasisPoints, 10000), $net->currency);

        return new TaxBreakdown($net, $tax, $net->add($tax), $rateBasisPoints);
    }

    private function roundDivide(int $numerator, int $denominator): int
    {
        if ($denominator <= 0) {
            throw new \InvalidArgumentException('Denominator must be positive.');
        }

        $sign = $numerator < 0 ? -1 : 1;
        $absolute = abs($numerator);

        return $sign * intdiv($absolute + intdiv($denominator, 2), $denominator);
    }

    private function assertRate(int $rateBasisPoints): void
    {
        if ($rateBasisPoints < 0 || $rateBasisPoints > 100000) {
            throw new \InvalidArgumentException('Tax rate is out of range.');
        }
    }
}
