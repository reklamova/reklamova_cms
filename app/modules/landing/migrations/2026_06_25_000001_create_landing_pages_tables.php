<?php

return new class {
    public function up(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS landing_pages (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(190) NOT NULL,
                slug VARCHAR(190) NOT NULL UNIQUE,
                campaign_source VARCHAR(120) NULL,
                template_variant VARCHAR(80) NOT NULL DEFAULT "lead",
                hero_title VARCHAR(190) NOT NULL,
                hero_text TEXT NULL,
                hero_image VARCHAR(255) NULL,
                benefits_json JSON NULL,
                sections_json JSON NULL,
                faq_json JSON NULL,
                form_enabled TINYINT(1) NOT NULL DEFAULT 1,
                form_title VARCHAR(190) NULL,
                cta_label VARCHAR(120) NULL,
                thank_you_message TEXT NULL,
                meta_title VARCHAR(190) NULL,
                meta_description TEXT NULL,
                status VARCHAR(40) NOT NULL DEFAULT "draft",
                published_at DATETIME NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_landing_pages_status (status, published_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec('INSERT INTO cms_modules (slug, version, enabled, source, installed_at) VALUES ("landing", "0.1.0", 1, "core", CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE enabled = 1, version = VALUES(version), source = "core"');
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS landing_pages');
        $pdo->exec('DELETE FROM cms_modules WHERE slug = "landing"');
    }
};
