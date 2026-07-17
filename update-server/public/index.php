<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$configPath = $root . '/config.php';
$config = is_file($configPath) ? require $configPath : require $root . '/config.example.php';

respond(handle($config));

function handle(array $config): array
{
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $path = rtrim($path, '/') ?: '/';

    if ($method === 'POST' && $path === '/api/v1/check-update') {
        return checkUpdate($config);
    }

    if ($method === 'GET' && preg_match('#^/api/v1/packages/([A-Za-z0-9_.-]+)/download$#', $path, $matches)) {
        return downloadPackage($config, $matches[1]);
    }

    if ($method === 'POST' && preg_match('#^/api/v1/report-(update-started|update-finished|update-failed|health)$#', $path, $matches)) {
        return storeReport($config, $matches[1]);
    }

    if ($method === 'GET' && $path === '/api/v1/central/installations') {
        return centralInstallations($config);
    }

    if ($method === 'POST' && $path === '/api/v1/central/module-policy') {
        return centralSaveModulePolicy($config);
    }

    if ($method === 'GET' && $path === '/') {
        return [200, ['service' => 'Reklamova Update Server', 'status' => 'ok']];
    }

    return [404, ['error' => 'not_found']];
}

function checkUpdate(array $config): array
{
    $payload = requestJson();
    $license = authorize($config);
    $index = packageIndex($config);
    $channel = (string) ($license['channel'] ?? $config['default_channel'] ?? 'stable');
    $currentVersion = (string) ($payload['cms_version'] ?? '0.0.0');
    $candidate = null;
    $modulePolicy = modulePolicyFor($config, $license);

    foreach ($index['packages'] ?? [] as $package) {
        if (($package['channel'] ?? 'stable') !== $channel) {
            continue;
        }
        if (version_compare((string) ($package['version'] ?? '0.0.0'), $currentVersion, '<=')) {
            continue;
        }
        if ($candidate === null || version_compare((string) $package['version'], (string) $candidate['version'], '>')) {
            $candidate = $package;
        }
    }

    $report = [
        'site_id' => $license['site_id'] ?? ($payload['site_id'] ?? ''),
        'domain' => $payload['domain'] ?? '',
        'cms_version' => $currentVersion,
        'php_version' => $payload['php_version'] ?? '',
        'database_version' => $payload['database_version'] ?? '',
        'active_modules' => $payload['active_modules'] ?? [],
        'theme' => $payload['theme'] ?? '',
        'core_checksum' => $payload['core_checksum'] ?? '',
        'health' => $payload['health'] ?? [],
        'checked_at' => date(DATE_ATOM),
        'update_available' => $candidate !== null,
    ];
    appendJsonl($config['reports_path'] . '/checks-' . date('Y-m') . '.jsonl', $report);

    if ($candidate === null) {
        return [200, [
            'update_available' => false,
            'current_version' => $currentVersion,
            'latest_version' => $currentVersion,
            'module_policy' => $modulePolicy,
        ]];
    }

    return [200, [
        'update_available' => true,
        'current_version' => $currentVersion,
        'latest_version' => $candidate['version'],
        'minimum_php' => $candidate['minimum_php'] ?? '8.3',
        'package' => publicPackagePayload($candidate),
        'module_policy' => $modulePolicy,
    ]];
}

function modulePolicyFor(array $config, array $license): ?array
{
    $path = (string) ($config['module_policies_path'] ?? '');
    if ($path === '') {
        $path = rtrim((string) ($config['storage_path'] ?? dirname((string) ($config['licenses_path'] ?? ''))), '/\\') . '/module-policies.json';
    }
    if (!is_file($path)) {
        return null;
    }

    $data = json_decode((string) file_get_contents($path), true) ?: [];
    $siteId = (string) ($license['site_id'] ?? '');
    $domain = (string) ($license['domain'] ?? '');
    foreach ($data['policies'] ?? [] as $policy) {
        if (!is_array($policy)) {
            continue;
        }
        $policySite = (string) ($policy['site_id'] ?? '');
        $policyDomain = (string) ($policy['domain'] ?? '');
        if (($siteId !== '' && $policySite === $siteId) || ($domain !== '' && $policyDomain === $domain)) {
            $modules = $policy['modules'] ?? [];
            if (!is_array($modules)) {
                $modules = [];
            }

            return [
                'site_id' => $policySite,
                'domain' => $policyDomain,
                'modules' => $modules,
                'updated_at' => (string) ($policy['updated_at'] ?? ''),
            ];
        }
    }

    return null;
}

