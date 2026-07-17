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
                $modules[$manifest['slug']] = $this->withDefaults($manifest);
            }
        }

        foreach (glob($this->container['app_path'] . '/modules/custom/*/module.json') ?: [] as $manifestPath) {
            $manifest = json_decode((string) file_get_contents($manifestPath), true) ?: [];
            if (isset($manifest['slug'])) {
                $manifest['source'] = 'custom';
                $manifest['_manifest'] = $manifestPath;
                $modules[$manifest['slug']] = $this->withDefaults($manifest);
            }
        }

        return $modules;
    }

    public function activeModules(?PDO $pdo = null): array
    {
        $modules = $this->discover();
        if ($pdo) {
            $this->syncDiscoveredModules($pdo, $modules);
        }
        $configured = $this->configuredModules();
        $state = $pdo ? $this->moduleState($pdo) : [];
        $active = [];

        foreach ($modules as $slug => $module) {
            $enabled = in_array($slug, $configured, true);
            if (isset($state[$slug])) {
                $enabled = $enabled || !empty($state[$slug]['enabled']);
                $module = $this->mergeDatabaseState($module, $state[$slug]);
            } else {
                $enabled = $enabled || !empty($module['enabled_by_default']) || !empty($module['enabled']);
            }

            if ($enabled) {
                $module['enabled'] = true;
                $module['_path'] = dirname((string) ($module['_manifest'] ?? $this->manifestPath($slug, (string) ($module['source'] ?? 'official'))));
                $active[$slug] = $module;
            }
        }

        return $active;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function modulesWithState(PDO $pdo): array
    {
        $modules = $this->discover();
        $this->syncDiscoveredModules($pdo, $modules);
        $rows = [];
        try {
            foreach ($pdo->query('SELECT * FROM cms_modules ORDER BY sort_order ASC, source ASC, name ASC, slug ASC')->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $slug = (string) ($row['slug'] ?? '');
                $rows[$slug] = $this->mergeDatabaseState($modules[$slug] ?? [], $row);
            }
        } catch (\Throwable) {
            return $modules;
        }

        foreach ($modules as $slug => $module) {
            $rows[$slug] ??= $module;
        }

        return $rows;
    }

    public function setEnabled(PDO $pdo, string $slug, bool $enabled): void
    {
        $modules = $this->discover();
        $module = $modules[$slug] ?? null;
        if (!$module) {
            throw new \RuntimeException('Nie znaleziono modułu: ' . $slug);
        }

        if (!$enabled && !empty($module['locked'])) {
            throw new \RuntimeException('Tego modułu nie można wyłączyć z panelu.');
        }

        $this->syncDiscoveredModules($pdo, $modules);
        $statement = $pdo->prepare('UPDATE cms_modules SET enabled = ?, enabled_at = IF(? = 1, CURRENT_TIMESTAMP, enabled_at), disabled_at = IF(? = 0, CURRENT_TIMESTAMP, disabled_at), updated_at = CURRENT_TIMESTAMP WHERE slug = ?');
        $statement->execute([$enabled ? 1 : 0, $enabled ? 1 : 0, $enabled ? 1 : 0, $slug]);
    }

    public function adminExtensions(PDO $pdo): array
    {
        $extensions = ['nav' => [], 'routes' => []];

        foreach ($this->activeModules($pdo) as $slug => $module) {
            if (isset($module['visible_in_admin_nav']) && !(bool) $module['visible_in_admin_nav']) {
                continue;
            }
            $path = ($module['_path'] ?? '') . '/admin.php';
            if (!is_file($path)) {
                continue;
            }

            $factory = require $path;
            if (!is_callable($factory)) {
                continue;
            }

            $extension = $factory($this->container, $pdo, $module);
            $extensions['nav'] = array_merge($extensions['nav'], $this->normalizeNavigation($slug, $module, $extension['nav'] ?? []));
            $extensions['routes'] = array_merge($extensions['routes'], $extension['routes'] ?? []);
        }

        uasort($extensions['nav'], static fn (array $a, array $b): int => ((int) ($a['sort_order'] ?? 500)) <=> ((int) ($b['sort_order'] ?? 500)));

        return $extensions;
    }

    public function publicExtensions(PDO $pdo): array
    {
        $extensions = [
            'routes' => [],
            'fallbacks' => [],
            'head' => [],
            'body_start' => [],
            'body_end' => [],
            'footer_links' => [],
        ];
        $customFallbacks = [];
        $officialFallbacks = [];

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
            if (($module['source'] ?? 'official') === 'custom') {
                $customFallbacks = array_merge($customFallbacks, $extension['fallbacks'] ?? []);
            } else {
                $officialFallbacks = array_merge($officialFallbacks, $extension['fallbacks'] ?? []);
            }
            $extensions['head'] = array_merge($extensions['head'], $extension['head'] ?? []);
            $extensions['body_start'] = array_merge($extensions['body_start'], $extension['body_start'] ?? []);
            $extensions['body_end'] = array_merge($extensions['body_end'], $extension['body_end'] ?? []);
            $extensions['footer_links'] = array_merge($extensions['footer_links'], $extension['footer_links'] ?? []);
        }

        $extensions['fallbacks'] = array_merge($customFallbacks, $officialFallbacks);

        return $extensions;
    }

    private function configuredModules(): array
    {
        $configured = $this->container['active_modules'] ?? [];
        if (!is_array($configured)) {
            $configured = [];
        }

        return array_values(array_unique(array_map('strval', $configured)));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function moduleState(PDO $pdo): array
    {
        $rows = [];
        try {
            foreach ($pdo->query('SELECT * FROM cms_modules')->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $slug = (string) ($row['slug'] ?? '');
                if ($slug !== '') {
                    $rows[$slug] = $row;
                }
            }
        } catch (\Throwable) {
            return [];
        }

        return $rows;
    }

    /**
     * @param array<string, array<string, mixed>> $modules
     */
    private function syncDiscoveredModules(PDO $pdo, array $modules): void
    {
        try {
            $statement = $pdo->prepare(
                'INSERT INTO cms_modules (slug, name, description, version, source, enabled, locked, system, visible_in_client_nav, visible_in_admin_nav, client_manageable, requires_json, permissions_json, menu_group, menu_label, sort_order, settings_json, installed_at, enabled_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, IF(? = 1, CURRENT_TIMESTAMP, NULL))
                 ON DUPLICATE KEY UPDATE
                    name = COALESCE(NULLIF(name, ""), VALUES(name)),
                    description = VALUES(description),
                    version = VALUES(version),
                    source = VALUES(source),
                    locked = GREATEST(locked, VALUES(locked)),
                    system = GREATEST(system, VALUES(system)),
                    visible_in_admin_nav = VALUES(visible_in_admin_nav),
                    requires_json = VALUES(requires_json),
                    permissions_json = VALUES(permissions_json),
                    menu_group = COALESCE(NULLIF(menu_group, ""), VALUES(menu_group)),
                    menu_label = COALESCE(NULLIF(menu_label, ""), VALUES(menu_label)),
                    sort_order = IF(sort_order IS NULL OR sort_order = 500, VALUES(sort_order), sort_order),
                    updated_at = CURRENT_TIMESTAMP'
            );
            foreach ($modules as $slug => $module) {
                $enabled = !empty($module['enabled_by_default']) ? 1 : 0;
                $settings = [
                    'description' => $module['description'] ?? '',
                    'requires' => $module['requires'] ?? [],
                    'system' => (bool) ($module['system'] ?? false),
                ];
                $permissions = $module['permissions'] ?? [$this->defaultPermissionForSlug($slug)];
                $statement->execute([
                    $slug,
                    (string) ($module['name'] ?? $slug),
                    (string) ($module['description'] ?? ''),
                    (string) ($module['version'] ?? '0.1.0'),
                    (string) ($module['source'] ?? 'official'),
                    $enabled,
                    !empty($module['locked']) ? 1 : 0,
                    !empty($module['system']) ? 1 : 0,
                    !empty($module['visible_in_client_nav']) ? 1 : 0,
                    array_key_exists('visible_in_admin_nav', $module) ? (int) (bool) $module['visible_in_admin_nav'] : 1,
                    !empty($module['client_manageable']) ? 1 : 0,
                    json_encode($module['requires'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    json_encode(is_array($permissions) ? array_values($permissions) : [(string) $permissions], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    (string) ($module['menu_group'] ?? $this->defaultMenuGroupForSlug($slug)),
                    (string) ($module['menu_label'] ?? $module['name'] ?? $slug),
                    (int) ($module['sort_order'] ?? 500),
                    json_encode($settings, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    $enabled,
                ]);
            }
        } catch (\Throwable) {
            return;
        }
    }

    /**
     * @param array<string, mixed> $manifest
     * @return array<string, mixed>
     */
    private function withDefaults(array $manifest): array
    {
        $slug = (string) ($manifest['slug'] ?? '');
        $system = in_array($slug, ['auth', 'pages', 'media', 'settings', 'updates', 'health', 'modules', 'themes'], true);
        $manifest['name'] ??= ucwords(str_replace(['-', '_'], ' ', $slug));
        $manifest['source'] ??= !empty($manifest['core']) || $system ? 'core' : 'official';
        $manifest['system'] ??= $system;
        $manifest['locked'] ??= $system || !empty($manifest['system']);
        $manifest['visible_in_client_nav'] ??= false;
        $manifest['visible_in_admin_nav'] ??= true;
        $manifest['client_manageable'] ??= false;
        $manifest['requires'] ??= [];
        $manifest['permissions'] ??= [$this->defaultPermissionForSlug($slug)];
        $manifest['menu_group'] ??= $this->defaultMenuGroupForSlug($slug);
        $manifest['menu_label'] ??= $manifest['name'];
        $manifest['sort_order'] ??= $this->defaultSortOrderForSlug($slug);

        return $manifest;
    }

    /**
     * @param array<string, mixed> $module
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function mergeDatabaseState(array $module, array $row): array
    {
        $merged = array_merge($module, $row);
        foreach (['settings_json' => 'settings', 'requires_json' => 'requires', 'permissions_json' => 'permissions'] as $column => $key) {
            if (!isset($row[$column]) || (string) $row[$column] === '') {
                continue;
            }
            $decoded = json_decode((string) $row[$column], true);
            if (is_array($decoded)) {
                $merged[$key] = $decoded;
            }
        }

        foreach (['enabled', 'locked', 'system', 'visible_in_client_nav', 'visible_in_admin_nav', 'client_manageable'] as $flag) {
            if (array_key_exists($flag, $merged)) {
                $merged[$flag] = (bool) $merged[$flag];
            }
        }

        return $this->withDefaults($merged);
    }

    /**
     * @param array<string, mixed> $module
     * @param array<string, mixed> $nav
     * @return array<string, array<string, mixed>>
     */
    private function normalizeNavigation(string $slug, array $module, array $nav): array
    {
        $items = [];
        $index = 0;
        foreach ($nav as $href => $item) {
            $data = is_array($item) ? $item : ['label' => (string) $item];
            $items[(string) $href] = [
                'label' => (string) ($data['label'] ?? $module['menu_label'] ?? $module['name'] ?? $slug),
                'permission' => (string) ($data['permission'] ?? ($module['permissions'][0] ?? $this->defaultPermissionForSlug($slug))),
                'module' => $slug,
                'menu_group' => (string) ($data['menu_group'] ?? $module['menu_group'] ?? $this->defaultMenuGroupForSlug($slug)),
                'sort_order' => (int) ($data['sort_order'] ?? $module['sort_order'] ?? 500) + $index,
                'visible_in_client_nav' => (bool) ($data['visible_in_client_nav'] ?? $module['visible_in_client_nav'] ?? false),
                'visible_in_admin_nav' => (bool) ($data['visible_in_admin_nav'] ?? $module['visible_in_admin_nav'] ?? true),
                'client_manageable' => (bool) ($data['client_manageable'] ?? $module['client_manageable'] ?? false),
            ];
            $index++;
        }

        return $items;
    }

    private function defaultPermissionForSlug(string $slug): string
    {
        return match ($slug) {
            'catalog' => 'manage_products',
            'knowledge' => 'manage_blog',
            'leads', 'forms' => 'manage_forms',
            'media' => 'manage_media',
            'privacy' => 'manage_privacy',
            'updates' => 'manage_updates',
            'themes' => 'manage_themes',
            'modules' => 'manage_modules',
            'health' => 'view_health',
            default => 'manage_pages',
        };
    }

    private function defaultMenuGroupForSlug(string $slug): string
    {
        return match ($slug) {
            'catalog' => 'Sprzedaż',
            'landing', 'privacy', 'trust' => 'Marketing',
            'updates', 'themes', 'modules', 'health', 'seo' => 'Reklamova / techniczne',
            default => 'Treść',
        };
    }

    private function defaultSortOrderForSlug(string $slug): int
    {
        return match ($slug) {
            'pages' => 20,
            'media' => 30,
            'business' => 40,
            'knowledge' => 50,
            'leads', 'forms' => 60,
            'catalog' => 100,
            'landing' => 160,
            'trust' => 170,
            'privacy' => 180,
            'modules' => 880,
            'themes' => 890,
            'updates' => 900,
            'health' => 920,
            default => 500,
        };
    }

    private function manifestPath(string $slug, string $source): string
    {
        if ($source === 'custom') {
            return $this->container['app_path'] . '/modules/custom/' . $slug . '/module.json';
        }

        return $this->container['app_path'] . '/modules/' . $slug . '/module.json';
    }
}
