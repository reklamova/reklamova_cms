<?php

return new class {
    public function up(PDO $pdo): void
    {
        $this->addColumn($pdo, 'og_image', 'VARCHAR(500) NULL AFTER featured_image');
        $this->addColumn($pdo, 'schema_json', 'JSON NULL AFTER settings_json');
        $this->addColumn($pdo, 'form_config_json', 'JSON NULL AFTER schema_json');
        $this->addColumn($pdo, 'cta_config_json', 'JSON NULL AFTER form_config_json');
        $this->addColumn($pdo, 'routing_priority', 'INT NOT NULL DEFAULT 100 AFTER cta_config_json');
        $this->addColumn($pdo, 'source_html', 'MEDIUMTEXT NULL AFTER routing_priority');
    }

    public function down(PDO $pdo): void
    {
        // Core migrations are intentionally forward-only on shared hostings.
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
