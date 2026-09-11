<?php

declare(strict_types=1);

return new class {
    public function up(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS commerce_payment_methods (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                store_id BIGINT UNSIGNED NOT NULL,
                code VARCHAR(120) NOT NULL,
                name VARCHAR(190) NOT NULL,
                provider VARCHAR(120) NULL,
                type VARCHAR(40) NOT NULL DEFAULT "online",
                active TINYINT(1) NOT NULL DEFAULT 1,
                rules_json JSON NULL,
                sort_order INT NOT NULL DEFAULT 100,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_commerce_payment_method_code (store_id, code),
                KEY idx_commerce_payment_method_active (store_id, active, sort_order),
                CONSTRAINT fk_commerce_payment_method_store
                    FOREIGN KEY (store_id) REFERENCES commerce_stores(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS commerce_payment_methods');
    }
};
