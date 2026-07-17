<?php

return new class {
    public function up(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS knowledge_categories (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(190) NOT NULL,
                slug VARCHAR(190) NOT NULL UNIQUE,
                description TEXT NULL,
                sort_order INT NOT NULL DEFAULT 100,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS knowledge_authors (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(190) NOT NULL,
                slug VARCHAR(190) NOT NULL UNIQUE,
                bio TEXT NULL,
                photo VARCHAR(255) NULL,
                role VARCHAR(190) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS knowledge_articles (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                title VARCHAR(190) NOT NULL,
                slug VARCHAR(190) NOT NULL UNIQUE,
                excerpt TEXT NULL,
                content MEDIUMTEXT NULL,
                cover_image VARCHAR(255) NULL,
                category_id BIGINT UNSIGNED NULL,
                author_id BIGINT UNSIGNED NULL,
                tags_json JSON NULL,
                related_service_slug VARCHAR(190) NULL,
                related_area_slug VARCHAR(190) NULL,
                meta_title VARCHAR(190) NULL,
                meta_description TEXT NULL,
                status VARCHAR(40) NOT NULL DEFAULT "draft",
                published_at DATETIME NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_knowledge_articles_status_date (status, published_at),
                INDEX idx_knowledge_articles_category (category_id),
                INDEX idx_knowledge_articles_author (author_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec('INSERT INTO cms_modules (slug, version, enabled, source, installed_at) VALUES ("knowledge", "0.1.0", 1, "core", CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE enabled = 1, version = VALUES(version), source = "core"');
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS knowledge_articles');
        $pdo->exec('DROP TABLE IF EXISTS knowledge_authors');
        $pdo->exec('DROP TABLE IF EXISTS knowledge_categories');
        $pdo->exec('DELETE FROM cms_modules WHERE slug = "knowledge"');
    }
};
