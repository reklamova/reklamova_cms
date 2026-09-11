<?php

declare(strict_types=1);

namespace Reklamova\Cms\Commerce\Notifications;

use PDO;
use Reklamova\Cms\Support\EmailSenderInterface;

final class NotificationWorker
{
    public function __construct(
        private PDO $pdo,
        private EmailSenderInterface $sender,
        private string $siteUrl,
        private string $siteName,
    ) {
        $this->siteUrl = rtrim($this->siteUrl, '/');
        $this->siteName = trim($this->siteName) ?: 'Sklep Reklamova';
    }

    public function processNext(): string
    {
        $event = $this->claim();
        if ($event === null) {
            return 'empty';
        }

        try {
            $notification = $this->notification($event);
            if ($notification === null) {
                $this->complete((int) $event['id']);
                return 'ignored';
            }
            $this->startDelivery($event, $notification);
            if (!$this->sender->send($notification['to'], $notification['subject'], $notification['body'])) {
                throw new \RuntimeException('Email sender rejected the notification.');
            }
            $this->finishDelivery((int) $event['id'], (string) $notification['template']);
            $this->complete((int) $event['id']);

            return 'sent';
        } catch (\Throwable $exception) {
            $this->fail((int) $event['id'], $exception);

            return 'failed';
        }
    }

    /** @return array<string, mixed>|null */
    private function claim(): ?array
    {
        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->query(
                'SELECT id, event_type, aggregate_type, aggregate_id, payload_json, attempts
                 FROM commerce_outbox
                 WHERE ((status = "pending") OR (status = "processing" AND available_at <= CURRENT_TIMESTAMP))
                   AND available_at <= CURRENT_TIMESTAMP AND attempts < 5
                 ORDER BY id LIMIT 1 FOR UPDATE SKIP LOCKED'
            );
            $event = $statement->fetch(PDO::FETCH_ASSOC);
            if (!$event) {
                $this->pdo->commit();
                return null;
            }
            $this->pdo->prepare(
                'UPDATE commerce_outbox
                 SET status = "processing", attempts = attempts + 1,
                     available_at = DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 10 MINUTE)
                 WHERE id = ?'
            )->execute([$event['id']]);
            $this->pdo->commit();
            $event['attempts'] = (int) $event['attempts'] + 1;

            return $event;
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    /** @param array<string, mixed> $event @return array<string, string>|null */
    private function notification(array $event): ?array
    {
        $type = (string) $event['event_type'];
        if ($type === 'commerce.order.created' || $type === 'commerce.order.paid') {
            return $this->orderNotification((int) $event['aggregate_id'], $type);
        }
        if ($type === 'commerce.customer.activate_requested' || $type === 'commerce.customer.reset_requested') {
            $payload = json_decode((string) $event['payload_json'], true, 16, JSON_THROW_ON_ERROR);
            return $this->passwordNotification(
                (int) $event['aggregate_id'],
                $type,
                (string) ($payload['token'] ?? ''),
            );
        }

        return null;
    }

