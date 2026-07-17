<?php

declare(strict_types=1);

namespace Reklamova\Cms\Updates;

use Reklamova\Cms\Backup\BackupManager;
use Reklamova\Cms\Database\ConnectionFactory;
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
        $this->ensureDirectory(dirname($lock));
        if (is_file($lock)) {
            throw new RuntimeException('Another update is already running.');
        }

        if (file_put_contents($lock, date(DATE_ATOM)) === false) {
            throw new RuntimeException('Cannot create update lock file: ' . $lock);
        }
        $backup = new BackupManager($this->container);
        $backupId = null;
        $logId = $this->startUpdateLog($package);

        try {
            $stagingPath = $this->extractToStaging($zipPath);
            $this->assertProtectedPathsAreClean($stagingPath);

            $backupId = $backup->createPreUpdateBackup($this->container['cms_version']);
            $this->enableMaintenance();
            $this->copyCoreFiles($stagingPath, $package);

            $migrator = new Migrator($this->container);
            $migrator->runCoreMigrations();
            $migrator->runActiveModuleMigrations();
            $this->clearCache();

            $health = (new HealthCheck($this->container))->run();
            if (!$health['php']['supported']) {
                throw new RuntimeException('Health check failed after update.');
            }

            $this->disableMaintenance();
            $this->finishUpdateLog($logId, 'updated', $backupId);
            return ['status' => 'updated', 'backup_id' => $backupId, 'package' => $package['package_id'] ?? null];
        } catch (\Throwable $exception) {
            if ($backupId !== null) {
                $backup->restore($backupId);
            }

            $this->disableMaintenance();
            $this->finishUpdateLog($logId, 'failed', $backupId, $exception->getMessage());
            throw $exception;
        } finally {
            @unlink($lock);
        }
    }

    public function dryRun(string $zipPath, array $package): array
    {
        $stagingPath = $this->extractToStaging($zipPath);
        $this->assertProtectedPathsAreClean($stagingPath);

        $health = (new HealthCheck($this->container))->run();
        $freeSpace = @disk_free_space($this->container['storage_path']);
        $checks = [
            'php_supported' => !empty($health['php']['supported']),
            'storage_writable' => !empty($health['writable_paths']['app/storage']),
            'backup_directory_writable' => $this->canWriteDirectory($this->container['storage_path'] . '/backups'),
            'free_space_ok' => $freeSpace === false || $freeSpace > 50 * 1024 * 1024,
            'zip_readable' => is_file($zipPath) && is_readable($zipPath),
            'protected_paths_clean' => true,
            'target_version' => (string) ($package['version'] ?? $package['to_version'] ?? ''),
            'free_space_bytes' => $freeSpace === false ? null : (int) $freeSpace,
        ];

        return [
            'status' => in_array(false, $checks, true) ? 'blocked' : 'ok',
            'checks' => $checks,
            'migrations' => $this->pendingMigrationNames($stagingPath),
        ];
    }

    private function extractToStaging(string $zipPath): string
    {
        $stagingPath = $this->container['storage_path'] . '/update-staging/' . date('YmdHis');
        $this->ensureDirectory($stagingPath);

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

    private function copyCoreFiles(string $stagingPath, array $package): void
    {
        $filesPath = $stagingPath . '/files';
        foreach ($this->corePaths($package) as $relativePath) {
            $source = $filesPath . '/' . $relativePath;
            if (is_dir($source)) {
                $this->mirrorDirectory($source, $this->targetPath($relativePath));
                continue;
            }

            if (is_file($source)) {
                $this->copyFile($source, $this->targetPath($relativePath));
            }
        }
    }

    private function targetPath(string $relativePath): string
    {
        if ($relativePath === 'public') {
            return $this->container['public_path'];
        }

        if (str_starts_with($relativePath, 'public/')) {
            return rtrim($this->container['public_path'], '/') . '/' . substr($relativePath, strlen('public/'));
        }

        return $this->container['root_path'] . '/' . $relativePath;
    }

    private function corePaths(array $package = []): array
    {
        $manifest = $package;
        if (empty($manifest['core_paths']) || !is_array($manifest['core_paths'])) {
            $manifestPath = $this->container['root_path'] . '/reklamova.json';
            $manifest = is_file($manifestPath) ? json_decode((string) file_get_contents($manifestPath), true) ?: [] : [];
        }

        $paths = $manifest['core_paths'] ?? ['app/core', 'app/migrations/core', 'app/modules', 'public/assets/core'];

        return array_values(array_filter($paths, static fn (string $path): bool => $path !== 'app/modules/custom'));
    }

    private function mirrorDirectory(string $source, string $target): void
    {
        if (!is_dir($target)) {
            $this->ensureDirectory($target);
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $destination = $target . '/' . $iterator->getSubPathName();
            if ($item->isDir()) {
                if (!is_dir($destination)) {
                    $this->ensureDirectory($destination);
                }
                continue;
            }

            if ($this->isProtectedPath($iterator->getSubPathName(), $target)) {
                continue;
            }

            copy($item->getPathname(), $destination);
        }
    }

    private function copyFile(string $source, string $target): void
    {
        $targetDir = dirname($target);
        if (!is_dir($targetDir)) {
            $this->ensureDirectory($targetDir);
        }

        copy($source, $target);
    }

    private function isProtectedPath(string $subPath, string $target): bool
    {
        $rootPath = rtrim(str_replace('\\', '/', $this->container['root_path']), '/');
        $targetPath = trim(str_replace('\\', '/', $target), '/');
        $relativeTarget = ltrim(str_replace($rootPath, '', '/' . $targetPath), '/');
        $candidate = trim($relativeTarget . '/' . str_replace('\\', '/', $subPath), '/');

        foreach (self::PROTECTED_PATHS as $protectedPath) {
            if ($candidate === $protectedPath || str_starts_with($candidate, $protectedPath . '/')) {
                return true;
            }
        }

        return false;
    }

    private function enableMaintenance(): void
    {
        $path = $this->container['storage_path'] . '/maintenance.lock';
        $this->ensureDirectory(dirname($path));
        if (file_put_contents($path, date(DATE_ATOM)) === false) {
            throw new RuntimeException('Cannot create maintenance lock file: ' . $path);
        }
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

    private function ensureDirectory(string $path): void
    {
        if (is_dir($path)) {
            return;
        }

        if (!@mkdir($path, 0775, true) && !is_dir($path)) {
            throw new RuntimeException('Cannot create directory: ' . $path);
        }
    }

    private function startUpdateLog(array $package): ?int
    {
        try {
            $pdo = (new ConnectionFactory($this->container))->make();
            $statement = $pdo->prepare('INSERT INTO cms_update_log (from_version, to_version, package_id, status, started_at) VALUES (?, ?, ?, "running", CURRENT_TIMESTAMP)');
            $statement->execute([
                (string) ($this->container['cms_version'] ?? ''),
                (string) ($package['version'] ?? $package['to_version'] ?? ''),
                (string) ($package['package_id'] ?? $package['id'] ?? ''),
            ]);
            return (int) $pdo->lastInsertId();
        } catch (\Throwable) {
            return null;
        }
    }

    private function finishUpdateLog(?int $logId, string $status, ?string $backupId = null, ?string $error = null): void
    {
        if (!$logId) {
            return;
        }

        try {
            $pdo = (new ConnectionFactory($this->container))->make();
            $statement = $pdo->prepare('UPDATE cms_update_log SET status = ?, backup_id = ?, error_message = ?, finished_at = CURRENT_TIMESTAMP WHERE id = ?');
            $statement->execute([$status, $backupId, $error, $logId]);
        } catch (\Throwable) {
            return;
        }
    }

    private function canWriteDirectory(string $path): bool
    {
        if (!is_dir($path)) {
            @mkdir($path, 0775, true);
        }

        return is_dir($path) && is_writable($path);
    }

    /**
     * @return array<int, string>
     */
    private function pendingMigrationNames(string $stagingPath): array
    {
        $names = [];
        foreach (glob($stagingPath . '/files/app/migrations/core/*.php') ?: [] as $file) {
            $names[] = basename($file, '.php');
        }
        foreach (glob($stagingPath . '/files/app/modules/*/migrations/*.php') ?: [] as $file) {
            $names[] = basename(dirname(dirname($file))) . ':' . basename($file, '.php');
        }

        return $names;
    }
}
