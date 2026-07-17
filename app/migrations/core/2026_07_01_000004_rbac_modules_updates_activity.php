<?php

return new class {
    public function up(PDO $pdo): void
    {
        $this->expandModules($pdo);
        $this->createPermissions($pdo);
        $this->expandPagesSeo($pdo);
        $this->createUpdateLog($pdo);
        $this->createActivityLog($pdo);
    }

    public function down(PDO $pdo): void
    {
    }

    private function expandModules(PDO $pdo): void
    {
        $this->addColumn($pdo, 'cms_modules', 'id', 'BIGINT UNSIGNED NULL FIRST');
        $this->addColumn($pdo, 'cms_modules', 'name', 'VARCHAR(190) NULL AFTER slug');
        $this->addColumn($pdo, 'cms_modules', 'locked', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER enabled');
        $this->addColumn($pdo, 'cms_modules', 'visible_in_client_nav', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER locked');
        $this->addColumn($pdo, 'cms_modules', 'visible_in_admin_nav', 'TINYINT(1) NOT NULL DEFAULT 1 AFTER visible_in_client_nav');
        $this->addColumn($pdo, 'cms_modules', 'client_manageable', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER visible_in_admin_nav');
        $this->addColumn($pdo, 'cms_modules', 'settings_json', 'JSON NULL AFTER client_manageable');
        $this->addColumn($pdo, 'cms_modules', 'enabled_at', 'TIMESTAMP NULL AFTER settings_json');
        $this->addColumn($pdo, 'cms_modules', 'disabled_at', 'TIMESTAMP NULL AFTER enabled_at');
        $this->addColumn($pdo, 'cms_modules', 'created_at', 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER disabled_at');

        $pdo->exec('UPDATE cms_modules SET enabled_at = COALESCE(enabled_at, installed_at, updated_at) WHERE enabled = 1');
        $pdo->exec('UPDATE cms_modules SET disabled_at = COALESCE(disabled_at, updated_at) WHERE enabled = 0');
        $pdo->exec('UPDATE cms_modules SET visible_in_admin_nav = 1 WHERE visible_in_admin_nav IS NULL');
        $pdo->exec('UPDATE cms_modules SET name = slug WHERE name IS NULL OR name = ""');
    }

    private function createPermissions(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS cms_permissions (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                slug VARCHAR(100) NOT NULL UNIQUE,
                name VARCHAR(190) NOT NULL,
                description TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS cms_role_permissions (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                role VARCHAR(60) NOT NULL,
                permission_id BIGINT UNSIGNED NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_role_permission (role, permission_id),
                INDEX idx_role_permissions_role (role)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS cms_user_permissions (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id BIGINT UNSIGNED NOT NULL,
                permission_id BIGINT UNSIGNED NOT NULL,
                allowed TINYINT(1) NOT NULL DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_user_permission (user_id, permission_id),
                INDEX idx_user_permissions_user (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $permissions = [
            'view_dashboard' => 'Start',
            'view_update_notice' => 'Komunikat o aktualizacji',
            'manage_pages' => 'Strony',
            'manage_media' => 'Media',
            'manage_forms' => 'Formularze',
            'manage_blog' => 'Blog / aktualnosci',
            'manage_products' => 'Produkty',
            'manage_basic_settings' => 'Ustawienia strony',
            'manage_basic_seo' => 'Podstawowe SEO',
            'manage_advanced_seo' => 'Zaawansowane SEO',
            'manage_modules' => 'Funkcje strony',
            'manage_themes' => 'Motywy',
            'manage_updates' => 'Aktualizacje CMS',
            'manage_backups' => 'Backupy',
            'view_logs' => 'Logi',
            'view_health' => 'Stan techniczny',
            'manage_users' => 'Uzytkownicy',
            'manage_permissions' => 'Uprawnienia',
            'manage_privacy' => 'Prywatnosc i cookies',
            'manage_privacy_scripts' => 'Skrypty prywatnosci',
            'view_developer_tools' => 'Narzedzia developerskie',
        ];

        $insertPermission = $pdo->prepare('INSERT INTO cms_permissions (slug, name) VALUES (?, ?) ON DUPLICATE KEY UPDATE name = VALUES(name)');
        foreach ($permissions as $slug => $name) {
            $insertPermission->execute([$slug, $name]);
        }

        $roleMap = [
            'client_admin' => ['view_dashboard', 'view_update_notice', 'manage_pages', 'manage_media', 'manage_forms', 'manage_blog', 'manage_products', 'manage_basic_settings', 'manage_basic_seo', 'manage_privacy'],
            'admin' => ['view_dashboard', 'view_update_notice', 'manage_pages', 'manage_media', 'manage_forms', 'manage_blog', 'manage_products', 'manage_basic_settings', 'manage_basic_seo', 'manage_privacy'],
            'editor' => ['view_dashboard', 'view_update_notice', 'manage_pages', 'manage_media', 'manage_blog', 'manage_basic_seo'],
            'seo' => ['view_dashboard', 'view_update_notice', 'manage_pages', 'manage_media', 'manage_basic_seo', 'manage_advanced_seo'],
            'marketing' => ['view_dashboard', 'view_update_notice', 'manage_pages', 'manage_media', 'manage_blog', 'manage_privacy', 'manage_privacy_scripts'],
        ];
        foreach (['super_admin', 'reklamova_admin', 'reklamova', 'developer'] as $role) {
            $roleMap[$role] = array_keys($permissions);
        }

        $permissionId = $pdo->prepare('SELECT id FROM cms_permissions WHERE slug = ? LIMIT 1');
        $insertRole = $pdo->prepare('INSERT IGNORE INTO cms_role_permissions (role, permission_id) VALUES (?, ?)');
        foreach ($roleMap as $role => $slugs) {
            foreach ($slugs as $slug) {
                $permissionId->execute([$slug]);
                $id = (int) $permissionId->fetchColumn();
                if ($id > 0) {
                    $insertRole->execute([$role, $id]);
                }
            }
        }
    }

    private function expandPagesSeo(PDO $pdo): void
    {
        foreach ([
            'meta_title' => 'VARCHAR(190) NULL',
            'meta_description' => 'TEXT NULL',
            'canonical_url' => 'VARCHAR(500) NULL',
            'robots' => 'VARCHAR(80) NULL DEFAULT "index,follow"',
            'og_title' => 'VARCHAR(190) NULL',
            'og_description' => 'TEXT NULL',
            'og_image' => 'VARCHAR(500) NULL',
            'seo_json' => 'JSON NULL',
        ] as $column => $definition) {
            $this->addColumn($pdo, 'cms_pages', $column, $definition);
        }
    }

    private function createUpdateLog(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS cms_update_log (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                from_version VARCHAR(40) NULL,
                to_version VARCHAR(40) NULL,
                package_id VARCHAR(120) NULL,
                status VARCHAR(40) NOT NULL,
                backup_id VARCHAR(120) NULL,
                started_at TIMESTAMP NULL,
                finished_at TIMESTAMP NULL,
                error_message TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_update_log_status (status),
                INDEX idx_update_log_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    private function createActivityLog(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS cms_activity_log (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id BIGINT UNSIGNED NULL,
                action VARCHAR(120) NOT NULL,
                entity_type VARCHAR(120) NULL,
                entity_id BIGINT UNSIGNED NULL,
                before_json JSON NULL,
                after_json JSON NULL,
                ip_hash CHAR(64) NULL,
                user_agent_hash CHAR(64) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_activity_entity (entity_type, entity_id),
                INDEX idx_activity_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    private function addColumn(PDO $pdo, string $table, string $column, string $definition): void
    {
        if ($this->columnExists($pdo, $table, $column)) {
            return;
        }

        $pdo->exec('ALTER TABLE `' . str_replace('`', '', $table) . '` ADD COLUMN `' . str_replace('`', '', $column) . '` ' . $definition);
    }

    private function columnExists(PDO $pdo, string $table, string $column): bool
    {
        $statement = $pdo->prepare('SHOW COLUMNS FROM `' . str_replace('`', '', $table) . '` LIKE ?');
        $statement->execute([$column]);

        return (bool) $statement->fetchColumn();
    }
};
