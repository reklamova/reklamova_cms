<?php

declare(strict_types=1);

namespace Reklamova\Cms\Commerce\Orders;

final class StatusTransitionGuard
{
    private const PAYMENT_TRANSITIONS = [
        'unpaid' => ['pending', 'cancelled'],
        'pending' => ['paid', 'failed', 'cancelled'],
        'paid' => ['refunded'],
        'failed' => ['pending', 'cancelled'],
        'cancelled' => ['pending'],
        'refunded' => [],
    ];

    private const ORDER_TRANSITIONS = [
        'new' => ['awaiting_files', 'files_received', 'in_production', 'cancelled'],
        'awaiting_files' => ['files_received', 'cancelled'],
        'files_received' => ['in_production', 'cancelled'],
        'in_production' => ['ready', 'cancelled'],
        'ready' => ['shipped', 'completed', 'cancelled'],
        'shipped' => ['completed'],
        'completed' => [],
        'cancelled' => [],
    ];

    public function canChangePayment(PaymentStatus $from, PaymentStatus $to): bool
    {
        return $from === $to || in_array($to->value, self::PAYMENT_TRANSITIONS[$from->value], true);
    }

    public function canChangeOrder(OrderStatus $from, OrderStatus $to): bool
    {
        return $from === $to || in_array($to->value, self::ORDER_TRANSITIONS[$from->value], true);
    }

    public function assertPayment(PaymentStatus $from, PaymentStatus $to): void
    {
        if (!$this->canChangePayment($from, $to)) {
            throw new \DomainException("Payment status cannot change from {$from->value} to {$to->value}.");
        }
    }

    public function assertOrder(OrderStatus $from, OrderStatus $to): void
    {
        if (!$this->canChangeOrder($from, $to)) {
            throw new \DomainException("Order status cannot change from {$from->value} to {$to->value}.");
        }
    }
}
