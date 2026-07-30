<?php

return new class {
    public function up(PDO $pdo): void
    {
        $this->seedPermissions($pdo);
        $this->seedRolePermissions($pdo);
        $this->updateModuleMetadata($pdo);
    }

    public function down(PDO $pdo): void
    {
    }

    private function seedPermissions(PDO $pdo): void
    {
        if (!$this->tableExists($pdo, 'cms_permissions')) {
            return;
        }

        $permissions = [
            'view_dashboard' => 'Start',
            'view_update_notice' => 'Komunikat o aktualizacji',
            'manage_pages' => 'Podstrony',
            'manage_homepage' => 'Strona główna',
            'manage_media' => 'Media',
            'manage_blog' => 'Poradnik',
            'manage_products' => 'Produkty',
            'manage_product_categories' => 'Kategorie produktów',
            'manage_forms' => 'Formularze',
            'view_leads' => 'Podgląd zapytań',
            'manage_inquiries' => 'Obsługa zapytań',
            'manage_privacy_basic' => 'Prywatność i cookies',
            'manage_privacy_scripts' => 'Skrypty prywatności',
            'manage_reviews_trust' => 'Opinie i wiarygodność',
            'manage_campaign_pages' => 'Strony kampanii',
            'manage_basic_settings' => 'Dane strony',
            'manage_basic_seo' => 'Podstawowe SEO',
            'manage_advanced_seo' => 'Zaawansowane SEO',
            'manage_modules' => 'Moduły strony',
            'manage_theme' => 'Motyw strony',
            'manage_updates' => 'Aktualizacje CMS',
            'view_system_health' => 'Stan systemu',
            'manage_backups' => 'Backupy',
            'view_logs' => 'Logi',
            'manage_users' => 'Użytkownicy',
            'manage_permissions' => 'Uprawnienia',
            'view_developer_tools' => 'Narzędzia developerskie',
            'manage_privacy' => 'Prywatność i cookies (alias)',
            'manage_themes' => 'Motyw strony (alias)',
            'view_health' => 'Stan systemu (alias)',
        ];

        $statement = $pdo->prepare('INSERT INTO cms_permissions (slug, name) VALUES (?, ?) ON DUPLICATE KEY UPDATE name = VALUES(name)');
        foreach ($permissions as $slug => $name) {
            $statement->execute([$slug, $name]);
        }
    }

    private function seedRolePermissions(PDO $pdo): void
    {
        if (!$this->tableExists($pdo, 'cms_permissions') || !$this->tableExists($pdo, 'cms_role_permissions')) {
            return;
        }

        $roleMap = [
            'client_admin' => ['view_dashboard', 'view_update_notice', 'manage_pages', 'manage_homepage', 'manage_media', 'manage_forms', 'manage_blog', 'manage_products', 'manage_product_categories', 'view_leads', 'manage_inquiries', 'manage_basic_settings', 'manage_basic_seo', 'manage_privacy_basic', 'manage_privacy'],
            'admin' => ['view_dashboard', 'view_update_notice', 'manage_pages', 'manage_homepage', 'manage_media', 'manage_forms', 'manage_blog', 'manage_products', 'manage_product_categories', 'view_leads', 'manage_inquiries', 'manage_basic_settings', 'manage_basic_seo', 'manage_privacy_basic', 'manage_privacy'],
            'editor' => ['view_dashboard', 'view_update_notice', 'manage_pages', 'manage_homepage', 'manage_media', 'manage_blog', 'manage_basic_seo'],
            'seo' => ['view_dashboard', 'view_update_notice', 'manage_pages', 'manage_homepage', 'manage_media', 'manage_basic_seo', 'manage_advanced_seo'],
            'marketing' => ['view_dashboard', 'view_update_notice', 'manage_pages', 'manage_media', 'manage_blog', 'manage_campaign_pages', 'manage_reviews_trust', 'manage_privacy_basic', 'manage_privacy_scripts'],
        ];

        $all = $pdo->query('SELECT slug FROM cms_permissions')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        foreach (['super_admin', 'reklamova_admin', 'reklamova', 'developer'] as $role) {
            $roleMap[$role] = array_values(array_map('strval', $all));
        }

        $permissionId = $pdo->prepare('SELECT id FROM cms_permissions WHERE slug = ? LIMIT 1');
        $insert = $pdo->prepare('INSERT IGNORE INTO cms_role_permissions (role, permission_id) VALUES (?, ?)');
        foreach ($roleMap as $role => $slugs) {
            foreach ($slugs as $slug) {
                $permissionId->execute([$slug]);
                $id = (int) $permissionId->fetchColumn();
                if ($id > 0) {
                    $insert->execute([$role, $id]);
                }
            }
        }
    }

    private function updateModuleMetadata(PDO $pdo): void
    {
        if (!$this->tableExists($pdo, 'cms_modules')) {
            return;
        }

        $this->ensureModuleColumns($pdo);

        $modules = [
            'pages' => ['Podstrony', 'Treści', 20, 1, 1, ['manage_pages']],
            'media' => ['Media', 'Treści', 30, 1, 1, ['manage_media']],
            'business' => ['Strona główna', 'Treści', 40, 1, 1, ['manage_homepage']],
            'knowledge' => ['Poradnik', 'Treści', 50, 1, 1, ['manage_blog']],
            'leads' => ['Zapytania', 'Kontakt', 60, 1, 1, ['view_leads', 'manage_inquiries']],
            'forms' => ['Formularze', 'Kontakt', 70, 1, 1, ['manage_forms']],
            'catalog' => ['Produkty', 'Oferta', 100, 1, 1, ['manage_products', 'manage_product_categories']],
            'landing' => ['Strony kampanii', 'Marketing', 160, 1, 1, ['manage_campaign_pages']],
            'trust' => ['Opinie i wiarygodność', 'Marketing', 170, 1, 1, ['manage_reviews_trust']],
            'privacy' => ['Prywatność i cookies', 'Ustawienia', 180, 1, 1, ['manage_privacy_basic']],
            'updates' => ['Aktualizacje CMS', 'Reklamova', 900, 0, 1, ['manage_updates']],
            'seo' => ['SEO zaawansowane', 'Reklamova', 910, 0, 1, ['manage_advanced_seo']],
        ];

        $statement = $pdo->prepare(
            'UPDATE cms_modules
             SET menu_label = ?,
                 menu_group = ?,
                 sort_order = ?,
                 visible_in_client_nav = ?,
                 visible_in_admin_nav = ?,
                 permissions_json = ?,
                 updated_at = CURRENT_TIMESTAMP
             WHERE slug = ?'
        );

        foreach ($modules as $slug => [$label, $group, $order, $clientVisible, $adminVisible, $permissions]) {
            $statement->execute([
                $label,
                $group,
                $order,
                $clientVisible,
                $adminVisible,
                json_encode($permissions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $slug,
            ]);
        }
    }

    private function ensureModuleColumns(PDO $pdo): void
    {
        $this->addColumnIfMissing($pdo, 'cms_modules', 'menu_label', 'VARCHAR(120) NULL');
        $this->addColumnIfMissing($pdo, 'cms_modules', 'menu_group', 'VARCHAR(80) NULL');
        $this->addColumnIfMissing($pdo, 'cms_modules', 'sort_order', 'INT NOT NULL DEFAULT 500');
        $this->addColumnIfMissing($pdo, 'cms_modules', 'visible_in_client_nav', 'TINYINT(1) NOT NULL DEFAULT 0');
        $this->addColumnIfMissing($pdo, 'cms_modules', 'visible_in_admin_nav', 'TINYINT(1) NOT NULL DEFAULT 1');
        $this->addColumnIfMissing($pdo, 'cms_modules', 'permissions_json', 'JSON NULL');
        $this->addColumnIfMissing($pdo, 'cms_modules', 'updated_at', 'TIMESTAMP NULL DEFAULT NULL');
    }

    private function tableExists(PDO $pdo, string $table): bool
    {
        try {
            $statement = $pdo->prepare('SHOW TABLES LIKE ?');
            $statement->execute([$table]);

            return (bool) $statement->fetchColumn();
        } catch (Throwable) {
            return false;
        }
    }

    private function columnExists(PDO $pdo, string $table, string $column): bool
    {
        try {
            $safeTable = str_replace('`', '', $table);
            $statement = $pdo->prepare('SHOW COLUMNS FROM `' . $safeTable . '` LIKE ?');
            $statement->execute([$column]);

            return (bool) $statement->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            return false;
        }
    }

    private function addColumnIfMissing(PDO $pdo, string $table, string $column, string $definition): void
    {
        if ($this->columnExists($pdo, $table, $column)) {
            return;
        }

        $safeTable = str_replace('`', '', $table);
        $safeColumn = str_replace('`', '', $column);
        $pdo->exec('ALTER TABLE `' . $safeTable . '` ADD COLUMN `' . $safeColumn . '` ' . $definition);
    }
};
