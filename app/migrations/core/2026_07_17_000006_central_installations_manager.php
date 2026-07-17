<?php

return new class {
    public function up(PDO $pdo): void
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

        $insert = $pdo->prepare('INSERT INTO cms_permissions (slug, name, description) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description)');
        $insert->execute([
            'manage_installations',
            'Instalacje CMS',
            'Dostep do centralnej listy instalacji Reklamova CMS i polityk modulow per strona.',
        ]);

        $permissionId = $pdo->prepare('SELECT id FROM cms_permissions WHERE slug = ? LIMIT 1');
        $permissionId->execute(['manage_installations']);
        $id = (int) $permissionId->fetchColumn();
        if ($id <= 0) {
            return;
        }

        $assign = $pdo->prepare('INSERT IGNORE INTO cms_role_permissions (role, permission_id) VALUES (?, ?)');
        foreach (['super_admin', 'reklamova_admin', 'reklamova', 'developer'] as $role) {
            $assign->execute([$role, $id]);
        }
    }

    public function down(PDO $pdo): void
    {
    }
};
