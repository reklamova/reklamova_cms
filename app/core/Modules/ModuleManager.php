<?php

declare(strict_types=1);

namespace Reklamova\Cms\Modules;

use PDO;

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
                $manifest['_manifest'] = $manifestPath;
                $modules[$manifest['slug']] = $manifest;
            }
        }

        foreach (glob($this->container['app_path'] . '/modules/custom/*/module.json') ?: [] as $manifestPath) {
            $manifest = json_decode((string) file_get_contents($manifestPath), true) ?: [];
            if (isset($manifest['slug'])) {
                $manifest['source'] = 'custom';
                $manifest['_manifest'] = $manifestPath;
                $modules[$manifest['slug']] = $manifest;
            }
        }

        return $modules;
    }

    public function activeModules(?PDO $pdo = null): array
    {
        $modules = $this->discover();
        $enabled = $this->enabledSlugs($pdo);
        $active = [];

        foreach ($modules as $slug => $module) {
            if (($module['enabled_by_default'] ?? false) || in_array($slug, $enabled, true)) {
                $module['_path'] = dirname((string) ($module['_manifest'] ?? $this->manifestPath($slug, (string) ($module['source'] ?? 'official'))));
                $active[$slug] = $module;
            }
        }

        return $active;
    }

    public function adminExtensions(PDO $pdo): array
    {
        $extensions = ['nav' => [], 'routes' => []];

        foreach ($this->activeModules($pdo) as $module) {
            $path = ($module['_path'] ?? '') . '/admin.php';
            if (!is_file($path)) {
                continue;
            }

            $factory = require $path;
            if (!is_callable($factory)) {
                continue;
            }

            $extension = $factory($this->container, $pdo, $module);
            $extensions['nav'] = array_merge($extensions['nav'], $extension['nav'] ?? []);
            $extensions['routes'] = array_merge($extensions['routes'], $extension['routes'] ?? []);
        }

        return $extensions;
    }

    public function publicExtensions(PDO $pdo): array
    {
        $extensions = ['routes' => [], 'fallbacks' => []];

        foreach ($this->activeModules($pdo) as $module) {
            $path = ($module['_path'] ?? '') . '/public.php';
            if (!is_file($path)) {
                continue;
            }

            $factory = require $path;
            if (!is_callable($factory)) {
                continue;
            }

            $extension = $factory($this->container, $pdo, $module);
            $extensions['routes'] = array_merge($extensions['routes'], $extension['routes'] ?? []);
            $extensions['fallbacks'] = array_merge($extensions['fallbacks'], $extension['fallbacks'] ?? []);
        }

        return $extensions;
    }

    private function enabledSlugs(?PDO $pdo): array
    {
        $configured = $this->container['active_modules'] ?? [];
        if (!is_array($configured)) {
            $configured = [];
        }

        if (!$pdo) {
            return $configured;
        }

        try {
            $rows = $pdo->query('SELECT slug FROM cms_modules WHERE enabled = 1')->fetchAll(PDO::FETCH_COLUMN);
            return array_values(array_unique(array_merge($configured, $rows ?: [])));
        } catch (\Throwable) {
            return $configured;
        }
    }

    private function manifestPath(string $slug, string $source): string
    {
        if ($source === 'custom') {
            return $this->container['app_path'] . '/modules/custom/' . $slug . '/module.json';
        }

        return $this->container['app_path'] . '/modules/' . $slug . '/module.json';
    }
}
