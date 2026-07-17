<?php

declare(strict_types=1);

namespace Reklamova\Cms\Updates;

use Reklamova\Cms\Database\ConnectionFactory;
use Reklamova\Cms\Support\Config;

final class UpdateClient
{
    public function __construct(private array $container)
    {
    }

    public function localStatus(): array
    {
        return [
            'cms_version' => $this->container['cms_version'],
            'update_channel' => $this->manifest()['update_channel'] ?? 'stable',
            'install_mode' => $this->manifest()['install_mode'] ?? 'zip',
            'server' => $this->license()['license_server'] ?? 'https://updates.reklamova.pl',
        ];
    }

    public function check(array $health, array $modules): array
    {
        $license = $this->license();
        $endpoint = rtrim($license['license_server'] ?? 'https://updates.reklamova.pl', '/') . '/api/v1/check-update';

        $result = $this->postJson($endpoint, [
            'site_id' => $license['site_id'] ?? '',
            'domain' => $_SERVER['HTTP_HOST'] ?? '',
            'cms_version' => $this->container['cms_version'],
            'php_version' => PHP_VERSION,
            'database_version' => $this->databaseVersion(),
            'active_modules' => $modules,
            'theme' => $this->activeTheme(),
            'core_checksum' => $this->coreChecksum(),
            'health' => $health,
        ], $license['site_key'] ?? '');

        $this->writeCachedStatus($result);

        return $result;
    }

    public function cachedStatus(): ?array
    {
        $path = $this->cachePath();
        if (!is_file($path)) {
            return null;
        }

        return json_decode((string) file_get_contents($path), true) ?: null;
    }

