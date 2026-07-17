<?php

return new class {
    public function up(PDO $pdo): void
    {
        $this->addColumn($pdo, 'excerpt', 'TEXT NULL AFTER content');
        $this->addColumn($pdo, 'template', 'VARCHAR(80) NOT NULL DEFAULT "default" AFTER status');
        $this->addColumn($pdo, 'meta_title', 'VARCHAR(190) NULL AFTER template');
        $this->addColumn($pdo, 'meta_description', 'TEXT NULL AFTER meta_title');
        $this->addColumn($pdo, 'canonical_url', 'VARCHAR(255) NULL AFTER meta_description');
        $this->addColumn($pdo, 'robots', 'VARCHAR(80) NOT NULL DEFAULT "index,follow" AFTER canonical_url');
        $this->addColumn($pdo, 'featured_image', 'VARCHAR(255) NULL AFTER robots');
        $this->addColumn($pdo, 'parent_id', 'BIGINT UNSIGNED NULL AFTER featured_image');
        $this->addColumn($pdo, 'sort_order', 'INT NOT NULL DEFAULT 100 AFTER parent_id');
        $this->addColumn($pdo, 'show_in_menu', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER sort_order');
        $this->addColumn($pdo, 'menu_label', 'VARCHAR(190) NULL AFTER show_in_menu');
        $this->addColumn($pdo, 'published_at', 'DATETIME NULL AFTER menu_label');
        $this->addColumn($pdo, 'blocks_json', 'JSON NULL AFTER published_at');
        $this->addColumn($pdo, 'settings_json', 'JSON NULL AFTER blocks_json');

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS cms_page_revisions (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                page_id BIGINT UNSIGNED NOT NULL,
                version_number INT UNSIGNED NOT NULL DEFAULT 1,
                title VARCHAR(190) NOT NULL,
                slug VARCHAR(190) NOT NULL,
                content MEDIUMTEXT NULL,
                blocks_json JSON NULL,
                meta_json JSON NULL,
                created_by BIGINT UNSIGNED NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_page_revisions_page (page_id),
                INDEX idx_page_revisions_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec('UPDATE cms_pages SET published_at = COALESCE(published_at, created_at) WHERE status = "published"');
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS cms_page_revisions');
    }

    private function addColumn(PDO $pdo, string $column, string $definition): void
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = "cms_pages" AND COLUMN_NAME = ?'
        );
        $statement->execute([$column]);
        if ((int) $statement->fetchColumn() > 0) {
            return;
        }

        $pdo->exec('ALTER TABLE cms_pages ADD COLUMN ' . $column . ' ' . $definition);
    }
};
