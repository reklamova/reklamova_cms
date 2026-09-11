<?php

declare(strict_types=1);

namespace Reklamova\Cms\Commerce\Pricing;

use Reklamova\Cms\Commerce\Shared\Money;

final class CartCalculator
{
    public function __construct(
        private TaxCalculator $taxCalculator = new TaxCalculator(),
        private bool $pricesIncludeTax = true,
    ) {
    }

    /**
     * @param array<int, array<string, mixed>> $lines
     * @param array<string, mixed>|null $shipping
     * @return array<string, mixed>
     */
    public function calculate(array $lines, ?array $shipping = null, string $currency = 'PLN'): array
    {
        $currency = strtoupper($currency);
        $resultLines = [];
        $net = Money::zero($currency);
        $tax = Money::zero($currency);
        $grossBeforeDiscount = Money::zero($currency);
        $discount = Money::zero($currency);
        $total = Money::zero($currency);

        foreach ($lines as $index => $line) {
            $quantity = $this->positiveInt($line['quantity'] ?? null, "Line {$index} quantity");
            $unitPrice = $this->nonNegativeInt($line['unit_price_minor'] ?? null, "Line {$index} unit price");
            $lineDiscount = $this->nonNegativeInt($line['discount_minor'] ?? 0, "Line {$index} discount");
            $rate = $this->nonNegativeInt($line['tax_rate_bps'] ?? 0, "Line {$index} tax rate");

            $beforeDiscount = (new Money($unitPrice, $currency))->multiply($quantity);
            if ($lineDiscount > $beforeDiscount->amountMinor) {
                throw new \InvalidArgumentException("Line {$index} discount exceeds its value.");
            }

            $charge = $beforeDiscount->subtract(new Money($lineDiscount, $currency));
            $breakdown = $this->breakdown($charge, $rate);
            $resultLines[] = [
                'id' => (string) ($line['id'] ?? $index),
                'quantity' => $quantity,
                'unit_price_minor' => $unitPrice,
                'gross_before_discount_minor' => $beforeDiscount->amountMinor,
                'discount_minor' => $lineDiscount,
                'net_minor' => $breakdown->net->amountMinor,
                'tax_minor' => $breakdown->tax->amountMinor,
                'total_minor' => $breakdown->gross->amountMinor,
                'tax_rate_bps' => $rate,
            ];
            $net = $net->add($breakdown->net);
            $tax = $tax->add($breakdown->tax);
            $grossBeforeDiscount = $grossBeforeDiscount->add($beforeDiscount);
            $discount = $discount->add(new Money($lineDiscount, $currency));
            $total = $total->add($breakdown->gross);
        }

        $shippingResult = null;
        if ($shipping !== null) {
            $shippingAmount = $this->nonNegativeInt($shipping['amount_minor'] ?? null, 'Shipping amount');
            $shippingRate = $this->nonNegativeInt($shipping['tax_rate_bps'] ?? 0, 'Shipping tax rate');
            $shippingBreakdown = $this->breakdown(new Money($shippingAmount, $currency), $shippingRate);
            $shippingResult = [
                'method' => (string) ($shipping['method'] ?? ''),
                'net_minor' => $shippingBreakdown->net->amountMinor,
                'tax_minor' => $shippingBreakdown->tax->amountMinor,
                'total_minor' => $shippingBreakdown->gross->amountMinor,
                'tax_rate_bps' => $shippingRate,
            ];
            $net = $net->add($shippingBreakdown->net);
            $tax = $tax->add($shippingBreakdown->tax);
            $total = $total->add($shippingBreakdown->gross);
        }

        return [
            'currency' => $currency,
            'prices_include_tax' => $this->pricesIncludeTax,
            'lines' => $resultLines,
            'shipping' => $shippingResult,
            'subtotal_minor' => $grossBeforeDiscount->amountMinor,
            'discount_minor' => $discount->amountMinor,
            'net_minor' => $net->amountMinor,
            'tax_minor' => $tax->amountMinor,
            'total_minor' => $total->amountMinor,
        ];
    }

    private function breakdown(Money $amount, int $rateBasisPoints): TaxBreakdown
    {
        return $this->pricesIncludeTax
            ? $this->taxCalculator->fromGross($amount, $rateBasisPoints)
            : $this->taxCalculator->fromNet($amount, $rateBasisPoints);
    }

    private function positiveInt(mixed $value, string $label): int
    {
        $value = $this->integer($value, $label);
        if ($value <= 0) {
            throw new \InvalidArgumentException("{$label} must be positive.");
        }

        return $value;
    }

    private function nonNegativeInt(mixed $value, string $label): int
    {
        $value = $this->integer($value, $label);
        if ($value < 0) {
            throw new \InvalidArgumentException("{$label} cannot be negative.");
        }

        return $value;
    }

    private function integer(mixed $value, string $label): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }

        throw new \InvalidArgumentException("{$label} must be an integer.");
    }
}
