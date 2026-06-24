<?php

declare(strict_types=1);

namespace Reklamova\Cms\Database;

use PDO;

final class Migrator
{
    public function __construct(private array $container)
    {
    }

    public function runCoreMigrations(): void
    {
        $pdo = (new ConnectionFactory($this->container))->make();
        $this->ensureMigrationTable($pdo);

        foreach (glob($this->container['app_path'] . '/migrations/core/*.php') ?: [] as $file) {
            $name = basename($file, '.php');
            if ($this->hasRun($pdo, $name, 'core')) {
                continue;
            }

            $migration = require $file;
            $pdo->beginTransaction();
            try {
                $migration->up($pdo);
                $this->markRun($pdo, $name, 'core');
                if ($pdo->inTransaction()) {
                    $pdo->commit();
                }
            } catch (\Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $exception;
            }
        }
    }

    private function ensureMigrationTable(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS cms_migrations (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                migration VARCHAR(190) NOT NULL,
                module VARCHAR(100) NOT NULL DEFAULT "core",
                batch INT UNSIGNED NOT NULL DEFAULT 1,
                executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_migration_module (migration, module)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    private function hasRun(PDO $pdo, string $migration, string $module): bool
    {
        $statement = $pdo->prepare('SELECT COUNT(*) FROM cms_migrations WHERE migration = ? AND module = ?');
        $statement->execute([$migration, $module]);

        return (int) $statement->fetchColumn() > 0;
    }

    private function markRun(PDO $pdo, string $migration, string $module): void
    {
        $statement = $pdo->prepare('INSERT INTO cms_migrations (migration, module, batch) VALUES (?, ?, 1)');
        $statement->execute([$migration, $module]);
    }
}
