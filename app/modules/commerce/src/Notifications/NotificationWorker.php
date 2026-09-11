<?php

declare(strict_types=1);

namespace Reklamova\Cms\Commerce\Notifications;

use PDO;
use Reklamova\Cms\Support\EmailTemplate;
use Reklamova\Cms\Support\EmailSenderInterface;

final class NotificationWorker
{
    private EmailTemplate $template;

    public function __construct(
        private PDO $pdo,
        private EmailSenderInterface $sender,
        private string $siteUrl,
        private string $siteName,
        ?EmailTemplate $template = null,
    ) {
        $this->siteUrl = rtrim($this->siteUrl, '/');
        $this->siteName = trim($this->siteName) ?: 'Sklep Reklamova';
        $this->template = $template ?? new EmailTemplate();
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
        $payload = json_decode((string) $event['payload_json'], true, 16, JSON_THROW_ON_ERROR);
        $payload = is_array($payload) ? $payload : [];
        if (in_array($type, [
            'commerce.order.created',
            'commerce.order.paid',
            'commerce.order.payment_failed',
            'commerce.order.status_changed',
            'commerce.order.shipped',
            'commerce.order.cancelled',
        ], true)) {
            return $this->orderNotification((int) $event['aggregate_id'], $type, $payload);
        }
        if ($type === 'commerce.customer.activate_requested' || $type === 'commerce.customer.reset_requested') {
            return $this->passwordNotification(
                (int) $event['aggregate_id'],
                $type,
                (string) ($payload['token'] ?? ''),
            );
        }

        return null;
    }

