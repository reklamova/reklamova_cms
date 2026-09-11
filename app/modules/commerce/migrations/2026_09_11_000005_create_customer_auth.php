<?php

declare(strict_types=1);

return new class {
    public function up(PDO $pdo): void
    {
        $options = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS commerce_customer_tokens (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                customer_id BIGINT UNSIGNED NOT NULL,
                purpose VARCHAR(30) NOT NULL,
                token_hash CHAR(64) NOT NULL,
                expires_at TIMESTAMP NOT NULL,
                used_at TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_commerce_customer_token (token_hash),
                KEY idx_commerce_customer_token_active (customer_id, purpose, used_at, expires_at),
                CONSTRAINT fk_commerce_customer_token_customer
                    FOREIGN KEY (customer_id) REFERENCES commerce_customers(id) ON DELETE CASCADE
            )' . $options
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS commerce_customer_auth_attempts (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                store_id BIGINT UNSIGNED NOT NULL,
                action VARCHAR(30) NOT NULL,
                identity_hash CHAR(64) NOT NULL,
                succeeded TINYINT(1) NOT NULL DEFAULT 0,
                attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY idx_commerce_auth_limit (store_id, action, identity_hash, succeeded, attempted_at),
                KEY idx_commerce_auth_cleanup (attempted_at),
                CONSTRAINT fk_commerce_auth_attempt_store
                    FOREIGN KEY (store_id) REFERENCES commerce_stores(id) ON DELETE CASCADE
            )' . $options
        );
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS commerce_customer_auth_attempts');
        $pdo->exec('DROP TABLE IF EXISTS commerce_customer_tokens');
    }
};
