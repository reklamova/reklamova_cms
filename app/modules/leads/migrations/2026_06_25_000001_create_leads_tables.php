<?php

return new class {
    public function up(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS cms_leads (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                public_id VARCHAR(40) NOT NULL UNIQUE,
                source VARCHAR(120) NOT NULL DEFAULT "website",
                form_type VARCHAR(120) NOT NULL DEFAULT "contact",
                status VARCHAR(40) NOT NULL DEFAULT "new",
                name VARCHAR(190) NULL,
                email VARCHAR(190) NULL,
                phone VARCHAR(80) NULL,
                company VARCHAR(190) NULL,
                message MEDIUMTEXT NULL,
                page_url VARCHAR(255) NULL,
                consent_payload_json JSON NULL,
                payload_json JSON NULL,
                note TEXT NULL,
                ip_hash VARCHAR(64) NULL,
                user_agent_hash VARCHAR(64) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_cms_leads_status_created (status, created_at),
                INDEX idx_cms_leads_form_type (form_type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS cms_lead_status_log (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                lead_id BIGINT UNSIGNED NOT NULL,
                old_status VARCHAR(40) NULL,
                new_status VARCHAR(40) NOT NULL,
                actor_id BIGINT UNSIGNED NULL,
                note TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_cms_lead_status_log_lead (lead_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec('INSERT INTO cms_modules (slug, version, enabled, source, installed_at) VALUES ("leads", "0.1.0", 1, "core", CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE enabled = 1, version = VALUES(version), source = "core"');
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS cms_lead_status_log');
        $pdo->exec('DROP TABLE IF EXISTS cms_leads');
        $pdo->exec('DELETE FROM cms_modules WHERE slug = "leads"');
    }
};
