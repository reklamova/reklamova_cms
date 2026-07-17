<?php

declare(strict_types=1);

$installRoot = $argv[1] ?? '';
$updateRoot = $argv[2] ?? '';
$domain = $argv[3] ?? '';

if ($installRoot === '' || $updateRoot === '' || $domain === '') {
    fwrite(STDERR, "Usage: php provision-update-license.php <install-root> <update-root> <domain>\n");
    exit(1);
}

$licenseFile = rtrim($installRoot, '/\\') . '/app/config/license.php';
$licensesFile = rtrim($updateRoot, '/\\') . '/storage/licenses.json';

$license = is_file($licenseFile) ? require $licenseFile : [];
if (!is_array($license)) {
    $license = [];
}

$siteId = (string) ($license['site_id'] ?? '');
if ($siteId === '') {
    $siteId = uuidv4();
}

$siteKey = (string) ($license['site_key'] ?? '');
if ($siteKey === '') {
    $siteKey = 'rklv_' . bin2hex(random_bytes(32));
}

$license['site_id'] = $siteId;
$license['site_key'] = $siteKey;
$license['license_server'] = $license['license_server'] ?? 'https://updates.reklamova.pl';

$licensePhp = "<?php\n\nreturn " . var_export($license, true) . ";\n";
file_put_contents($licenseFile, $licensePhp);
@chmod($licenseFile, 0600);

$index = is_file($licensesFile) ? json_decode((string) file_get_contents($licensesFile), true) : ['licenses' => []];
if (!is_array($index)) {
    $index = ['licenses' => []];
}

$licenses = $index['licenses'] ?? [];
if (!is_array($licenses)) {
    $licenses = [];
}

$updated = false;
foreach ($licenses as &$entry) {
    if (($entry['site_id'] ?? '') === $siteId || ($entry['domain'] ?? '') === $domain) {
        $entry['site_id'] = $siteId;
        $entry['site_key'] = $siteKey;
        $entry['domain'] = $domain;
        $entry['channel'] = $entry['channel'] ?? 'stable';
        $entry['status'] = 'active';
        $updated = true;
    }
}
unset($entry);

if (!$updated) {
    $licenses[] = [
        'site_id' => $siteId,
        'site_key' => $siteKey,
        'domain' => $domain,
        'channel' => 'stable',
        'status' => 'active',
    ];
}

file_put_contents(
    $licensesFile,
    json_encode(['licenses' => array_values($licenses)], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
);
@chmod($licensesFile, 0600);

echo "LICENSE_PROVISIONED domain={$domain} site_key=set\n";

function uuidv4(): string
{
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}
