<?php

declare(strict_types=1);

namespace Reklamova\Cms\Logging;

use PDO;

final class ActivityLogger
{
    public function __construct(private PDO $pdo, private string $salt)
    {
    }

    public function log(?array $user, string $action, ?string $entityType = null, ?int $entityId = null, mixed $before = null, mixed $after = null): void
    {
        try {
            $statement = $this->pdo->prepare(
                'INSERT INTO cms_activity_log (user_id, action, entity_type, entity_id, before_json, after_json, ip_hash, user_agent_hash)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $statement->execute([
                isset($user['id']) ? (int) $user['id'] : null,
                $action,
                $entityType,
                $entityId,
                $before === null ? null : json_encode($before, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                $after === null ? null : json_encode($after, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                hash('sha256', $this->salt . '|' . (string) ($_SERVER['REMOTE_ADDR'] ?? '')),
                hash('sha256', $this->salt . '|' . (string) ($_SERVER['HTTP_USER_AGENT'] ?? '')),
            ]);
        } catch (\Throwable) {
            return;
        }
    }
}