function downloadPackage(array $config, string $packageId): array
{
    authorize($config);
    $index = packageIndex($config);
    foreach ($index['packages'] ?? [] as $package) {
        if (($package['id'] ?? '') !== $packageId) {
            continue;
        }

        $file = rtrim($config['packages_path'], '/\\') . '/' . basename((string) $package['file']);
        if (!is_file($file)) {
            return [404, ['error' => 'package_file_missing']];
        }

        header('Content-Type: application/zip');
        header('Content-Length: ' . filesize($file));
        header('Content-Disposition: attachment; filename="' . basename($file) . '"');
        readfile($file);
        exit;
    }

    return [404, ['error' => 'package_not_found']];
}

function storeReport(array $config, string $event): array
{
    $license = authorize($config);
    $payload = requestJson();
    appendJsonl($config['reports_path'] . '/' . $event . '-' . date('Y-m') . '.jsonl', [
        'event' => $event,
        'site_id' => $license['site_id'] ?? ($payload['site_id'] ?? ''),
        'domain' => $payload['domain'] ?? '',
        'payload' => $payload,
        'created_at' => date(DATE_ATOM),
    ]);

    return [200, ['ok' => true]];
}

function centralInstallations(array $config): array
{
    authorizeCentral($config);
    $licenses = centralLicenses($config);
    $checks = centralLatestReports($config, 'checks');
    $updates = centralLatestUpdateReports($config);
    $health = centralLatestReports($config, 'health');
    $packages = packageIndex($config)['packages'] ?? [];
    $policies = centralModulePolicies($config);
    $rows = [];

    foreach ($licenses as $license) {
        $siteId = (string) ($license['site_id'] ?? '');
        $domain = (string) ($license['domain'] ?? '');
        $key = centralInstallationKey($siteId, $domain);
        $check = $checks[$key] ?? $checks[$siteId] ?? $checks[$domain] ?? [];
        $latest = centralLatestVersionForChannel($packages, (string) ($license['channel'] ?? 'stable'));
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
        if (centralHasInstallation($rows, $siteId, $domain)) {
            continue;
        }
        $latest = centralLatestVersionForChannel($packages, 'stable');
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

    return [200, ['installations' => $rows]];
}

function centralSaveModulePolicy(array $config): array
{
    authorizeCentral($config);
    $payload = requestJson();
    $siteId = trim((string) ($payload['site_id'] ?? ''));
    $requestedModules = $payload['modules'] ?? [];
    if ($siteId === '' || !is_array($requestedModules)) {
        return [422, ['error' => 'invalid_module_policy']];
    }

    $license = null;
    foreach (centralLicenses($config) as $row) {
        if ((string) ($row['site_id'] ?? '') === $siteId || (string) ($row['domain'] ?? '') === $siteId) {
            $license = $row;
            break;
        }
    }

    if (!$license) {
        return [404, ['error' => 'installation_not_found']];
    }

    $modules = [];
    foreach ($requestedModules as $slug => $enabled) {
        $slug = preg_replace('/[^a-zA-Z0-9_.-]/', '', (string) $slug) ?: '';
        if ($slug !== '') {
            $modules[$slug] = (bool) $enabled;
        }
    }

    $policy = [
        'site_id' => (string) ($license['site_id'] ?? $siteId),
        'domain' => (string) ($license['domain'] ?? ''),
        'modules' => $modules,
        'updated_at' => date(DATE_ATOM),
        'updated_by' => (string) ($payload['updated_by'] ?? 'Reklamova'),
    ];

    $path = centralModulePoliciesPath($config);
    $data = centralReadJson($path, ['policies' => []]);
    $policies = [];
    foreach (($data['policies'] ?? []) as $existing) {
        if (!is_array($existing)) {
            continue;
        }
        if ((string) ($existing['site_id'] ?? '') === $policy['site_id'] || ($policy['domain'] !== '' && (string) ($existing['domain'] ?? '') === $policy['domain'])) {
            continue;
        }
        $policies[] = $existing;
    }
    $policies[] = $policy;
    centralWriteJson($path, ['policies' => $policies]);

    return [200, ['ok' => true, 'policy' => $policy]];
}

function authorizeCentral(array $config): void
{
    $expected = (string) ($config['central_admin_token'] ?? '');
    if ($expected === '') {
        jsonError(403, 'central_api_disabled');
    }
    if (!hash_equals($expected, bearerToken())) {
        jsonError(401, 'invalid_central_token');
    }
}

function centralLicenses(array $config): array
{
    $data = centralReadJson((string) ($config['licenses_path'] ?? ''), ['licenses' => []]);
    $licenses = $data['licenses'] ?? [];

    return is_array($licenses) ? array_values(array_filter($licenses, 'is_array')) : [];
}

function centralLatestReports(array $config, string $type): array
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
            $key = centralInstallationKey($siteId, $domain);
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

function centralLatestUpdateReports(array $config): array
{
    $rows = [];
    foreach (['update-started', 'update-finished', 'update-failed'] as $event) {
        foreach (centralLatestReports($config, $event) as $key => $report) {
            $time = (string) ($report['created_at'] ?? '');
            if (!isset($rows[$key]) || strcmp($time, (string) ($rows[$key]['created_at'] ?? '')) >= 0) {
                $rows[$key] = $report;
            }
        }
    }

    return $rows;
}

function centralModulePolicies(array $config): array
{
    $data = centralReadJson(centralModulePoliciesPath($config), ['policies' => []]);
    $rows = [];
    foreach (($data['policies'] ?? []) as $policy) {
        if (!is_array($policy)) {
            continue;
        }
        $siteId = (string) ($policy['site_id'] ?? '');
        $domain = (string) ($policy['domain'] ?? '');
        $key = centralInstallationKey($siteId, $domain);
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

function centralModulePoliciesPath(array $config): string
{
    $path = (string) ($config['module_policies_path'] ?? '');
    if ($path !== '') {
        return $path;
    }

    return rtrim((string) ($config['storage_path'] ?? ''), '/\\') . '/module-policies.json';
}

function centralLatestVersionForChannel(array $packages, string $channel): string
{
    $latest = '';
    foreach ($packages as $package) {
        if (!is_array($package) || (string) ($package['channel'] ?? 'stable') !== $channel) {
            continue;
        }
        $version = (string) ($package['version'] ?? '');
        if ($version !== '' && ($latest === '' || version_compare($version, $latest, '>'))) {
            $latest = $version;
        }
    }

    return $latest;
}

function centralHasInstallation(array $rows, string $siteId, string $domain): bool
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

function centralInstallationKey(string $siteId, string $domain): string
{
    return $siteId !== '' ? $siteId : $domain;
}

function centralReadJson(string $path, array $fallback): array
{
    if ($path === '' || !is_file($path)) {
        return $fallback;
    }

    $decoded = json_decode((string) file_get_contents($path), true);

    return is_array($decoded) ? $decoded : $fallback;
}

function centralWriteJson(string $path, array $data): void
{
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $tmp = $path . '.tmp-' . bin2hex(random_bytes(4));
    file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);
    rename($tmp, $path);
}

function authorize(array $config): array
{
    $token = bearerToken();
    $licenses = is_file($config['licenses_path']) ? json_decode((string) file_get_contents($config['licenses_path']), true) ?: [] : [];
    foreach ($licenses['licenses'] ?? [] as $license) {
        if (!empty($license['site_key']) && hash_equals((string) $license['site_key'], $token)) {
            if (($license['status'] ?? 'active') !== 'active') {
                jsonError(403, 'license_inactive');
            }
            return $license;
        }
    }

    jsonError(401, 'invalid_license');
}

function packageIndex(array $config): array
{
    $path = rtrim($config['packages_path'], '/\\') . '/index.json';
    return is_file($path) ? json_decode((string) file_get_contents($path), true) ?: ['packages' => []] : ['packages' => []];
}

function publicPackagePayload(array $package): array
{
    return [
        'id' => $package['id'],
        'type' => $package['type'] ?? 'core',
        'url' => $package['url'],
        'sha256' => $package['sha256'],
        'signature' => $package['signature'],
        'signature_algorithm' => $package['signature_algorithm'] ?? 'ed25519',
    ];
}

function requestJson(): array
{
    $raw = (string) file_get_contents('php://input');
    return json_decode($raw, true) ?: [];
}

function bearerToken(): string
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/Bearer\s+(.+)/i', $header, $matches)) {
        return trim($matches[1]);
    }

    return '';
}

function appendJsonl(string $path, array $payload): void
{
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    file_put_contents($path, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
}

function respond(array $response): void
{
    [$status, $payload] = $response;
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function jsonError(int $status, string $error): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => $error], JSON_UNESCAPED_SLASHES);
    exit;
}
