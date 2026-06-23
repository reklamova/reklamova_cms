<?php

declare(strict_types=1);

namespace Reklamova\Cms\Backup;

use RuntimeException;
use ZipArchive;

final class BackupManager
{
    public function __construct(private array $container)
    {
    }

    public function createPreUpdateBackup(string $cmsVersion): string
    {
        $backupId = 'bkp_' . date('Ymd_His');
        $backupPath = $this->container['storage_path'] . '/backups/' . $backupId;
        mkdir($backupPath, 0775, true);

        $this->zipCoreFiles($backupPath . '/core-files.zip');
        $this->writeDatabasePlaceholder($backupPath . '/database.sql.gz');

        file_put_contents($backupPath . '/manifest.json', json_encode([
            'backup_id' => $backupId,
            'cms_version' => $cmsVersion,
            'created_at' => date(DATE_ATOM),
            'type' => 'pre-update',
            'database' => 'database.sql.gz',
            'files' => 'core-files.zip',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $backupId;
    }

    public function restore(string $backupId): void
    {
        $backupPath = $this->container['storage_path'] . '/backups/' . basename($backupId);
        $zipPath = $backupPath . '/core-files.zip';

        if (!is_file($zipPath)) {
            throw new RuntimeException('Backup archive is missing: ' . $backupId);
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('Cannot open backup archive.');
        }

        $zip->extractTo($this->container['root_path']);
        $zip->close();
    }

    private function zipCoreFiles(string $targetZip): void
    {
        $zip = new ZipArchive();
        if ($zip->open($targetZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Cannot create backup archive.');
        }

        foreach (['app/core', 'app/migrations/core', 'public/assets/core'] as $relativePath) {
            $absolutePath = $this->container['root_path'] . '/' . $relativePath;
            if (is_dir($absolutePath)) {
                $this->addDirectory($zip, $absolutePath, $relativePath);
            }
        }

        $zip->close();
    }

    private function addDirectory(ZipArchive $zip, string $absolutePath, string $relativePath): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($absolutePath, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $localName = $relativePath . '/' . $iterator->getSubPathName();
            if ($item->isDir()) {
                $zip->addEmptyDir($localName);
                continue;
            }

            $zip->addFile($item->getPathname(), $localName);
        }
    }

    private function writeDatabasePlaceholder(string $path): void
    {
        $message = "-- Database dump hook placeholder.\n"
            . "-- Shared hosting implementations should use mysqldump when available,\n"
            . "-- or a PDO-based dumper when shell access is not available.\n";

        file_put_contents('compress.zlib://' . $path, $message);
    }
}

