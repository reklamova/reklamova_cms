<?php

return new class {
    public function up(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS catalog_categories (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                parent_id BIGINT UNSIGNED NULL,
                name VARCHAR(190) NOT NULL,
                slug VARCHAR(190) NOT NULL,
                full_path VARCHAR(700) NOT NULL,
                summary TEXT NULL,
                description MEDIUMTEXT NULL,
                featured_image VARCHAR(500) NULL,
                icon VARCHAR(120) NULL,
                status VARCHAR(40) NOT NULL DEFAULT "draft",
                sort_order INT NOT NULL DEFAULT 100,
                meta_title VARCHAR(190) NULL,
                meta_description TEXT NULL,
                og_image VARCHAR(500) NULL,
                settings_json JSON NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_catalog_categories_full_path (full_path),
                KEY idx_catalog_categories_parent (parent_id),
                KEY idx_catalog_categories_status_sort (status, sort_order)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS catalog_products (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                category_id BIGINT UNSIGNED NULL,
                name VARCHAR(220) NOT NULL,
                slug VARCHAR(220) NOT NULL,
                full_path VARCHAR(900) NOT NULL,
                sku VARCHAR(120) NULL,
                brand VARCHAR(160) NULL,
                model VARCHAR(160) NULL,
                summary TEXT NULL,
                description MEDIUMTEXT NULL,
                specs_json JSON NULL,
                gallery_json JSON NULL,
                documents_json JSON NULL,
                featured_image VARCHAR(500) NULL,
                status VARCHAR(40) NOT NULL DEFAULT "draft",
                is_featured TINYINT(1) NOT NULL DEFAULT 0,
                sort_order INT NOT NULL DEFAULT 100,
                meta_title VARCHAR(190) NULL,
                meta_description TEXT NULL,
                og_image VARCHAR(500) NULL,
                schema_json JSON NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_catalog_products_full_path (full_path),
                KEY idx_catalog_products_category (category_id),
                KEY idx_catalog_products_status_sort (status, sort_order),
                KEY idx_catalog_products_sku (sku)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS catalog_products');
        $pdo->exec('DROP TABLE IF EXISTS catalog_categories');
    }
};
