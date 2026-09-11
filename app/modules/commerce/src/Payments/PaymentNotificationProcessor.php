<?php

declare(strict_types=1);

namespace Reklamova\Cms\Commerce\Payments;

use Reklamova\Cms\Commerce\Orders\PaymentStatus;
use Reklamova\Cms\Commerce\Orders\StatusTransitionGuard;

final class PaymentNotificationProcessor
{
    public function __construct(
        private PaymentNotificationStoreInterface $store,
        private StatusTransitionGuard $transitions = new StatusTransitionGuard(),
    ) {
    }

    public function process(string $provider, PaymentNotification $notification): PaymentProcessingResult
    {
        // Never claim an invalid event key: otherwise an attacker could submit the
        // authentic body with a bad signature first and poison duplicate handling.
        if (!$notification->signatureValid) {
            return new PaymentProcessingResult('rejected', 'invalid_signature');
        }

        return $this->store->transaction(function () use ($provider, $notification): PaymentProcessingResult {
            if (!$this->store->claim($provider, $notification)) {
                return new PaymentProcessingResult('duplicate');
            }

            $attempt = $this->store->attempt($provider, $notification->providerTransactionId);
            if ($attempt === null) {
                $this->store->reject($provider, $notification->eventKey, 'unknown_transaction');

                return new PaymentProcessingResult('rejected', 'unknown_transaction');
            }
            $mismatch = $this->mismatch($attempt, $notification);
            if ($mismatch !== null) {
                $this->store->reject($provider, $notification->eventKey, $mismatch, $attempt->id);

                return new PaymentProcessingResult('rejected', $mismatch, $attempt->orderId);
            }

            $newStatus = $this->mapStatus($notification->status);
            if ($newStatus === null) {
                $this->store->reject($provider, $notification->eventKey, 'unsupported_status', $attempt->id);

                return new PaymentProcessingResult('rejected', 'unsupported_status', $attempt->orderId);
            }
            if (!$this->transitions->canChangePayment($attempt->paymentStatus, $newStatus)) {
                $this->store->reject($provider, $notification->eventKey, 'invalid_status_transition', $attempt->id);

                return new PaymentProcessingResult('rejected', 'invalid_status_transition', $attempt->orderId);
            }

            $this->store->apply($provider, $notification, $attempt, $newStatus);

            return new PaymentProcessingResult('processed', null, $attempt->orderId);
        });
    }

    private function mismatch(PaymentAttempt $attempt, PaymentNotification $notification): ?string
    {
        if (!hash_equals($attempt->orderNumber, $notification->orderId)) {
            return 'order_mismatch';
        }
        if ($attempt->expectedAmount->currency !== $notification->amount->currency) {
            return 'currency_mismatch';
        }
        if ($attempt->expectedAmount->amountMinor !== $notification->amount->amountMinor) {
            return 'amount_mismatch';
        }

        return null;
    }

    private function mapStatus(string $providerStatus): ?PaymentStatus
    {
        return match (strtolower($providerStatus)) {
            'new', 'pending', 'authorized' => PaymentStatus::Pending,
            'settled' => PaymentStatus::Paid,
            'rejected', 'error' => PaymentStatus::Failed,
            'cancelled' => PaymentStatus::Cancelled,
            default => null,
        };
    }
}
