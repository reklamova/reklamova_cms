<?php

declare(strict_types=1);

namespace Reklamova\Cms\Commerce\Analytics;

use PDO;
use PDOException;

final class AnalyticsEventRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @param array<string, mixed> $payload */
    public function claimOnce(string $eventName, string $aggregateType, string $aggregateId, array $payload = []): bool
    {
        foreach ([$eventName, $aggregateType, $aggregateId] as $value) {
            if (trim($value) === '' || strlen($value) > 190) {
                throw new \InvalidArgumentException('Analytics event identity is invalid.');
            }
        }
        $encoded = json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
        );
        $statement = $this->pdo->prepare(
            'INSERT INTO commerce_analytics_events
                (event_name, aggregate_type, aggregate_id, payload_hash)
             VALUES (?, ?, ?, ?)'
        );
        try {
            $statement->execute([$eventName, $aggregateType, $aggregateId, hash('sha256', $encoded)]);

            return true;
        } catch (PDOException $exception) {
            if ($this->isDuplicate($exception)) {
                return false;
            }
            throw $exception;
        }
    }

    private function isDuplicate(PDOException $exception): bool
    {
        return (string) $exception->getCode() === '23000'
            && (int) ($exception->errorInfo[1] ?? 0) === 1062;
    }
}
