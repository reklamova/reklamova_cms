<?php

declare(strict_types=1);

namespace Reklamova\Cms\Commerce\Checkout;

use Reklamova\Cms\Commerce\Pricing\CartCalculator;
use Reklamova\Cms\Commerce\Pricing\TaxCalculator;

final class CheckoutService
{
    public function __construct(
        private CheckoutStoreInterface $store,
        private ?CartCalculator $calculator = null,
        private OrderNumberGeneratorInterface $orderNumbers = new RandomOrderNumberGenerator(),
    ) {
    }

    public function place(string $cartToken, CheckoutData $data, string $idempotencyToken): CheckoutResult
    {
        if (strlen($cartToken) < 24 || strlen($cartToken) > 500) {
            throw new \InvalidArgumentException('Cart token has an invalid length.');
        }
        if (strlen($idempotencyToken) < 16 || strlen($idempotencyToken) > 500) {
            throw new \InvalidArgumentException('Checkout idempotency token has an invalid length.');
        }
        $checkoutKey = hash('sha256', $idempotencyToken);

        return $this->store->transaction(function () use ($cartToken, $data, $checkoutKey): CheckoutResult {
            $existing = $this->store->existing($checkoutKey);
            if ($existing !== null) {
                return new CheckoutResult(
                    $existing->orderId,
                    $existing->orderNumber,
                    $existing->orderStatus,
                    $existing->paymentStatus,
                    $existing->totals,
                    true,
                );
            }

            $quote = $this->store->lockQuote($cartToken, $data);
            $lines = array_map(static fn (CheckoutLine $line): array => [
                'id' => (string) $line->productId . ':' . (string) ($line->variantId ?? 0),
                'unit_price_minor' => $line->unitPriceMinor,
                'quantity' => $line->quantity,
                'discount_minor' => $line->discountMinor,
                'tax_rate_bps' => $line->taxRateBps,
            ], $quote->lines);
            $calculator = $this->calculator ?? new CartCalculator(new TaxCalculator(), $quote->pricesIncludeTax);
            $calculation = $calculator->calculate($lines, [
                'method' => $quote->shippingMethodCode,
                'amount_minor' => $quote->shippingPriceMinor,
                'tax_rate_bps' => $quote->shippingTaxRateBps,
            ], $quote->currency);
            if ((int) $calculation['total_minor'] <= 0) {
                throw new \DomainException('Checkout total must be positive.');
            }

            $result = $this->store->createOrder(
                $checkoutKey,
                $this->orderNumbers->generate($quote->storeCode),
                $quote,
                $data,
                $calculation,
            );
            $this->store->completeCart($quote->cartId, $quote->cartVersion);

            return $result;
        });
    }
}
