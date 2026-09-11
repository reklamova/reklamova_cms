<?php

declare(strict_types=1);

namespace Reklamova\Cms\Commerce\Notifications;

use PDO;

final class NotificationOutbox
{
    public function __construct(private PDO $pdo)
    {
    }

    public function enqueueCustomerPasswordLink(int $customerId, string $purpose, string $rawToken): void
    {
        if ($customerId <= 0 || !in_array($purpose, ['activate', 'reset'], true)) {
            throw new \InvalidArgumentException('Invalid customer password notification.');
        }
        if (strlen($rawToken) < 32 || strlen($rawToken) > 500) {
            throw new \InvalidArgumentException('Invalid customer password token.');
        }

        $this->pdo->prepare(
            'INSERT INTO commerce_outbox
                (event_id, event_type, aggregate_type, aggregate_id, payload_json)
             VALUES (?, ?, "customer", ?, ?)'
        )->execute([
            $this->uuid(),
            $purpose === 'activate'
                ? 'commerce.customer.activate_requested'
                : 'commerce.customer.reset_requested',
            (string) $customerId,
            json_encode(['token' => $rawToken], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        ]);
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
            . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }
}
