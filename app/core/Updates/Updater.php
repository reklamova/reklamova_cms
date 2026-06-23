<?php

declare(strict_types=1);

namespace Reklamova\Cms\Updates;

use Reklamova\Cms\Backup\BackupManager;
use Reklamova\Cms\Database\Migrator;
use Reklamova\Cms\Health\HealthCheck;
use RuntimeException;
use ZipArchive;

final class Updater
{
    private const PROTECTED_PATHS = [
        'app/config',
        'app/themes',
        'app/modules/custom',
        'public/uploads',
        'app/storage/backups',
        'app/storage/logs',
    ];

    public function __construct(private array $container)
    {
    }

    public function apply(string $zipPath, array $package): array
    {
        $lock = $this->container['storage_path'] . '/update.lock';
        if (is_file($lock)) {
            throw new RuntimeException('Another update is already running.');
        }

        file_put_contents($lock, date(DATE_ATOM));
        $backup = new BackupManager($this->container);
        $backupId = null;

        try {
            $stagingPath = $this->extractToStaging($zipPath);
            $this->assertProtectedPathsAreClean($stagingPath);

            $backupId = $backup->createPreUpdateBackup($this->container['cms_version']);
            $this->enableMaintenance();
            $this->copyCoreFiles($stagingPath);

            (new Migrator($this->container))->runCoreMigrations();
            $this->clearCache();

            $health = (new HealthCheck($this->container))->run();
            if (!$health['php']['supported']) {
                throw new RuntimeException('Health check failed after update.');
            }

            $this->disableMaintenance();
            return ['status' => 'updated', 'backup_id' => $backupId, 'package' => $package['package_id'] ?? null];
        } catch (\Throwable $exception) {
            if ($backupId !== null) {
                $backup->restore($backupId);
            }

            $this->disableMaintenance();
            throw $exception;
        } finally {
            @unlink($lock);
        }
    }

    private function extractToStaging(string $zipPath): string
    {
        $stagingPath = $this->container['storage_path'] . '/update-staging/' . date('YmdHis');
        mkdir($stagingPath, 0775, true);

        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('Cannot open update package.');
        }

        $zip->extractTo($stagingPath);
        $zip->close();

        return $stagingPath;
    }

    private function assertProtectedPathsAreClean(string $stagingPath): void
    {
        foreach (self::PROTECTED_PATHS as $path) {
            if (is_dir($stagingPath . '/files/' . $path) || is_file($stagingPath . '/files/' . $path)) {
                throw new RuntimeException('Package tries to modify protected path: ' . $path);
            }
        }
    }

    private function copyCoreFiles(string $stagingPath): void
    {
        $filesPath = $stagingPath . '/files';
        foreach (['app/core', 'app/migrations/core', 'public/assets/core'] as $relativePath) {
            $source = $filesPath . '/' . $relativePath;
            if (is_dir($source)) {
                $this->mirrorDirectory($source, $this->container['root_path'] . '/' . $relativePath);
            }
        }
    }

    private function mirrorDirectory(string $source, string $target): void
    {
        if (!is_dir($target)) {
            mkdir($target, 0775, true);
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $destination = $target . '/' . $iterator->getSubPathName();
            if ($item->isDir()) {
                if (!is_dir($destination)) {
                    mkdir($destination, 0775, true);
                }
                continue;
            }

            copy($item->getPathname(), $destination);
        }
    }

    private function enableMaintenance(): void
    {
        file_put_contents($this->container['storage_path'] . '/maintenance.lock', date(DATE_ATOM));
    }

    private function disableMaintenance(): void
    {
        @unlink($this->container['storage_path'] . '/maintenance.lock');
    }

    private function clearCache(): void
    {
        foreach (glob($this->container['storage_path'] . '/cache/*') ?: [] as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }
}

