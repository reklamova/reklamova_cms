<?php

use PDO;

return new class {
    public function up(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS cms_settings (
                setting_key VARCHAR(190) PRIMARY KEY,
                setting_value JSON NULL,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS cms_modules (
                slug VARCHAR(100) PRIMARY KEY,
                version VARCHAR(40) NOT NULL,
                enabled TINYINT(1) NOT NULL DEFAULT 0,
                source VARCHAR(40) NOT NULL DEFAULT "official",
                installed_at TIMESTAMP NULL,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS cms_modules');
        $pdo->exec('DROP TABLE IF EXISTS cms_settings');
    }
};

