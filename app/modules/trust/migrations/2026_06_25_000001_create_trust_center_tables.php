<?php

return new class {
    public function up(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS trust_items (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                type VARCHAR(60) NOT NULL DEFAULT "certificate",
                title VARCHAR(190) NOT NULL,
                subtitle VARCHAR(190) NULL,
                description TEXT NULL,
                value VARCHAR(120) NULL,
                image VARCHAR(255) NULL,
                file_url VARCHAR(255) NULL,
                external_url VARCHAR(255) NULL,
                is_featured TINYINT(1) NOT NULL DEFAULT 0,
                status VARCHAR(40) NOT NULL DEFAULT "draft",
                sort_order INT NOT NULL DEFAULT 100,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_trust_items_type_status (type, status, sort_order)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec('INSERT INTO cms_modules (slug, version, enabled, source, installed_at) VALUES ("trust", "0.1.0", 1, "core", CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE enabled = 1, version = VALUES(version), source = "core"');
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS trust_items');
        $pdo->exec('DELETE FROM cms_modules WHERE slug = "trust"');
    }
};
