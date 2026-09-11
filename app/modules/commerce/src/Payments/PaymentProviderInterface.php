<?php

declare(strict_types=1);

namespace Reklamova\Cms\Commerce\Payments;

interface PaymentProviderInterface
{
    public function name(): string;

    public function initiate(PaymentRequest $request): PaymentRedirect;

    /**
     * Implementations must validate the signature against the unmodified request body.
     *
     * @param array<string, string> $headers
     */
    public function parseNotification(string $rawBody, array $headers): PaymentNotification;

    public function query(string $providerTransactionId): PaymentNotification;

    public function cancel(string $providerTransactionId): void;
}
