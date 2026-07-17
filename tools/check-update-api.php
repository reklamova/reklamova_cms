<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$config = require $root . '/app/config/license.php';
$app = require $root . '/app/bootstrap.php';
$version = $argv[1] ?? ($app['cms_version'] ?? '0.0.0');
$server = (string) ($config['update_server'] ?? $config['license_server'] ?? '');
$endpoint = rtrim($server, '/') . '/api/v1/check-update';
$siteKey = (string) ($config['site_key'] ?? '');

if ($endpoint === '/api/v1/check-update' || $siteKey === '') {
    fwrite(
        STDERR,
        'Missing update server or site key. server=' . ($server !== '' ? 'yes' : 'no') .
        ' site_key=' . ($siteKey !== '' ? 'yes' : 'no') . "\n"
    );
    exit(1);
}

$payload = json_encode([
    'domain' => $config['domain'] ?? ($_SERVER['HTTP_HOST'] ?? 'unknown'),
    'cms_version' => $version,
    'php_version' => PHP_VERSION,
    'active_modules' => $app['active_modules'] ?? [],
], JSON_THROW_ON_ERROR);

$ch = curl_init($endpoint);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $siteKey,
        'Content-Type: application/json',
    ],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 15,
]);

$body = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
$error = curl_error($ch);
unset($ch);

echo "HTTP {$status}\n";
if ($error !== '') {
    echo "ERROR {$error}\n";
}

$data = json_decode((string) $body, true);
if (!is_array($data)) {
    echo "BODY_INVALID\n";
    exit($status >= 200 && $status < 300 ? 0 : 1);
}

echo 'update_available=' . (!empty($data['update_available']) ? 'yes' : 'no') . "\n";
if (isset($data['latest_version'])) {
    echo 'latest_version=' . $data['latest_version'] . "\n";
}
if (isset($data['package']['id'])) {
    echo 'package_id=' . $data['package']['id'] . "\n";
}
if (isset($data['package']['url'])) {
    echo 'package_url=set' . "\n";
}
