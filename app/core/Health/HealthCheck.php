<?php

declare(strict_types=1);

namespace Reklamova\Cms\Health;

use Reklamova\Cms\Install\Installer;

final class HealthCheck
{
    public function __construct(private array $container)
    {
    }

    public function run(): array
    {
        $installer = new Installer($this->container);
        $extensions = [];

        foreach ($installer->requiredExtensions() as $extension) {
            $extensions[$extension] = extension_loaded($extension);
        }

        return [
            'cms_version' => $this->container['cms_version'],
            'php' => [
                'version' => PHP_VERSION,
                'supported' => version_compare(PHP_VERSION, '8.3.0', '>='),
            ],
            'extensions' => $extensions,
            'writable_paths' => [
                'app/storage' => is_writable($this->container['storage_path']),
                'public/uploads' => is_writable($this->container['public_path'] . '/uploads'),
            ],
            'ssl' => [
                'enabled' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
            ],
            'cron' => [
                'last_run_at' => $this->readCronTimestamp(),
            ],
        ];
    }

    private function readCronTimestamp(): ?string
    {
        $path = $this->container['storage_path'] . '/cron.last';
        if (!is_file($path)) {
            return null;
        }

        return trim((string) file_get_contents($path)) ?: null;
    }
}

