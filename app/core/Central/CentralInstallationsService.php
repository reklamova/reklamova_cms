<?php

declare(strict_types=1);

namespace Reklamova\Cms\Central;

final class CentralInstallationsService
{
    public function __construct(private array $container)
    {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function installations(): array
    {
        $config = $this->updateServerConfig();
        $licenses = $this->licenses($config);
        $checks = $this->latestReports($config, 'checks');
        $updates = $this->latestUpdateReports($config);
        $health = $this->latestReports($config, 'health');
        $packages = $this->packageIndex($config);
        $policies = $this->modulePolicies($config);
        $rows = [];

        foreach ($licenses as $license) {
            $siteId = (string) ($license['site_id'] ?? '');
            $domain = (string) ($license['domain'] ?? '');
            $key = $this->installationKey($siteId, $domain);
            $check = $checks[$key] ?? $checks[$siteId] ?? $checks[$domain] ?? [];
            $latest = $this->latestVersionForChannel($packages, (string) ($license['channel'] ?? 'stable'));
            $current = (string) ($check['cms_version'] ?? '');

            $rows[] = [
                'site_id' => $siteId,
                'domain' => $domain,
                'channel' => (string) ($license['channel'] ?? 'stable'),
                'license_status' => (string) ($license['status'] ?? 'active'),
                'current_version' => $current !== '' ? $current : 'brak danych',
                'latest_version' => $latest !== '' ? $latest : 'brak paczki',
                'update_available' => $current !== '' && $latest !== '' && version_compare($latest, $current, '>'),
                'php_version' => (string) ($check['php_version'] ?? ''),
                'database_version' => (string) ($check['database_version'] ?? ''),
                'theme' => (string) ($check['theme'] ?? ''),
                'active_modules' => is_array($check['active_modules'] ?? null) ? $check['active_modules'] : [],
                'checked_at' => (string) ($check['checked_at'] ?? ''),
                'health' => $health[$key] ?? $health[$siteId] ?? $health[$domain] ?? ($check['health'] ?? []),
                'last_update' => $updates[$key] ?? $updates[$siteId] ?? $updates[$domain] ?? null,
                'module_policy' => $policies[$key] ?? $policies[$siteId] ?? $policies[$domain] ?? null,
            ];
        }

        foreach ($checks as $key => $check) {
            $siteId = (string) ($check['site_id'] ?? '');
            $domain = (string) ($check['domain'] ?? '');
            if ($this->hasInstallation($rows, $siteId, $domain)) {
                continue;
            }
            $latest = $this->latestVersionForChannel($packages, 'stable');
            $current = (string) ($check['cms_version'] ?? '');
            $rows[] = [
                'site_id' => $siteId !== '' ? $siteId : $key,
                'domain' => $domain,
                'channel' => 'stable',
                'license_status' => 'brak w licencjach',
                'current_version' => $current !== '' ? $current : 'brak danych',
                'latest_version' => $latest !== '' ? $latest : 'brak paczki',
                'update_available' => $current !== '' && $latest !== '' && version_compare($latest, $current, '>'),
                'php_version' => (string) ($check['php_version'] ?? ''),
                'database_version' => (string) ($check['database_version'] ?? ''),
                'theme' => (string) ($check['theme'] ?? ''),
                'active_modules' => is_array($check['active_modules'] ?? null) ? $check['active_modules'] : [],
                'checked_at' => (string) ($check['checked_at'] ?? ''),
                'health' => $check['health'] ?? [],
                'last_update' => $updates[$key] ?? null,
                'module_policy' => $policies[$key] ?? null,
            ];
        }

        usort($rows, static fn (array $a, array $b): int => strcmp((string) ($a['domain'] ?: $a['site_id']), (string) ($b['domain'] ?: $b['site_id'])));

        return $rows;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function installation(string $siteId): ?array
    {
        foreach ($this->installations() as $installation) {
            if ((string) ($installation['site_id'] ?? '') === $siteId || (string) ($installation['domain'] ?? '') === $siteId) {
                return $installation;
            }
        }

        return null;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function modulePolicy(string $siteId): array
    {
        $config = $this->updateServerConfig();
        $policies = $this->modulePolicies($config);
        $installation = $this->installation($siteId) ?? ['site_id' => $siteId, 'domain' => ''];
        $key = $this->installationKey((string) ($installation['site_id'] ?? $siteId), (string) ($installation['domain'] ?? ''));
        $policy = $policies[$key] ?? $policies[$siteId] ?? [];

        return is_array($policy) ? $policy : [];
    }

    /**
     * @param array<string, bool> $modules
     */
    public function saveModulePolicy(string $siteId, array $modules, array $user): void
    {
        $config = $this->updateServerConfig();
        $path = $this->modulePoliciesPath($config);
        $data = $this->readJson($path, ['policies' => []]);
        $installation = $this->installation($siteId) ?? ['site_id' => $siteId, 'domain' => ''];
        $site = (string) ($installation['site_id'] ?? $siteId);
        $domain = (string) ($installation['domain'] ?? '');
        $normalized = [];

        foreach ($modules as $slug => $enabled) {
            $slug = preg_replace('/[^a-zA-Z0-9_.-]/', '', (string) $slug) ?: '';
            if ($slug === '') {
                continue;
            }
            $normalized[$slug] = (bool) $enabled;
        }

        $policy = [
            'site_id' => $site,
            'domain' => $domain,
            'modules' => $normalized,
            'updated_at' => date(DATE_ATOM),
            'updated_by' => (string) ($user['email'] ?? $user['name'] ?? 'Reklamova'),
        ];

        $policies = [];
        foreach (($data['policies'] ?? []) as $existing) {
            if (!is_array($existing)) {
                continue;
            }
            $existingSite = (string) ($existing['site_id'] ?? '');
            $existingDomain = (string) ($existing['domain'] ?? '');
            if ($existingSite === $site || ($domain !== '' && $existingDomain === $domain)) {
                continue;
            }
            $policies[] = $existing;
        }
        $policies[] = $policy;

        $this->writeJson($path, ['policies' => $policies]);
    }

    public function updateServerRoot(): ?string
    {
        foreach ($this->updateServerCandidates() as $candidate) {
            $candidate = rtrim(str_replace('\\', '/', $candidate), '/');
            if ($candidate !== '' && is_dir($candidate . '/storage')) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function updateServerConfig(): array
    {
        $root = $this->updateServerRoot();
        if ($root === null) {
            return [
                'storage_path' => '',
                'packages_path' => '',
                'licenses_path' => '',
                'reports_path' => '',
                'module_policies_path' => '',
            ];
        }

        $configPath = $root . '/config.php';
        $config = is_file($configPath) ? require $configPath : [];
        $config = is_array($config) ? $config : [];

        return array_merge([
            'storage_path' => $root . '/storage',
            'packages_path' => $root . '/storage/packages',
            'licenses_path' => $root . '/storage/licenses.json',
            'reports_path' => $root . '/storage/reports',
            'module_policies_path' => $root . '/storage/module-policies.json',
        ], $config);
    }

    /**
     * @return array<int, string>
     */
    private function updateServerCandidates(): array
    {
        $config = $this->appConfig();
        $root = (string) ($this->container['root_path'] ?? dirname(__DIR__, 3));

        return array_values(array_filter([
            (string) ($config['central_update_server_path'] ?? ''),
            (string) ($config['update_server_path'] ?? ''),
            $root . '/update-server',
            dirname($root) . '/updates.reklamova.pl',
            '/home/host379800/domains/updates.reklamova.pl',
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    private function appConfig(): array
    {
        $path = (string) ($this->container['config_path'] ?? '') . '/app.php';
        if (!is_file($path)) {
            return [];
        }

        $config = require $path;

        return is_array($config) ? $config : [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function licenses(array $config): array
    {
        $data = $this->readJson((string) ($config['licenses_path'] ?? ''), ['licenses' => []]);
        $licenses = $data['licenses'] ?? [];

        return is_array($licenses) ? array_values(array_filter($licenses, 'is_array')) : [];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function packageIndex(array $config): array
    {
        $data = $this->readJson(rtrim((string) ($config['packages_path'] ?? ''), '/\\') . '/index.json', ['packages' => []]);
        $packages = [];
        foreach (($data['packages'] ?? []) as $package) {
            if (is_array($package)) {
                $packages[] = $package;
            }
        }

        return $packages;
    }

    /**
     * @param array<int, array<string, mixed>> $packages
     */
    private function latestVersionForChannel(array $packages, string $channel): string
    {
        $latest = '';
        foreach ($packages as $package) {
            if ((string) ($package['channel'] ?? 'stable') !== $channel) {
                continue;
            }
            $version = (string) ($package['version'] ?? '');
            if ($version !== '' && ($latest === '' || version_compare($version, $latest, '>'))) {
                $latest = $version;
            }
        }

        return $latest;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function modulePolicies(array $config): array
    {
        $data = $this->readJson($this->modulePoliciesPath($config), ['policies' => []]);
        $rows = [];
        foreach (($data['policies'] ?? []) as $policy) {
            if (!is_array($policy)) {
                continue;
            }
            $siteId = (string) ($policy['site_id'] ?? '');
            $domain = (string) ($policy['domain'] ?? '');
            $key = $this->installationKey($siteId, $domain);
            if ($key !== '') {
                $rows[$key] = $policy;
            }
            if ($siteId !== '') {
                $rows[$siteId] = $policy;
            }
            if ($domain !== '') {
                $rows[$domain] = $policy;
            }
        }

        return $rows;
    }

    private function modulePoliciesPath(array $config): string
    {
        $path = (string) ($config['module_policies_path'] ?? '');
        if ($path !== '') {
            return $path;
        }

        return rtrim((string) ($config['storage_path'] ?? ''), '/\\') . '/module-policies.json';
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function latestReports(array $config, string $type): array
    {
        $path = rtrim((string) ($config['reports_path'] ?? ''), '/\\');
        if ($path === '' || !is_dir($path)) {
            return [];
        }

        $rows = [];
        foreach (glob($path . '/' . $type . '-*.jsonl') ?: [] as $file) {
            $handle = fopen($file, 'rb');
            if (!$handle) {
                continue;
            }
            while (($line = fgets($handle)) !== false) {
                $entry = json_decode(trim($line), true);
                if (!is_array($entry)) {
                    continue;
                }
                $siteId = (string) ($entry['site_id'] ?? '');
                $domain = (string) ($entry['domain'] ?? '');
                $key = $this->installationKey($siteId, $domain);
                $time = (string) ($entry['checked_at'] ?? $entry['created_at'] ?? $entry['payload']['reported_at'] ?? '');
                if ($key === '') {
                    continue;
                }
                if (!isset($rows[$key]) || strcmp($time, (string) ($rows[$key]['_time'] ?? '')) >= 0) {
                    $entry['_time'] = $time;
                    $rows[$key] = $entry;
                    if ($siteId !== '') {
                        $rows[$siteId] = $entry;
                    }
                    if ($domain !== '') {
                        $rows[$domain] = $entry;
                    }
                }
            }
            fclose($handle);
        }

        return $rows;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function latestUpdateReports(array $config): array
    {
        $rows = [];
        foreach (['update-started', 'update-finished', 'update-failed'] as $event) {
            foreach ($this->latestReports($config, $event) as $key => $report) {
                $time = (string) ($report['created_at'] ?? '');
                if (!isset($rows[$key]) || strcmp($time, (string) ($rows[$key]['created_at'] ?? '')) >= 0) {
                    $rows[$key] = $report;
                }
            }
        }

        return $rows;
    }

    private function hasInstallation(array $rows, string $siteId, string $domain): bool
    {
        foreach ($rows as $row) {
            if ($siteId !== '' && (string) ($row['site_id'] ?? '') === $siteId) {
                return true;
            }
            if ($domain !== '' && (string) ($row['domain'] ?? '') === $domain) {
                return true;
            }
        }

        return false;
    }

    private function installationKey(string $siteId, string $domain): string
    {
        return $siteId !== '' ? $siteId : $domain;
    }

    /**
     * @return array<string, mixed>
     */
    private function readJson(string $path, array $fallback): array
    {
        if ($path === '' || !is_file($path)) {
            return $fallback;
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : $fallback;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function writeJson(string $path, array $data): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $tmp = $path . '.tmp-' . bin2hex(random_bytes(4));
        file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);
        rename($tmp, $path);
    }
}
