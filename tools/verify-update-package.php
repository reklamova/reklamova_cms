<?php

declare(strict_types=1);

use Reklamova\Cms\Updates\PackageVerifier;

$root = dirname(__DIR__);
require $root . '/app/core/Updates/PackageVerifier.php';

$zipPath = $argv[1] ?? '';
$entryPath = $argv[2] ?? '';
$channel = $argv[3] ?? 'stable';

if ($zipPath === '' || $entryPath === '') {
    fwrite(STDERR, "Usage: php verify-update-package.php <zip-path> <entry-json> [channel]\n");
    exit(1);
}

$entry = json_decode((string) file_get_contents($entryPath), true);
$keys = require $root . '/app/core/Updates/trusted_keys.php';
$publicKey = (string) ($keys[$channel] ?? '');

if (!is_array($entry) || $publicKey === '') {
    fwrite(STDERR, "Missing package entry or trusted key.\n");
    exit(1);
}

$manifest = (new PackageVerifier($publicKey))->verify(
    $zipPath,
    (string) ($entry['sha256'] ?? ''),
    (string) ($entry['signature'] ?? '')
);

$zip = new ZipArchive();
$fileCount = 0;
if ($zip->open($zipPath) === true) {
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = (string) ($zip->statIndex($i)['name'] ?? '');
        if (str_starts_with($name, 'files/') && !str_ends_with($name, '/')) {
            $fileCount++;
        }
    }
    $zip->close();
}

echo 'PACKAGE_VERIFIED version=' . ($manifest['version'] ?? 'unknown') . ' files=' . $fileCount . "\n";
