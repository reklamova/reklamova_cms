<?php

declare(strict_types=1);

namespace Reklamova\Cms\Commerce\Payments;

use PDO;
use PDOException;
use Reklamova\Cms\Commerce\Orders\PaymentStatus;
use Reklamova\Cms\Commerce\Shared\Money;

final class PdoPaymentNotificationStore implements PaymentNotificationStoreInterface
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

    public function claim(string $provider, PaymentNotification $notification): bool
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO commerce_payment_events
                (provider, event_key, provider_transaction_id, signature_valid, payload_hash, received_status)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        try {
            $statement->execute([
                $provider,
                $notification->eventKey,
                $notification->providerTransactionId,
                $notification->signatureValid ? 1 : 0,
                $notification->payloadHash,
                $notification->status,
            ]);

            return true;
        } catch (PDOException $exception) {
            if ((string) $exception->getCode() === '23000'
                && (int) ($exception->errorInfo[1] ?? 0) === 1062) {
                return false;
            }
            throw $exception;
        }
    }

    public function attempt(string $provider, string $providerTransactionId): ?PaymentAttempt
    {
        $statement = $this->pdo->prepare(
            'SELECT pa.id, pa.order_id, pa.provider, pa.provider_transaction_id, pa.amount_minor, pa.currency,
                    o.order_number, o.payment_status
             FROM commerce_payment_attempts pa
             INNER JOIN commerce_orders o ON o.id = pa.order_id
             WHERE pa.provider = ? AND pa.provider_transaction_id = ?
             LIMIT 1 FOR UPDATE'
        );
        $statement->execute([$provider, $providerTransactionId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        return new PaymentAttempt(
            (int) $row['id'],
            (int) $row['order_id'],
            (string) $row['order_number'],
            (string) $row['provider'],
            (string) $row['provider_transaction_id'],
            new Money((int) $row['amount_minor'], (string) $row['currency']),
            $this->paymentStatus((string) $row['payment_status']),
        );
    }

    public function reject(string $provider, string $eventKey, string $errorCode, ?int $attemptId = null): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE commerce_payment_events
             SET payment_attempt_id = ?, processing_status = "rejected", error_code = ?, processed_at = CURRENT_TIMESTAMP
             WHERE provider = ? AND event_key = ?'
        );
        $statement->execute([$attemptId, $errorCode, $provider, $eventKey]);
    }

    public function apply(
        string $provider,
        PaymentNotification $notification,
        PaymentAttempt $attempt,
        PaymentStatus $newStatus,
    ): void {
        $attemptStatement = $this->pdo->prepare(
            'UPDATE commerce_payment_attempts
             SET status = ?, settled_at = IF(? = "paid", COALESCE(settled_at, CURRENT_TIMESTAMP), settled_at),
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = ?'
        );
        $attemptStatement->execute([$newStatus->value, $newStatus->value, $attempt->id]);

        $orderStatement = $this->pdo->prepare(
            'UPDATE commerce_orders
             SET payment_status = ?,
                 paid_at = IF(? = "paid", COALESCE(paid_at, CURRENT_TIMESTAMP), paid_at),
                 cancelled_at = IF(? = "cancelled", COALESCE(cancelled_at, CURRENT_TIMESTAMP), cancelled_at),
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = ?'
        );
        $orderStatement->execute([$newStatus->value, $newStatus->value, $newStatus->value, $attempt->orderId]);

        $eventStatement = $this->pdo->prepare(
            'UPDATE commerce_payment_events
             SET payment_attempt_id = ?, processing_status = "processed", processed_at = CURRENT_TIMESTAMP
             WHERE provider = ? AND event_key = ?'
        );
        $eventStatement->execute([$attempt->id, $provider, $notification->eventKey]);

        $history = $this->pdo->prepare(
            'INSERT INTO commerce_order_status_history
                (order_id, dimension, from_status, to_status, actor_type, reason, metadata_json)
             VALUES (?, "payment", ?, ?, "webhook", "provider_notification", ?)'
        );
        $history->execute([
            $attempt->orderId,
            $attempt->paymentStatus->value,
            $newStatus->value,
            json_encode([
                'provider' => $provider,
                'event_key' => $notification->eventKey,
                'transaction_id_hash' => hash('sha256', $notification->providerTransactionId),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        ]);
    }

    private function paymentStatus(string $status): PaymentStatus
    {
        // Early provider attempts used "new" before the order moved to pending.
        return $status === 'new' ? PaymentStatus::Pending : PaymentStatus::from($status);
    }
}
