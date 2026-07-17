<?php

declare(strict_types=1);

$packagesDir = $argv[1] ?? '';
$entryFile = $argv[2] ?? '';

if ($packagesDir === '' || $entryFile === '') {
    fwrite(STDERR, "Usage: php update-package-index.php <packages-dir> <entry-file>\n");
    exit(1);
}

$entryPath = rtrim($packagesDir, '/\\') . DIRECTORY_SEPARATOR . $entryFile;
$indexPath = rtrim($packagesDir, '/\\') . DIRECTORY_SEPARATOR . 'index.json';
$entry = json_decode((string) file_get_contents($entryPath), true);

if (!is_array($entry) || empty($entry['id'])) {
    fwrite(STDERR, "Invalid package entry: {$entryPath}\n");
    exit(1);
}

$index = is_file($indexPath) ? json_decode((string) file_get_contents($indexPath), true) : ['packages' => []];
if (!is_array($index)) {
    $index = ['packages' => []];
}

$packages = $index['packages'] ?? [];
if (!is_array($packages)) {
    $packages = [];
}

$packages = array_values(array_filter($packages, static function (array $package) use ($entry): bool {
    return ($package['id'] ?? '') !== $entry['id'];
}));
$packages[] = $entry;

usort($packages, static function (array $a, array $b): int {
    return version_compare((string) ($b['version'] ?? '0'), (string) ($a['version'] ?? '0'));
});

file_put_contents(
    $indexPath,
    json_encode(['packages' => $packages], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
);

echo 'INDEX_UPDATED ' . count($packages) . " packages\n";