    /** @return array<string, string>|null */
    private function orderNotification(int $orderId, string $eventType, array $payload = []): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT o.order_number, o.customer_email, o.total_minor, o.currency,
                    o.order_status, o.payment_status, o.customer_id,
                    (SELECT s.tracking_number FROM commerce_order_shipments s WHERE s.order_id=o.id ORDER BY s.id DESC LIMIT 1) AS tracking_number,
                    (SELECT s.tracking_url FROM commerce_order_shipments s WHERE s.order_id=o.id ORDER BY s.id DESC LIMIT 1) AS tracking_url
             FROM commerce_orders o WHERE o.id = ? LIMIT 1'
        );
        $statement->execute([$orderId]);
        $order = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$order || !filter_var($order['customer_email'], FILTER_VALIDATE_EMAIL)) {
            return null;
        }
        $number = (string) $order['order_number'];
        $total = number_format((int) $order['total_minor'] / 100, 2, ',', ' ') . ' ' . strtoupper((string) $order['currency']);
        $action = $order['customer_id'] === null
            ? ['label' => 'Przejdź do sklepu', 'url' => $this->siteUrl]
            : ['label' => 'Zobacz swoje zamówienia', 'url' => $this->siteUrl . '/moje-konto'];
        $facts = [
            'Numer zamówienia' => $number,
            'Kwota' => $total,
            'Płatność' => $this->status((string) $order['payment_status']),
            'Realizacja' => $this->status((string) ($payload['status'] ?? $order['order_status'])),
        ];

        if ($eventType === 'commerce.order.paid') {
            return [
                'to' => (string) $order['customer_email'],
                'subject' => "Płatność za zamówienie {$number} potwierdzona",
                'body' => $this->template->render($this->siteName, 'Płatność została przyjęta.', 'Płatność potwierdzona', ['Dziękujemy. Płatność dotarła prawidłowo i możemy rozpocząć obsługę zamówienia.'], $facts, $action),
                'template' => 'order_paid',
            ];
        }
        if ($eventType === 'commerce.order.payment_failed') {
            return [
                'to' => (string) $order['customer_email'],
                'subject' => "Problem z płatnością za zamówienie {$number}",
                'body' => $this->template->render($this->siteName, 'Płatność wymaga ponowienia.', 'Nie udało się potwierdzić płatności', ['Zamówienie jest zapisane, ale operator nie potwierdził płatności. Zaloguj się do konta i bezpiecznie ponów próbę.'], $facts, $action, 'Nie przesyłaj danych karty ani danych logowania w odpowiedzi na tę wiadomość.'),
                'template' => 'order_payment_failed',
            ];
        }
        if ($eventType === 'commerce.order.shipped') {
            if (!empty($order['tracking_number'])) {
                $facts['Numer przesyłki'] = (string) $order['tracking_number'];
            }
            $trackingAction = !empty($order['tracking_url'])
                ? ['label' => 'Śledź przesyłkę', 'url' => (string) $order['tracking_url']]
                : $action;
            return [
                'to' => (string) $order['customer_email'],
                'subject' => "Zamówienie {$number} zostało wysłane",
                'body' => $this->template->render($this->siteName, 'Twoje zamówienie jest w drodze.', 'Zamówienie wysłane', ['Gotowe materiały zostały przekazane do doręczenia.'], $facts, $trackingAction),
                'template' => 'order_shipped',
            ];
        }
        if ($eventType === 'commerce.order.cancelled') {
            return [
                'to' => (string) $order['customer_email'],
                'subject' => "Zamówienie {$number} zostało anulowane",
                'body' => $this->template->render($this->siteName, 'Zamówienie zostało anulowane.', 'Zamówienie anulowane', ['Wstrzymaliśmy realizację tego zamówienia. Jeśli masz pytania, odpowiedz na tę wiadomość.'], $facts, $action),
                'template' => 'order_cancelled',
            ];
        }
        if ($eventType === 'commerce.order.status_changed') {
            return [
                'to' => (string) $order['customer_email'],
                'subject' => "Nowy status zamówienia {$number}",
                'body' => $this->template->render($this->siteName, 'Status zamówienia został zaktualizowany.', 'Zamówienie zmieniło status', ['Aktualny etap realizacji znajdziesz poniżej oraz na swoim koncie klienta.'], $facts, $action),
                'template' => 'order_status_changed',
            ];
        }

        $awaiting = in_array((string) $order['payment_status'], ['unpaid', 'pending'], true);

        return [
            'to' => (string) $order['customer_email'],
            'subject' => $awaiting ? "Zamówienie {$number} oczekuje na płatność" : "Potwierdzenie zamówienia {$number}",
            'body' => $this->template->render(
                $this->siteName,
                $awaiting ? 'Zamówienie jest zapisane i oczekuje na płatność.' : 'Zamówienie zostało zapisane.',
                $awaiting ? 'Czekamy na potwierdzenie płatności' : 'Dziękujemy za zamówienie',
                [$awaiting ? 'Operator płatności potwierdzi wynik niezależnie. Sam powrót do sklepu nie oznacza jeszcze opłacenia.' : 'Przyjęliśmy zamówienie do dalszej obsługi.'],
                $facts,
                $action,
            ),
            'template' => $awaiting ? 'order_awaiting_payment' : 'order_created',
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
            'body' => $this->template->render(
                $this->siteName,
                $activation ? 'Aktywuj konto po migracji sklepu.' : 'Ustaw nowe hasło do konta.',
                $activation ? 'Aktywuj swoje konto' : 'Ustaw nowe hasło',
                ["{$name}, " . ($activation ? 'ustaw własne hasło, aby bezpiecznie korzystać z konta po migracji sklepu.' : 'otrzymaliśmy prośbę o ustawienie nowego hasła.')],
                ['Ważność linku' => '1 godzina', 'Użycie' => 'jednorazowe'],
                ['label' => $activation ? 'Aktywuj konto' : 'Ustaw nowe hasło', 'url' => $link],
                'Jeśli to nie Ty wysyłasz prośbę, zignoruj tę wiadomość.',
            ),
            'template' => $activation ? 'customer_activate' : 'customer_reset',
        ];
    }

    private function status(string $status): string
    {
        return [
            'unpaid' => 'nieopłacona',
            'pending' => 'weryfikowana',
            'paid' => 'opłacona',
            'failed' => 'nieudana',
            'cancelled' => 'anulowana',
            'refunded' => 'zwrócona',
            'new' => 'nowe',
            'awaiting_files' => 'oczekuje na pliki',
            'files_received' => 'pliki przyjęte',
            'in_production' => 'w produkcji',
            'ready' => 'gotowe',
            'shipped' => 'wysłane',
            'completed' => 'zakończone',
        ][$status] ?? $status;
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
                last_error_code = NULL,
                payload_json = CASE
                    WHEN event_type IN ("commerce.customer.activate_requested", "commerce.customer.reset_requested")
                    THEN JSON_OBJECT()
                    ELSE payload_json
                END
             WHERE id = ?'
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
