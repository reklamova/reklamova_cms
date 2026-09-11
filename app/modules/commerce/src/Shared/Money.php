<?php

declare(strict_types=1);

namespace Reklamova\Cms\Commerce\Shared;

final readonly class Money
{
    public int $amountMinor;
    public string $currency;

    public function __construct(int $amountMinor, string $currency)
    {
        $currency = strtoupper(trim($currency));
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new \InvalidArgumentException('Currency must be an ISO 4217 code.');
        }

        $this->amountMinor = $amountMinor;
        $this->currency = $currency;
    }

    public static function zero(string $currency): self
    {
        return new self(0, $currency);
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amountMinor + $other->amountMinor, $this->currency);
    }

    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amountMinor - $other->amountMinor, $this->currency);
    }

    public function multiply(int $quantity): self
    {
        if ($quantity < 0) {
            throw new \InvalidArgumentException('Quantity cannot be negative.');
        }
        if ($quantity !== 0 && abs($this->amountMinor) > intdiv(PHP_INT_MAX, $quantity)) {
            throw new \OverflowException('Money multiplication overflow.');
        }

        return new self($this->amountMinor * $quantity, $this->currency);
    }

    public function isNegative(): bool
    {
        return $this->amountMinor < 0;
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new \InvalidArgumentException('Cannot operate on different currencies.');
        }
    }
}
