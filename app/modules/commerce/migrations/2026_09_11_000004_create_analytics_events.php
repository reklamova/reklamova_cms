<?php

declare(strict_types=1);

return new class {
    public function up(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS commerce_analytics_events (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                event_name VARCHAR(120) NOT NULL,
                aggregate_type VARCHAR(80) NOT NULL,
                aggregate_id VARCHAR(190) NOT NULL,
                payload_hash CHAR(64) NULL,
                claimed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_commerce_analytics_once (event_name, aggregate_type, aggregate_id),
                KEY idx_commerce_analytics_claimed (claimed_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS commerce_analytics_events');
    }
};
