<?php

declare(strict_types=1);

namespace Reklamova\Cms\Commerce\Payments;

use Reklamova\Cms\Commerce\Orders\PaymentStatus;

interface PaymentNotificationStoreInterface
{
    public function transaction(callable $callback): mixed;

    public function claim(string $provider, PaymentNotification $notification): bool;

    public function attempt(string $provider, string $providerTransactionId): ?PaymentAttempt;

    public function reject(string $provider, string $eventKey, string $errorCode, ?int $attemptId = null): void;

    public function apply(
        string $provider,
        PaymentNotification $notification,
        PaymentAttempt $attempt,
        PaymentStatus $newStatus,
    ): void;
}
