<?php

declare(strict_types=1);

namespace Reklamova\Cms\Install;

final class Installer
{
    public function __construct(private array $container)
    {
    }

    public function isInstalled(): bool
    {
        return is_file($this->container['storage_path'] . '/installed.lock')
            && is_file($this->container['config_path'] . '/database.php')
            && is_file($this->container['config_path'] . '/license.php');
    }

    public function requiredExtensions(): array
    {
        return [
            'pdo_mysql',
            'openssl',
            'curl',
            'zip',
            'mbstring',
            'json',
            'fileinfo',
            'sodium',
        ];
    }
}
