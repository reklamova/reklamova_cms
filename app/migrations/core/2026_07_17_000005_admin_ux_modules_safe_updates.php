<?php

return new class {
    public function up(PDO $pdo): void
    {
        $this->expandModuleMetadata($pdo);
        $this->seedPermissions($pdo);
        $this->seedDefaultModuleVisibility($pdo);
    }

    public function down(PDO $pdo): void
    {
    }

    private function expandModuleMetadata(PDO $pdo): void
    {
        $this->addColumn($pdo, 'cms_modules', 'description', 'TEXT NULL AFTER name');
        $this->addColumn($pdo, 'cms_modules', 'system', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER source');
        $this->addColumn($pdo, 'cms_modules', 'requires_json', 'JSON NULL AFTER client_manageable');
        $this->addColumn($pdo, 'cms_modules', 'permissions_json', 'JSON NULL AFTER requires_json');
        $this->addColumn($pdo, 'cms_modules', 'menu_group', 'VARCHAR(80) NULL AFTER permissions_json');
        $this->addColumn($pdo, 'cms_modules', 'menu_label', 'VARCHAR(120) NULL AFTER menu_group');
        $this->addColumn($pdo, 'cms_modules', 'sort_order', 'INT NOT NULL DEFAULT 500 AFTER menu_label');
    }

    private function seedPermissions(PDO $pdo): void
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

        $permissions = [
            'view_update_notice' => 'Komunikat o aktualizacji',
            'manage_backups' => 'Backupy',
            'view_logs' => 'Logi',
            'manage_permissions' => 'Uprawnienia',
        ];

        $insertPermission = $pdo->prepare('INSERT INTO cms_permissions (slug, name) VALUES (?, ?) ON DUPLICATE KEY UPDATE name = VALUES(name)');
        foreach ($permissions as $slug => $name) {
            $insertPermission->execute([$slug, $name]);
        }

        $roleMap = [
            'client_admin' => ['view_update_notice'],
            'admin' => ['view_update_notice'],
            'editor' => ['view_update_notice'],
            'seo' => ['view_update_notice'],
            'marketing' => ['view_update_notice'],
            'super_admin' => array_keys($permissions),
            'reklamova_admin' => array_keys($permissions),
            'reklamova' => array_keys($permissions),
            'developer' => array_keys($permissions),
        ];

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

    private function seedDefaultModuleVisibility(PDO $pdo): void
    {
        $defaults = [
            'pages' => ['Strony', 'Treść', 'manage_pages', 20, 1, 1, 1, 1],
            'media' => ['Media', 'Treść', 'manage_media', 30, 1, 1, 1, 1],
            'business' => ['Strona główna', 'Treść', 'manage_pages', 40, 0, 1, 1, 0],
            'knowledge' => ['Aktualności / Poradniki', 'Treść', 'manage_blog', 50, 0, 1, 1, 0],
            'leads' => ['Formularze', 'Treść', 'manage_forms', 60, 0, 1, 1, 0],
            'catalog' => ['Produkty', 'Sprzedaż', 'manage_products', 100, 0, 1, 1, 0],
            'landing' => ['Landing pages', 'Marketing', 'manage_pages', 160, 0, 1, 1, 0],
            'trust' => ['Opinie i referencje', 'Marketing', 'manage_pages', 170, 0, 1, 1, 0],
            'privacy' => ['Prywatność i cookies', 'Marketing', 'manage_privacy', 180, 1, 1, 1, 1],
            'updates' => ['Aktualizacje CMS', 'Reklamova / techniczne', 'manage_updates', 900, 1, 0, 1, 1],
            'seo' => ['SEO', 'Reklamova / techniczne', 'manage_advanced_seo', 910, 0, 0, 1, 0],
            'forms' => ['Formularze', 'Treść', 'manage_forms', 65, 0, 1, 1, 0],
        ];

        $statement = $pdo->prepare(
            'UPDATE cms_modules
             SET menu_label = COALESCE(NULLIF(menu_label, ""), ?),
                 menu_group = COALESCE(NULLIF(menu_group, ""), ?),
                 permissions_json = COALESCE(permissions_json, ?),
                 sort_order = IF(sort_order = 500 OR sort_order IS NULL, ?, sort_order),
                 system = GREATEST(system, ?),
                 visible_in_client_nav = ?,
                 visible_in_admin_nav = ?,
                 client_manageable = ?
             WHERE slug = ?'
        );

        foreach ($defaults as $slug => [$label, $group, $permission, $order, $system, $clientVisible, $adminVisible, $clientManageable]) {
            $statement->execute([
                $label,
                $group,
                json_encode([$permission], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                $order,
                $system,
                $clientVisible,
                $adminVisible,
                $clientManageable,
                $slug,
            ]);
        }
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