    public function downloadPackage(array $package): string
    {
        $url = (string) ($package['url'] ?? '');
        if ($url === '') {
            throw new \RuntimeException('Update package URL is missing.');
        }

        if (!extension_loaded('curl')) {
            throw new \RuntimeException('curl extension is required to download updates.');
        }

        $downloadDir = $this->writableDirectory([
            $this->container['storage_path'] . '/update-downloads',
            $this->container['storage_path'] . '/cache/update-downloads',
            sys_get_temp_dir() . '/reklamova-update-downloads',
        ]);

        $prefix = preg_replace('/[^a-zA-Z0-9_-]/', '_', (string) ($package['id'] ?? 'package')) ?: 'package';
        $target = tempnam($downloadDir, $prefix . '-');
        if ($target === false) {
            throw new \RuntimeException('Cannot create update download file in writable directory: ' . $downloadDir);
        }

        $zipTarget = $target . '.zip';
        if (!@rename($target, $zipTarget)) {
            $zipTarget = $target;
        }

        $handle = fopen($zipTarget, 'wb');
        if (!$handle) {
            @unlink($zipTarget);
            throw new \RuntimeException('Cannot create update download file in: ' . $downloadDir);
        }

        $license = $this->license();
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE => $handle,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . ($license['site_key'] ?? ''),
            ],
            CURLOPT_TIMEOUT => 120,
        ]);

        $ok = curl_exec($ch);
        $error = curl_error($ch);
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        fclose($handle);
        unset($ch);

        if ($ok === false || $status < 200 || $status >= 300) {
            @unlink($zipTarget);
            throw new \RuntimeException('Update package download failed: ' . ($error ?: 'HTTP ' . $status));
        }

        return $zipTarget;
    }

    public function report(string $event, array $payload = []): array
    {
        $license = $this->license();
        $endpoint = rtrim($license['license_server'] ?? 'https://updates.reklamova.pl', '/') . '/api/v1/report-' . $event;

        return $this->postJson($endpoint, array_merge([
            'site_id' => $license['site_id'] ?? '',
            'domain' => $_SERVER['HTTP_HOST'] ?? '',
            'cms_version' => $this->container['cms_version'],
            'reported_at' => date(DATE_ATOM),
        ], $payload), $license['site_key'] ?? '');
    }

    private function postJson(string $url, array $payload, string $siteKey): array
    {
        if (!extension_loaded('curl')) {
            return ['error' => 'curl extension is missing'];
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $siteKey,
            ],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR),
            CURLOPT_TIMEOUT => 20,
        ]);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        unset($ch);

        if ($response === false) {
            return ['error' => $error ?: 'update check failed'];
        }

        return [
            'status' => $status,
            'body' => json_decode((string) $response, true) ?: [],
        ];
    }

    private function license(): array
    {
        $path = $this->container['config_path'] . '/license.php';
        return is_file($path) ? require $path : [];
    }

    private function manifest(): array
    {
        $path = $this->container['root_path'] . '/reklamova.json';
        return is_file($path) ? json_decode((string) file_get_contents($path), true) ?: [] : [];
    }

    private function databaseVersion(): string
    {
        try {
            $pdo = (new ConnectionFactory($this->container))->make();
            return (string) $pdo->query('SELECT VERSION()')->fetchColumn();
        } catch (\Throwable) {
            return 'unknown';
        }
    }

    private function activeTheme(): string
    {
        try {
            return (string) (new Config($this->container))->get('app', 'active_theme', 'client-default');
        } catch (\Throwable) {
            return 'client-default';
        }
    }

    private function coreChecksum(): string
    {
        $manifest = $this->manifest();
        $paths = $manifest['core_paths'] ?? ['reklamova.json', 'app/bootstrap.php', 'app/core', 'app/migrations/core', 'app/modules', 'public/index.php', 'public/admin', 'public/assets/core'];
        $hashes = [];
        foreach ($paths as $path) {
            $relative = trim((string) $path, '/');
            if ($relative === '' || $this->isProtectedRelativePath($relative)) {
                continue;
            }

            $absolute = $this->absolutePath($relative);
            if (is_file($absolute)) {
                $hashes[] = $relative . ':' . hash_file('sha256', $absolute);
                continue;
            }

            if (!is_dir($absolute)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($absolute, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $item) {
                if (!$item->isFile()) {
                    continue;
                }
                $fileRelative = trim($relative . '/' . str_replace('\\', '/', $iterator->getSubPathName()), '/');
                if ($this->isProtectedRelativePath($fileRelative)) {
                    continue;
                }
                $hashes[] = $fileRelative . ':' . hash_file('sha256', $item->getPathname());
            }
        }

        sort($hashes);

        return hash('sha256', implode("\n", $hashes));
    }

    private function absolutePath(string $relative): string
    {
        if ($relative === 'public') {
            return $this->container['public_path'];
        }

        if (str_starts_with($relative, 'public/')) {
            return rtrim($this->container['public_path'], '/') . '/' . substr($relative, strlen('public/'));
        }

        return $this->container['root_path'] . '/' . $relative;
    }

    private function isProtectedRelativePath(string $relative): bool
    {
        $relative = trim(str_replace('\\', '/', $relative), '/');
        foreach ($this->manifest()['protected_paths'] ?? [] as $protected) {
            $protected = trim((string) $protected, '/');
            if ($relative === $protected || str_starts_with($relative, $protected . '/')) {
                return true;
            }
        }

        return false;
    }

    private function writeCachedStatus(array $result): void
    {
        $dir = dirname($this->cachePath());
        if (!$this->ensureDirectory($dir)) {
            return;
        }

        file_put_contents($this->cachePath(), json_encode([
            'checked_at' => date(DATE_ATOM),
            'result' => $result,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function cachePath(): string
    {
        return $this->container['storage_path'] . '/cache/update-status.json';
    }

    private function writableDirectory(array $candidates): string
    {
        foreach ($candidates as $candidate) {
            if ($this->ensureDirectory($candidate) && is_writable($candidate)) {
                return $candidate;
            }
        }

        throw new \RuntimeException('No writable directory available for update downloads. Checked: ' . implode(', ', $candidates));
    }

    private function ensureDirectory(string $path): bool
    {
        if (is_dir($path)) {
            return true;
        }

        return @mkdir($path, 0775, true) || is_dir($path);
    }
}
