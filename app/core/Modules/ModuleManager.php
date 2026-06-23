<?php

declare(strict_types=1);

namespace Reklamova\Cms\Modules;

final class ModuleManager
{
    public function __construct(private array $container)
    {
    }

    public function discover(): array
    {
        $modules = [];
        foreach (glob($this->container['app_path'] . '/modules/*/module.json') ?: [] as $manifestPath) {
            $manifest = json_decode((string) file_get_contents($manifestPath), true) ?: [];
            if (isset($manifest['slug'])) {
                $modules[$manifest['slug']] = $manifest;
            }
        }

        foreach (glob($this->container['app_path'] . '/modules/custom/*/module.json') ?: [] as $manifestPath) {
            $manifest = json_decode((string) file_get_contents($manifestPath), true) ?: [];
            if (isset($manifest['slug'])) {
                $manifest['source'] = 'custom';
                $modules[$manifest['slug']] = $manifest;
            }
        }

        return $modules;
    }
}

