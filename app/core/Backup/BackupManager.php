<?php

declare(strict_types=1);

namespace Reklamova\Cms\Backup;

use PDO;
use Reklamova\Cms\Database\ConnectionFactory;
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
        $this->ensureDirectory($backupPath);

        $this->zipCoreFiles($backupPath . '/core-files.zip');
        $database = $this->dumpDatabase($backupPath . '/database.sql.gz');

        file_put_contents($backupPath . '/manifest.json', json_encode([
            'backup_id' => $backupId,
            'cms_version' => $cmsVersion,
            'created_at' => date(DATE_ATOM),
            'type' => 'pre-update',
            'database' => array_merge(['file' => 'database.sql.gz'], $database),
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

        $databasePath = $backupPath . '/database.sql.gz';
        if (is_file($databasePath)) {
            $this->restoreDatabase($databasePath);
        }
    }

    private function zipCoreFiles(string $targetZip): void
    {
        $zip = new ZipArchive();
        if ($zip->open($targetZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Cannot create backup archive.');
        }

        foreach ($this->corePaths() as $relativePath) {
            $absolutePath = $this->sourcePath($relativePath);
            if (is_dir($absolutePath)) {
                $this->addDirectory($zip, $absolutePath, $relativePath);
                continue;
            }

            if (is_file($absolutePath)) {
                $zip->addFile($absolutePath, $relativePath);
            }
        }

        $zip->close();
    }

    private function corePaths(): array
    {
        return [
            'reklamova.json',
            'app/bootstrap.php',
            'app/core',
            'app/migrations/core',
            'app/modules',
            'public/.htaccess',
            'public/index.php',
            'public/admin',
            'public/assets/core',
        ];
    }

    private function sourcePath(string $relativePath): string
    {
        if ($relativePath === 'public') {
            return $this->container['public_path'];
        }

        if (str_starts_with($relativePath, 'public/')) {
            return rtrim($this->container['public_path'], '/') . '/' . substr($relativePath, strlen('public/'));
        }

        return $this->container['root_path'] . '/' . $relativePath;
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

    private function dumpDatabase(string $path): array
    {
        $config = $this->databaseConfig();
        $method = 'pdo';
        if ($this->canUseShell()) {
            $binary = $this->findBinary('mysqldump');
            if ($binary !== null && $this->dumpWithMysqlDump($binary, $config, $path)) {
                $method = 'mysqldump';
            } else {
                $this->dumpWithPdo($path);
            }
        } else {
            $this->dumpWithPdo($path);
        }

        $size = is_file($path) ? (int) filesize($path) : 0;
        if ($size <= 0 || !$this->isReadableGzip($path)) {
            throw new RuntimeException('Database backup integrity check failed.');
        }

        return [
            'method' => $method,
            'size' => $size,
            'sha256' => hash_file('sha256', $path),
            'integrity' => 'ok',
        ];
    }

    private function dumpWithMysqlDump(string $binary, array $config, string $path): bool
    {
        $defaultsFile = dirname($path) . '/mysql-client.cnf';
        $defaults = "[client]\n"
            . 'user=' . (string) $config['username'] . "\n"
            . 'password=' . (string) $config['password'] . "\n"
            . 'host=' . (string) $config['host'] . "\n"
            . 'port=' . (string) ($config['port'] ?? 3306) . "\n";
        file_put_contents($defaultsFile, $defaults);
        @chmod($defaultsFile, 0600);

        $command = escapeshellarg($binary)
            . ' --defaults-extra-file=' . escapeshellarg($defaultsFile)
            . ' --single-transaction --quick --skip-lock-tables --default-character-set=' . escapeshellarg((string) ($config['charset'] ?? 'utf8mb4'))
            . ' ' . escapeshellarg((string) $config['database']);

        $input = @popen($command, 'rb');
        if (!$input) {
            @unlink($defaultsFile);
            return false;
        }

        $output = gzopen($path, 'wb9');
        if (!$output) {
            pclose($input);
            @unlink($defaultsFile);
            return false;
        }

        while (!feof($input)) {
            gzwrite($output, fread($input, 8192) ?: '');
        }

        gzclose($output);
        $exitCode = pclose($input);
        @unlink($defaultsFile);

        return $exitCode === 0 && is_file($path) && filesize($path) > 0;
    }

    private function dumpWithPdo(string $path): void
    {
        $pdo = (new ConnectionFactory($this->container))->make();
        $out = gzopen($path, 'wb9');
        if (!$out) {
            throw new RuntimeException('Cannot create database backup file.');
        }

        gzwrite($out, "-- Reklamova CMS PDO database backup\nSET FOREIGN_KEY_CHECKS=0;\n\n");
        $tables = $pdo->query('SHOW FULL TABLES WHERE Table_type = "BASE TABLE"')->fetchAll(PDO::FETCH_NUM);
        foreach ($tables as $tableRow) {
            $table = (string) $tableRow[0];
            $create = $pdo->query('SHOW CREATE TABLE `' . str_replace('`', '``', $table) . '`')->fetch(PDO::FETCH_ASSOC);
            gzwrite($out, "DROP TABLE IF EXISTS `" . str_replace('`', '``', $table) . "`;\n" . (string) ($create['Create Table'] ?? array_values($create)[1] ?? '') . ";\n\n");

            $rows = $pdo->query('SELECT * FROM `' . str_replace('`', '``', $table) . '`', PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $columns = array_map(static fn (string $column): string => '`' . str_replace('`', '``', $column) . '`', array_keys($row));
                $values = array_map(static fn ($value): string => $value === null ? 'NULL' : $pdo->quote((string) $value), array_values($row));
                gzwrite($out, 'INSERT INTO `' . str_replace('`', '``', $table) . '` (' . implode(',', $columns) . ') VALUES (' . implode(',', $values) . ");\n");
            }
            gzwrite($out, "\n");
        }
        gzwrite($out, "SET FOREIGN_KEY_CHECKS=1;\n");
        gzclose($out);
    }

    private function restoreDatabase(string $path): void
    {
        if ($this->canUseShell()) {
            $binary = $this->findBinary('mysql');
            $config = $this->databaseConfig();
            if ($binary !== null && $this->restoreWithMysqlClient($binary, $config, $path)) {
                return;
            }
        }

        $pdo = (new ConnectionFactory($this->container))->make();
        $sql = gzdecode((string) file_get_contents($path));
        if ($sql === false) {
            throw new RuntimeException('Cannot read database backup.');
        }

        $statement = '';
        foreach (explode("\n", $sql) as $line) {
            if (str_starts_with(trim($line), '--') || trim($line) === '') {
                continue;
            }
            $statement .= $line . "\n";
            if (str_ends_with(rtrim($line), ';')) {
                $pdo->exec($statement);
                $statement = '';
            }
        }
    }

    private function restoreWithMysqlClient(string $binary, array $config, string $path): bool
    {
        $defaultsFile = dirname($path) . '/mysql-restore.cnf';
        file_put_contents($defaultsFile, "[client]\nuser={$config['username']}\npassword={$config['password']}\nhost={$config['host']}\nport=" . ($config['port'] ?? 3306) . "\n");
        @chmod($defaultsFile, 0600);
        $sql = gzdecode((string) file_get_contents($path));
        if ($sql === false) {
            @unlink($defaultsFile);
            return false;
        }

        $tempSql = dirname($path) . '/restore.sql';
        file_put_contents($tempSql, $sql);
        $command = escapeshellarg($binary) . ' --defaults-extra-file=' . escapeshellarg($defaultsFile) . ' ' . escapeshellarg((string) $config['database']) . ' < ' . escapeshellarg($tempSql);
        exec($command, $output, $exitCode);
        @unlink($defaultsFile);
        @unlink($tempSql);

        return $exitCode === 0;
    }

    private function databaseConfig(): array
    {
        $path = $this->container['config_path'] . '/database.php';
        if (!is_file($path)) {
            throw new RuntimeException('Database config is missing.');
        }

        $config = require $path;
        if (!is_array($config)) {
            throw new RuntimeException('Database config is invalid.');
        }

        return $config;
    }

    private function findBinary(string $name): ?string
    {
        $result = trim((string) @shell_exec('command -v ' . escapeshellarg($name) . ' 2>/dev/null'));
        return $result !== '' ? $result : null;
    }

    private function canUseShell(): bool
    {
        return function_exists('shell_exec') && function_exists('popen') && function_exists('exec');
    }

    private function isReadableGzip(string $path): bool
    {
        $handle = @gzopen($path, 'rb');
        if (!$handle) {
            return false;
        }

        $chunk = gzread($handle, 64);
        gzclose($handle);

        return is_string($chunk);
    }

    private function ensureDirectory(string $path): void
    {
        if (is_dir($path)) {
            return;
        }

        if (!@mkdir($path, 0775, true) && !is_dir($path)) {
            throw new RuntimeException('Cannot create backup directory: ' . $path);
        }
    }
}
