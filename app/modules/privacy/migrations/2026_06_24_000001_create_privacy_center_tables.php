<?php

return new class {
    public function up(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS privacy_settings (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `key` VARCHAR(190) NOT NULL UNIQUE,
                value JSON NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS privacy_categories (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                slug VARCHAR(80) NOT NULL UNIQUE,
                name VARCHAR(190) NOT NULL,
                short_description TEXT NULL,
                full_description MEDIUMTEXT NULL,
                is_required TINYINT(1) NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                sort_order INT NOT NULL DEFAULT 100,
                consent_mode_mapping_json JSON NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS privacy_scripts (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(190) NOT NULL,
                type VARCHAR(40) NOT NULL DEFAULT "custom",
                provider VARCHAR(120) NULL,
                category_id BIGINT UNSIGNED NULL,
                placement VARCHAR(40) NOT NULL DEFAULT "body_end",
                scope_type VARCHAR(60) NOT NULL DEFAULT "global",
                scope_rules_json JSON NULL,
                excluded_rules_json JSON NULL,
                code MEDIUMTEXT NULL,
                external_url VARCHAR(500) NULL,
                async_enabled TINYINT(1) NOT NULL DEFAULT 0,
                defer_enabled TINYINT(1) NOT NULL DEFAULT 0,
                priority INT NOT NULL DEFAULT 100,
                is_active TINYINT(1) NOT NULL DEFAULT 0,
                is_test_mode TINYINT(1) NOT NULL DEFAULT 0,
                risk_acknowledged_at TIMESTAMP NULL,
                created_by BIGINT UNSIGNED NULL,
                updated_by BIGINT UNSIGNED NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_privacy_scripts_category (category_id),
                INDEX idx_privacy_scripts_active (is_active, placement, priority)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS privacy_script_versions (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                script_id BIGINT UNSIGNED NOT NULL,
                version_number INT UNSIGNED NOT NULL,
                code MEDIUMTEXT NULL,
                external_url VARCHAR(500) NULL,
                settings_json JSON NULL,
                created_by BIGINT UNSIGNED NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_privacy_script_versions_script (script_id, version_number)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS privacy_consents (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                consent_uuid VARCHAR(80) NOT NULL,
                consent_version VARCHAR(80) NOT NULL,
                categories_json JSON NULL,
                consent_state_json JSON NULL,
                page_url VARCHAR(700) NULL,
                user_agent_hash CHAR(64) NULL,
                ip_hash CHAR(64) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_privacy_consents_uuid (consent_uuid),
                INDEX idx_privacy_consents_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS privacy_documents (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                type VARCHAR(60) NOT NULL,
                title VARCHAR(190) NOT NULL,
                slug VARCHAR(190) NOT NULL,
                content MEDIUMTEXT NULL,
                version INT UNSIGNED NOT NULL DEFAULT 1,
                status VARCHAR(40) NOT NULL DEFAULT "draft",
                published_at TIMESTAMP NULL,
                created_by BIGINT UNSIGNED NULL,
                updated_by BIGINT UNSIGNED NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_privacy_document_slug_version (slug, version),
                INDEX idx_privacy_documents_public (slug, status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS privacy_form_clauses (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(190) NOT NULL,
                type VARCHAR(60) NOT NULL,
                content MEDIUMTEXT NULL,
                version VARCHAR(80) NOT NULL DEFAULT "1.0",
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_by BIGINT UNSIGNED NULL,
                updated_by BIGINT UNSIGNED NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_privacy_form_clauses_type (type, is_active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS privacy_cookie_registry (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(190) NOT NULL,
                provider VARCHAR(120) NULL,
                category_id BIGINT UNSIGNED NULL,
                purpose TEXT NULL,
                duration VARCHAR(190) NULL,
                domain VARCHAR(190) NULL,
                source_script_id BIGINT UNSIGNED NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_privacy_cookie_registry_category (category_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS privacy_audit_log (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                actor_id BIGINT UNSIGNED NULL,
                action VARCHAR(120) NOT NULL,
                entity_type VARCHAR(120) NOT NULL,
                entity_id BIGINT UNSIGNED NULL,
                before_json JSON NULL,
                after_json JSON NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_privacy_audit_entity (entity_type, entity_id),
                INDEX idx_privacy_audit_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec('INSERT INTO cms_modules (slug, version, enabled, source, installed_at) VALUES ("privacy", "0.1.0", 1, "core", CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE enabled = 1, version = VALUES(version), source = "core"');

        $this->seedSettings($pdo);
        $this->seedCategories($pdo);
        $this->seedDocuments($pdo);
        $this->seedClauses($pdo);
    }

    private function seedSettings(PDO $pdo): void
    {
        $settings = [
            'module_enabled' => true,
            'allow_client_custom_scripts' => false,
            'emergency_disable_external_scripts' => false,
            'banner_mode' => 'modal',
            'banner_style' => 'minimal',
            'default_language' => 'pl',
            'consent_ttl_days' => 365,
            'consent_version' => '2026-06-24-1',
            'privacy_policy_version' => '1',
            'cookie_policy_version' => '1',
            'test_mode_admin_always_show' => false,
            'debug_mode' => false,
            'button_accept_all' => 'Akceptuje wszystko',
            'button_reject_all' => 'Odrzucam',
            'button_customize' => 'Dostosuj',
            'button_save' => 'Zapisz wybór',
            'banner_title' => 'Prywatność i cookies',
            'banner_text' => 'Używamy niezbędnych cookies do działania strony. Dodatkowe technologie uruchamiamy dopiero po Twojej zgodzie.',
            'footer_privacy_label' => 'Ustawienia prywatności',
            'consent_log_retention_days' => 395,
        ];

        $statement = $pdo->prepare('INSERT INTO privacy_settings (`key`, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = value');
        foreach ($settings as $key => $value) {
            $statement->execute([$key, json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);
        }
    }

    private function seedCategories(PDO $pdo): void
    {
        $categories = [
            ['necessary', 'Niezbędne', 'Sesja, bezpieczeństwo, CSRF i zapamiętanie decyzji.', 'Niezbędne technologie są wymagane do prawidłowego działania strony i panelu. Nie można ich wylaczyc.', 1, 1, 10, ['security_storage' => 'granted']],
            ['analytics', 'Analityczne', 'Pomiar ruchu, statystyki i diagnostyka.', 'Pomagaja mierzyc sposob korzystania że strony, np. GA4, Clarity lub Hotjar. Domyslnie zablokowane do czasu zgody.', 0, 1, 20, ['analytics_storage' => 'granted']],
            ['marketing', 'Marketingowe', 'Pomiar reklam i remarketing.', 'Uzywane przez Google Ads, Meta Pixel, TikTok Pixel lub LinkedIn Insight Tag. Domyslnie zablokowane do czasu zgody.', 0, 1, 30, ['ad_storage' => 'granted', 'ad_user_data' => 'granted', 'ad_personalization' => 'granted']],
            ['functional', 'Funkcjonalne', 'Mapy, wideo, czat i dodatkowe funkcje.', 'Pozwalają uruchamiac osadzone mapy, wideo, czaty lub inne funkcje, ktore nie są niezbędne technicznie.', 0, 1, 40, ['functionality_storage' => 'granted']],
            ['personalization', 'Personalizacyjne', 'Preferencje i personalizacja doświadczenia.', 'Pozwalają zapamiętywać preferencje i personalizować treści poza zakresem technicznie niezbędnym.', 0, 1, 50, ['personalization_storage' => 'granted']],
        ];

        $statement = $pdo->prepare('INSERT INTO privacy_categories (slug, name, short_description, full_description, is_required, is_active, sort_order, consent_mode_mapping_json) VALUES (?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE name = VALUES(name), short_description = VALUES(short_description), full_description = VALUES(full_description), is_required = VALUES(is_required), is_active = VALUES(is_active), sort_order = VALUES(sort_order), consent_mode_mapping_json = VALUES(consent_mode_mapping_json)');
        foreach ($categories as $category) {
            $statement->execute([
                $category[0],
                $category[1],
                $category[2],
                $category[3],
                $category[4],
                $category[5],
                $category[6],
                json_encode($category[7], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]);
        }
    }

    private function seedDocuments(PDO $pdo): void
    {
        $documents = [
            ['privacy_policy', 'Polityka prywatności', 'polityka-prywatności', "To jest edytowalny szablon polityki prywatności. Uzupelnij dane administratora, formularze, odbiorcow danych, narzędzia zewnętrzne i okresy retencji.\n\nTen dokument nie jest porada prawna.", 1],
            ['cookie_policy', 'Polityka cookies', 'polityka-cookies', "To jest edytowalny szablon polityki cookies. Uzupelnij liste cookies, kategorię zgod, dostawcow i czasy przechowywania.\n\nTen dokument nie jest porada prawna.", 1],
        ];

        $statement = $pdo->prepare('INSERT IGNORE INTO privacy_documents (type, title, slug, content, version, status) VALUES (?, ?, ?, ?, ?, "draft")');
        foreach ($documents as $document) {
            $statement->execute($document);
        }
    }

    private function seedClauses(PDO $pdo): void
    {
        $clauses = [
            ['Kontakt', 'contact', 'Administratorem danych jest właściciel strony. Dane z formularza przetwarzamy w celu obsługi zapytania i kontaktu zwrotnego.'],
            ['Newsletter', 'newsletter', 'Zgoda na newsletter jest dobrowolna i może byc wycofana w dowolnym momencie.'],
            ['Zapytanie ofertowe', 'quote', 'Dane przetwarzamy w celu przygotowania odpowiedzi lub oferty.'],
            ['Zamówienie', 'order', 'Dane przetwarzamy w celu realizacji zamówienia, płatności, dostawy i obowiazkow księgowych.'],
        ];

        $statement = $pdo->prepare('INSERT IGNORE INTO privacy_form_clauses (name, type, content, version, is_active) VALUES (?, ?, ?, "1.0", 1)');
        foreach ($clauses as $clause) {
            $statement->execute($clause);
        }
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS privacy_audit_log');
        $pdo->exec('DROP TABLE IF EXISTS privacy_cookie_registry');
        $pdo->exec('DROP TABLE IF EXISTS privacy_form_clauses');
        $pdo->exec('DROP TABLE IF EXISTS privacy_documents');
        $pdo->exec('DROP TABLE IF EXISTS privacy_consents');
        $pdo->exec('DROP TABLE IF EXISTS privacy_script_versions');
        $pdo->exec('DROP TABLE IF EXISTS privacy_scripts');
        $pdo->exec('DROP TABLE IF EXISTS privacy_categories');
        $pdo->exec('DROP TABLE IF EXISTS privacy_settings');
        $pdo->exec('DELETE FROM cms_modules WHERE slug = "privacy"');
    }
};
