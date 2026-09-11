<?php

declare(strict_types=1);

namespace Reklamova\Cms\Commerce\Payments;

use PDO;
use Reklamova\Cms\Commerce\Orders\PaymentStatus;
use Reklamova\Cms\Commerce\Shared\Money;

final class PdoPaymentInitiationStore implements PaymentInitiationStoreInterface
{
    public function __construct(private PDO $pdo)
    {
    }

    public function transaction(callable $callback): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $callback();
            $this->pdo->commit();

            return $result;
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function reserve(
        int $orderId,
        string $provider,
        string $environment,
        string $idempotencyKey,
    ): PaymentReservation {
        $orderStatement = $this->pdo->prepare(
            'SELECT id, order_number, payment_status, currency, total_minor, customer_email, billing_address_json
             FROM commerce_orders WHERE id = ? LIMIT 1 FOR UPDATE'
        );
        $orderStatement->execute([$orderId]);
        $order = $orderStatement->fetch(PDO::FETCH_ASSOC);
        if (!$order) {
            throw new \DomainException('Order does not exist.');
        }
        if (in_array((string) $order['payment_status'], [
            PaymentStatus::Paid->value,
            PaymentStatus::Cancelled->value,
            PaymentStatus::Refunded->value,
        ], true)) {
            throw new \DomainException('Order cannot accept another payment attempt.');
        }

        $attemptStatement = $this->pdo->prepare(
            'SELECT id, order_id, provider_transaction_id, redirect_url, status
             FROM commerce_payment_attempts
             WHERE provider = ? AND idempotency_key = ? LIMIT 1 FOR UPDATE'
        );
        $attemptStatement->execute([$provider, $idempotencyKey]);
        $attempt = $attemptStatement->fetch(PDO::FETCH_ASSOC);
        $billing = $this->decodeAddress((string) $order['billing_address_json']);
        if ($attempt) {
            if ((int) $attempt['order_id'] !== $orderId) {
                throw new \DomainException('Payment retry token belongs to another order.');
            }
            if (!empty($attempt['provider_transaction_id']) && !empty($attempt['redirect_url'])) {
                return $this->reservation(
                    $order,
                    $billing,
                    (int) $attempt['id'],
                    $idempotencyKey,
                    new PaymentRedirect(
                        (string) $attempt['provider_transaction_id'],
                        (string) $attempt['redirect_url'],
                        (string) $attempt['status'],
                    ),
                );
            }

            throw new \DomainException(
                (string) $attempt['status'] === 'failed'
                    ? 'Previous payment attempt failed; create a new retry token.'
                    : 'Payment attempt is already in progress.'
            );
        }

        $insert = $this->pdo->prepare(
            'INSERT INTO commerce_payment_attempts
                (order_id, provider, environment, idempotency_key, status, amount_minor, currency, request_hash)
             VALUES (?, ?, ?, ?, "new", ?, ?, ?)'
        );
        $requestHash = hash('sha256', implode('|', [
            $order['id'],
            $order['order_number'],
            $order['total_minor'],
            $order['currency'],
            $idempotencyKey,
        ]));
        $insert->execute([
            $orderId,
            $provider,
            $environment,
            $idempotencyKey,
            $order['total_minor'],
            $order['currency'],
            $requestHash,
        ]);
        $attemptId = (int) $this->pdo->lastInsertId();
        if ((string) $order['payment_status'] !== PaymentStatus::Pending->value) {
            $this->updateOrderPaymentStatus(
                $orderId,
                (string) $order['payment_status'],
                PaymentStatus::Pending->value,
                'payment_attempt_created',
            );
        }

        return $this->reservation($order, $billing, $attemptId, $idempotencyKey);
    }

    public function complete(int $attemptId, PaymentRedirect $redirect): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE commerce_payment_attempts
             SET provider_transaction_id = ?, redirect_url = ?, status = "pending",
                 response_meta_json = ?, updated_at = CURRENT_TIMESTAMP
             WHERE id = ? AND provider_transaction_id IS NULL'
        );
        $statement->execute([
            $redirect->providerTransactionId,
            $redirect->redirectUrl,
            json_encode(['provider_status' => $redirect->status], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            $attemptId,
        ]);
        if ($statement->rowCount() !== 1) {
            throw new \RuntimeException('Payment attempt could not be completed exactly once.');
        }
    }

    public function fail(int $attemptId, string $errorCode): void
    {
        $attempt = $this->pdo->prepare(
            'SELECT order_id FROM commerce_payment_attempts WHERE id = ? LIMIT 1 FOR UPDATE'
        );
        $attempt->execute([$attemptId]);
        $row = $attempt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new \RuntimeException('Payment attempt does not exist.');
        }
        $this->pdo->prepare(
            'UPDATE commerce_payment_attempts
             SET status = "failed", response_meta_json = ?, updated_at = CURRENT_TIMESTAMP
             WHERE id = ? AND provider_transaction_id IS NULL'
        )->execute([
            json_encode(['error_code' => $errorCode], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            $attemptId,
        ]);

        $order = $this->pdo->prepare(
            'SELECT payment_status FROM commerce_orders WHERE id = ? LIMIT 1 FOR UPDATE'
        );
        $order->execute([(int) $row['order_id']]);
        $status = (string) $order->fetchColumn();
        if ($status === PaymentStatus::Pending->value) {
            $this->updateOrderPaymentStatus(
                (int) $row['order_id'],
                $status,
                PaymentStatus::Failed->value,
                $errorCode,
            );
        }
    }

    /**
     * @param array<string, mixed> $order
     * @param array<string, mixed> $billing
     */
    private function reservation(
        array $order,
        array $billing,
        int $attemptId,
        string $idempotencyKey,
        ?PaymentRedirect $redirect = null,
    ): PaymentReservation {
        return new PaymentReservation(
            $attemptId,
            (int) $order['id'],
            (string) $order['order_number'],
            new Money((int) $order['total_minor'], (string) $order['currency']),
            (string) $order['customer_email'],
            trim((string) ($billing['first_name'] ?? '')) ?: 'Klient',
            trim((string) ($billing['last_name'] ?? '')) ?: 'Reklamova',
            $idempotencyKey,
            $redirect,
        );
    }

    /** @return array<string, mixed> */
    private function decodeAddress(string $json): array
    {
        $address = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return is_array($address) ? $address : [];
    }

    private function updateOrderPaymentStatus(int $orderId, string $from, string $to, string $reason): void
    {
        $this->pdo->prepare(
            'UPDATE commerce_orders SET payment_status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?'
        )->execute([$to, $orderId]);
        $this->pdo->prepare(
            'INSERT INTO commerce_order_status_history
                (order_id, dimension, from_status, to_status, actor_type, reason)
             VALUES (?, "payment", ?, ?, "system", ?)'
        )->execute([$orderId, $from, $to, $reason]);
    }
}
