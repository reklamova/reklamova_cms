<?php

declare(strict_types=1);

namespace Reklamova\Cms\Updates;

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

        return $this->postJson($endpoint, [
            'site_id' => $license['site_id'] ?? '',
            'domain' => $_SERVER['HTTP_HOST'] ?? '',
            'cms_version' => $this->container['cms_version'],
            'php_version' => PHP_VERSION,
            'active_modules' => $modules,
            'health' => $health,
        ], $license['site_key'] ?? '');
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
        curl_close($ch);

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
}

