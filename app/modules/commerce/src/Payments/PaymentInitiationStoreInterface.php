<?php

declare(strict_types=1);

namespace Reklamova\Cms\Commerce\Payments;

interface PaymentInitiationStoreInterface
{
    public function transaction(callable $callback): mixed;

    public function reserve(
        int $orderId,
        string $provider,
        string $environment,
        string $idempotencyKey,
    ): PaymentReservation;

    public function complete(int $attemptId, PaymentRedirect $redirect): void;

    public function fail(int $attemptId, string $errorCode): void;
}
