<?php

declare(strict_types=1);

return new class {
    public function up(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS catalog_redirects (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                old_path VARCHAR(900) NOT NULL,
                new_path VARCHAR(900) NOT NULL,
                entity_type VARCHAR(30) NOT NULL DEFAULT "catalog",
                entity_id BIGINT UNSIGNED NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_catalog_redirects_old_path (old_path),
                KEY idx_catalog_redirects_new_path (new_path)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $aliases = [
            'plastiform-masy-plastyczne' => 'plastiform',
            'produkcja-przyrzadow-specjalnych' => 'produkcja-przyrzadow-pomiarowych',
            'serwis-przyrzadow' => 'naprawa-oraz-serwis-przyrzadow-pomiarowych',
            'silomierze-i-przyrzady-pwytrzymalosciowe' => 'maszyny-do-badan-wytrzymalosciowych',
            'przyrzady-pomiarowe/mikroskopy-i-lupy' => 'pomiary-optyczne',
            'przyrzady-pomiarowe/twardosciomierze' => 'twardosciomierze-rockwell-super-rockwell',
        ];
        $insert = $pdo->prepare('INSERT INTO catalog_redirects (old_path, new_path, entity_type, entity_id) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE new_path = VALUES(new_path), entity_type = VALUES(entity_type), entity_id = VALUES(entity_id)');
        foreach (['catalog_categories' => 'category', 'catalog_products' => 'product'] as $table => $type) {
            $rows = $pdo->query('SELECT id, full_path FROM ' . $table)->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $currentPath = trim((string) ($row['full_path'] ?? ''), '/');
                foreach ($aliases as $oldPrefix => $newPrefix) {
                    if ($currentPath !== $newPrefix && !str_starts_with($currentPath, $newPrefix . '/')) {
                        continue;
                    }
                    $oldPath = $oldPrefix . substr($currentPath, strlen($newPrefix));
                    $insert->execute([$oldPath, $currentPath, $type, (int) $row['id']]);
                    break;
                }
            }
        }
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS catalog_redirects');
    }
};
