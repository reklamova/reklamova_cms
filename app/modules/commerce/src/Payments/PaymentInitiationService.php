<?php

declare(strict_types=1);

namespace Reklamova\Cms\Commerce\Payments;

final class PaymentInitiationService
{
    public function __construct(
        private PaymentInitiationStoreInterface $store,
        private PaymentProviderInterface $provider,
        private string $environment = 'sandbox',
    ) {
        if (!in_array($environment, ['sandbox', 'production'], true)) {
            throw new \InvalidArgumentException('Payment environment must be sandbox or production.');
        }
    }

    public function start(
        int $orderId,
        string $returnUrl,
        string $notificationUrl,
        string $idempotencyToken,
    ): PaymentRedirect {
        if ($orderId <= 0) {
            throw new \InvalidArgumentException('Order ID must be positive.');
        }
        if (strlen($idempotencyToken) < 16 || strlen($idempotencyToken) > 500) {
            throw new \InvalidArgumentException('Payment idempotency token has an invalid length.');
        }
        $idempotencyKey = hash('sha256', $idempotencyToken);
        $reservation = $this->store->transaction(fn (): PaymentReservation => $this->store->reserve(
            $orderId,
            $this->provider->name(),
            $this->environment,
            $idempotencyKey,
        ));
        if ($reservation->existingRedirect !== null) {
            return $reservation->existingRedirect;
        }

        try {
            $redirect = $this->provider->initiate(new PaymentRequest(
                (string) $reservation->orderId,
                $reservation->orderNumber,
                $reservation->amount,
                $reservation->customerEmail,
                $returnUrl,
                $notificationUrl,
                $reservation->idempotencyKey,
                $reservation->customerFirstName,
                $reservation->customerLastName,
            ));
            $this->store->transaction(fn () => $this->store->complete($reservation->attemptId, $redirect));

            return $redirect;
        } catch (\Throwable $exception) {
            $errorCode = $exception instanceof IngPayException && $exception->apiCode !== null
                ? 'provider_' . preg_replace('/[^a-zA-Z0-9_.-]/', '_', $exception->apiCode)
                : 'provider_request_failed';
            $this->store->transaction(fn () => $this->store->fail($reservation->attemptId, $errorCode));

            throw $exception;
        }
    }
}
