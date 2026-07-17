<?php

return new class {
    public function up(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS business_services (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                title VARCHAR(190) NOT NULL,
                slug VARCHAR(190) NOT NULL UNIQUE,
                summary TEXT NULL,
                description MEDIUMTEXT NULL,
                icon VARCHAR(80) NULL,
                featured_image VARCHAR(255) NULL,
                meta_title VARCHAR(190) NULL,
                meta_description TEXT NULL,
                status VARCHAR(40) NOT NULL DEFAULT "draft",
                sort_order INT NOT NULL DEFAULT 100,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_business_services_status_sort (status, sort_order)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS business_service_areas (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(190) NOT NULL,
                slug VARCHAR(190) NOT NULL UNIQUE,
                region VARCHAR(190) NULL,
                summary TEXT NULL,
                description MEDIUMTEXT NULL,
                meta_title VARCHAR(190) NULL,
                meta_description TEXT NULL,
                status VARCHAR(40) NOT NULL DEFAULT "draft",
                sort_order INT NOT NULL DEFAULT 100,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_business_areas_status_sort (status, sort_order)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS business_case_studies (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                title VARCHAR(190) NOT NULL,
                slug VARCHAR(190) NOT NULL UNIQUE,
                client_name VARCHAR(190) NULL,
                industry VARCHAR(190) NULL,
                summary TEXT NULL,
                challenge MEDIUMTEXT NULL,
                solution MEDIUMTEXT NULL,
                result MEDIUMTEXT NULL,
                cover_image VARCHAR(255) NULL,
                meta_title VARCHAR(190) NULL,
                meta_description TEXT NULL,
                status VARCHAR(40) NOT NULL DEFAULT "draft",
                sort_order INT NOT NULL DEFAULT 100,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_business_cases_status_sort (status, sort_order)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS business_testimonials (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                author VARCHAR(190) NOT NULL,
                company VARCHAR(190) NULL,
                role VARCHAR(190) NULL,
                quote TEXT NOT NULL,
                rating TINYINT UNSIGNED NULL,
                source_url VARCHAR(255) NULL,
                is_featured TINYINT(1) NOT NULL DEFAULT 0,
                status VARCHAR(40) NOT NULL DEFAULT "draft",
                sort_order INT NOT NULL DEFAULT 100,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_business_testimonials_status_sort (status, sort_order)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS business_faqs (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                question VARCHAR(255) NOT NULL,
                answer MEDIUMTEXT NOT NULL,
                scope_type VARCHAR(60) NOT NULL DEFAULT "global",
                scope_slug VARCHAR(190) NULL,
                status VARCHAR(40) NOT NULL DEFAULT "draft",
                sort_order INT NOT NULL DEFAULT 100,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_business_faqs_scope (scope_type, scope_slug, status, sort_order)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS business_team_members (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(190) NOT NULL,
                role VARCHAR(190) NULL,
                bio TEXT NULL,
                photo VARCHAR(255) NULL,
                email VARCHAR(190) NULL,
                phone VARCHAR(80) NULL,
                status VARCHAR(40) NOT NULL DEFAULT "draft",
                sort_order INT NOT NULL DEFAULT 100,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_business_team_status_sort (status, sort_order)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS business_ctas (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(190) NOT NULL,
                placement VARCHAR(100) NOT NULL DEFAULT "global",
                headline VARCHAR(190) NOT NULL,
                text TEXT NULL,
                button_label VARCHAR(120) NULL,
                button_url VARCHAR(255) NULL,
                status VARCHAR(40) NOT NULL DEFAULT "draft",
                sort_order INT NOT NULL DEFAULT 100,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_business_ctas_placement (placement, status, sort_order)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec('INSERT INTO cms_modules (slug, version, enabled, source, installed_at) VALUES ("business", "0.1.0", 1, "core", CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE enabled = 1, version = VALUES(version), source = "core"');
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS business_ctas');
        $pdo->exec('DROP TABLE IF EXISTS business_team_members');
        $pdo->exec('DROP TABLE IF EXISTS business_faqs');
        $pdo->exec('DROP TABLE IF EXISTS business_testimonials');
        $pdo->exec('DROP TABLE IF EXISTS business_case_studies');
        $pdo->exec('DROP TABLE IF EXISTS business_service_areas');
        $pdo->exec('DROP TABLE IF EXISTS business_services');
        $pdo->exec('DELETE FROM cms_modules WHERE slug = "business"');
    }
};
