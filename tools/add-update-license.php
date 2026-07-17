<?php

declare(strict_types=1);

$licensesFile = $argv[1] ?? '';
$siteId = $argv[2] ?? '';
$domain = $argv[3] ?? '';
$siteKey = $argv[4] ?? '';
$channel = $argv[5] ?? 'stable';

if ($licensesFile === '' || $siteId === '' || $domain === '') {
    fwrite(STDERR, "Usage: php add-update-license.php <licenses-json> <site-id> <domain> [site-key] [channel]\n");
    exit(1);
}

if ($siteKey === '') {
    $siteKey = 'rklv_' . bin2hex(random_bytes(32));
}

$index = is_file($licensesFile) ? json_decode((string) file_get_contents($licensesFile), true) : ['licenses' => []];
if (!is_array($index)) {
    $index = ['licenses' => []];
}

$licenses = $index['licenses'] ?? [];
if (!is_array($licenses)) {
    $licenses = [];
}

$updated = false;
foreach ($licenses as &$license) {
    if (($license['site_id'] ?? '') === $siteId || ($license['domain'] ?? '') === $domain) {
        $license['site_id'] = $siteId;
        $license['domain'] = $domain;
        $license['site_key'] = $siteKey;
        $license['channel'] = $channel;
        $license['status'] = 'active';
        $updated = true;
    }
}
unset($license);

if (!$updated) {
    $licenses[] = [
        'site_id' => $siteId,
        'site_key' => $siteKey,
        'domain' => $domain,
        'channel' => $channel,
        'status' => 'active',
    ];
}

file_put_contents(
    $licensesFile,
    json_encode(['licenses' => array_values($licenses)], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
);
@chmod($licensesFile, 0600);

echo "LICENSE_ADDED site_id={$siteId} domain={$domain} key_prefix=" . substr($siteKey, 0, 9) . " key_length=" . strlen($siteKey) . "\n";
echo $siteKey . "\n";
