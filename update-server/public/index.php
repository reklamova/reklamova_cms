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