    /** @return array<string, string>|null */
    private function orderNotification(int $orderId, string $eventType): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT o.order_number, o.customer_email, o.total_minor, o.currency,
                    o.order_status, o.payment_status, o.customer_id
             FROM commerce_orders o WHERE o.id = ? LIMIT 1'
        );
        $statement->execute([$orderId]);
        $order = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$order || !filter_var($order['customer_email'], FILTER_VALIDATE_EMAIL)) {
            return null;
        }
        $number = (string) $order['order_number'];
        $total = number_format((int) $order['total_minor'] / 100, 2, ',', ' ') . ' ' . strtoupper((string) $order['currency']);
        $accountLine = $order['customer_id'] === null
            ? "Status zamówienia sprawdzisz na urządzeniu, na którym zostało złożone.\n"
            : "Historia zamówień: {$this->siteUrl}/moje-konto\n";

        if ($eventType === 'commerce.order.paid') {
            return [
                'to' => (string) $order['customer_email'],
                'subject' => "Płatność za zamówienie {$number} potwierdzona",
                'body' => "Dzień dobry,\n\npotwierdzamy płatność za zamówienie {$number}.\nKwota: {$total}\n{$accountLine}\nRozpoczynamy obsługę zamówienia.\n\n{$this->siteName}",
                'template' => 'order_paid',
            ];
        }

        return [
            'to' => (string) $order['customer_email'],
            'subject' => "Potwierdzenie zamówienia {$number}",
            'body' => "Dzień dobry,\n\nzapisaliśmy zamówienie {$number}.\nKwota: {$total}\nStatus płatności: {$order['payment_status']}\n{$accountLine}\nDziękujemy za zamówienie.\n\n{$this->siteName}",
            'template' => 'order_created',
        ];
    }

    /** @return array<string, string>|null */
    private function passwordNotification(int $customerId, string $eventType, string $token): ?array
    {
        if (strlen($token) < 32 || strlen($token) > 500) {
            return null;
        }
        $statement = $this->pdo->prepare(
            'SELECT email, first_name FROM commerce_customers WHERE id = ? AND status = "active" LIMIT 1'
        );
        $statement->execute([$customerId]);
        $customer = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$customer || !filter_var($customer['email'], FILTER_VALIDATE_EMAIL)) {
            return null;
        }
        $activation = $eventType === 'commerce.customer.activate_requested';
        $name = trim((string) ($customer['first_name'] ?? '')) ?: 'Dzień dobry';
        $link = $this->siteUrl . '/moje-konto/haslo?token=' . rawurlencode($token);

        return [
            'to' => (string) $customer['email'],
            'subject' => $activation ? 'Aktywuj konto klienta' : 'Ustaw nowe hasło do konta',
            'body' => "{$name},\n\n" . ($activation
                ? "ustaw nowe hasło, aby aktywować konto po migracji sklepu:\n"
                : "otrzymaliśmy prośbę o ustawienie nowego hasła:\n")
                . "{$link}\n\nLink jest jednorazowy i wygaśnie po godzinie. Jeśli to nie Ty wysyłasz prośbę, zignoruj tę wiadomość.\n\n{$this->siteName}",
            'template' => $activation ? 'customer_activate' : 'customer_reset',
        ];
    }

    /** @param array<string, mixed> $event @param array<string, string> $notification */
    private function startDelivery(array $event, array $notification): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO commerce_notification_deliveries
                (outbox_id, order_id, channel, template_key, recipient_hash, status, attempts)
             VALUES (?, ?, "email", ?, ?, "processing", 1)
             ON DUPLICATE KEY UPDATE status = "processing", attempts = attempts + 1,
                last_error_code = NULL, updated_at = CURRENT_TIMESTAMP'
        );
        $statement->execute([
            $event['id'],
            $event['aggregate_type'] === 'order' ? (int) $event['aggregate_id'] : null,
            $notification['template'],
            hash('sha256', mb_strtolower($notification['to'], 'UTF-8')),
        ]);
    }

    private function finishDelivery(int $outboxId, string $template): void
    {
        $this->pdo->prepare(
            'UPDATE commerce_notification_deliveries
             SET status = "sent", sent_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
             WHERE outbox_id = ? AND channel = "email" AND template_key = ?'
        )->execute([$outboxId, $template]);
    }

    private function complete(int $outboxId): void
    {
        $this->pdo->prepare(
            'UPDATE commerce_outbox SET status = "processed", processed_at = CURRENT_TIMESTAMP,
                last_error_code = NULL WHERE id = ?'
        )->execute([$outboxId]);
    }

    private function fail(int $outboxId, \Throwable $exception): void
    {
        $statement = $this->pdo->prepare('SELECT attempts FROM commerce_outbox WHERE id = ? LIMIT 1');
        $statement->execute([$outboxId]);
        $attempts = (int) $statement->fetchColumn();
        $terminal = $attempts >= 5;
        $code = substr(hash('sha256', $exception::class . '|' . $exception->getMessage()), 0, 32);
        $delayMinutes = min(60, 2 ** max(0, $attempts - 1));
        $this->pdo->prepare(
            'UPDATE commerce_outbox SET status = ?, available_at = DATE_ADD(CURRENT_TIMESTAMP, INTERVAL ? MINUTE),
                last_error_code = ? WHERE id = ?'
        )->execute([$terminal ? 'failed' : 'pending', $delayMinutes, $code, $outboxId]);
        $this->pdo->prepare(
            'UPDATE commerce_notification_deliveries SET status = "failed", last_error_code = ?,
                updated_at = CURRENT_TIMESTAMP WHERE outbox_id = ?'
        )->execute([$code, $outboxId]);
    }
}
