<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from CLI.\n");
    exit(1);
}

$root = dirname(__DIR__);
$version = option('version') ?: null;
$packageId = option('package-id') ?: null;
$channel = option('channel') ?: 'stable';
$baseUrl = rtrim(option('base-url') ?: 'https://updates.reklamova.pl', '/');
$outDir = option('out') ?: $root . '/build/update-packages';

if (!$version) {
    fwrite(STDERR, "Missing --version=x.y.z\n");
    exit(1);
}

if (!$packageId) {
    $packageId = 'pkg_core_' . str_replace('.', '_', $version);
}

if (!extension_loaded('zip') || !extension_loaded('sodium')) {
    fwrite(STDERR, "Required PHP extensions: zip, sodium.\n");
    exit(1);
}

$privateKey = getenv('REKLAMOVA_UPDATE_PRIVATE_KEY_B64') ?: '';
if ($privateKey === '') {
    fwrite(STDERR, "Set REKLAMOVA_UPDATE_PRIVATE_KEY_B64 before signing packages.\n");
    exit(1);
}

$privateKeyBytes = base64_decode($privateKey, true);
if ($privateKeyBytes === false || strlen($privateKeyBytes) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
    fwrite(STDERR, "Invalid REKLAMOVA_UPDATE_PRIVATE_KEY_B64.\n");
    exit(1);
}

$manifestConfig = json_decode((string) file_get_contents($root . '/reklamova.json'), true) ?: [];
$corePaths = $manifestConfig['core_paths'] ?? [];
$protectedPaths = $manifestConfig['protected_paths'] ?? [];
$work = sys_get_temp_dir() . '/reklamova-update-' . bin2hex(random_bytes(6));
$filesRoot = $work . '/files';
mkdir($filesRoot, 0775, true);

foreach ($corePaths as $relativePath) {
    $source = $root . '/' . $relativePath;
    if (!file_exists($source)) {
        continue;
    }
    assertNotProtected($relativePath, $protectedPaths);
    $target = $filesRoot . '/' . $relativePath;
    if (is_dir($source)) {
        copyDirectory($source, $target, $protectedPaths, $root);
        continue;
    }
    $targetDir = dirname($target);
    if (!is_dir($targetDir)) {
        mkdir($targetDir, 0775, true);
    }
    copy($source, $target);
}

$manifest = [
    'package_id' => $packageId,
    'type' => 'core',
    'version' => $version,
    'channel' => $channel,
    'from_versions' => ['>=0.1.0 <' . $version],
    'created_at' => date(DATE_ATOM),
    'requires' => [
        'php' => '>=8.3',
        'mysql' => '>=8.0 || mariadb >=10.6',
    ],
    'protected_paths' => $protectedPaths,
    'core_paths' => $corePaths,
];

file_put_contents($work . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
file_put_contents($work . '/checksums.json', json_encode(fileChecksums($filesRoot), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

if (!is_dir($outDir)) {
    mkdir($outDir, 0775, true);
}

$zipPath = rtrim($outDir, '/\\') . '/reklamova-core-' . $version . '.zip';
if (is_file($zipPath)) {
    unlink($zipPath);
}

$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    throw new RuntimeException('Cannot create ZIP.');
}
addToZip($zip, $work, $work);
$zip->close();

$sha256 = hash_file('sha256', $zipPath);
$message = $sha256 . "\n" . json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
$signature = base64_encode(sodium_crypto_sign_detached($message, $privateKeyBytes));
$indexEntry = [
    'id' => $packageId,
    'type' => 'core',
    'version' => $version,
    'channel' => $channel,
    'file' => basename($zipPath),
    'url' => $baseUrl . '/api/v1/packages/' . rawurlencode($packageId) . '/download',
    'sha256' => $sha256,
    'signature' => $signature,
    'signature_algorithm' => 'ed25519',
    'minimum_php' => '8.3',
];

file_put_contents(rtrim($outDir, '/\\') . '/index-entry-' . $packageId . '.json', json_encode($indexEntry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
removeDirectory($work);

echo json_encode([
    'zip' => $zipPath,
    'index_entry' => rtrim($outDir, '/\\') . '/index-entry-' . $packageId . '.json',
    'sha256' => $sha256,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

function option(string $name): ?string
{
    foreach ($_SERVER['argv'] as $arg) {
        if (str_starts_with($arg, '--' . $name . '=')) {
            return substr($arg, strlen($name) + 3);
        }
    }
    return null;
}

function assertNotProtected(string $path, array $protectedPaths): void
{
    $path = trim(str_replace('\\', '/', $path), '/');
    foreach ($protectedPaths as $protectedPath) {
        $protectedPath = trim((string) $protectedPath, '/');
        if ($path === $protectedPath || str_starts_with($path, $protectedPath . '/')) {
            throw new RuntimeException('Protected path in package: ' . $path);
        }
    }
}

function copyDirectory(string $source, string $target, array $protectedPaths, string $root): void
{
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $item) {
        $relative = trim(str_replace('\\', '/', substr($item->getPathname(), strlen($root) + 1)), '/');
        if (isProtected($relative, $protectedPaths)) {
            continue;
        }
        $destination = $target . '/' . $iterator->getSubPathName();
        if ($item->isDir()) {
            if (!is_dir($destination)) {
                mkdir($destination, 0775, true);
            }
            continue;
        }
        $destinationDir = dirname($destination);
        if (!is_dir($destinationDir)) {
            mkdir($destinationDir, 0775, true);
        }
        copy($item->getPathname(), $destination);
    }
}

function isProtected(string $path, array $protectedPaths): bool
{
    $path = trim(str_replace('\\', '/', $path), '/');
    foreach ($protectedPaths as $protectedPath) {
        $protectedPath = trim((string) $protectedPath, '/');
        if ($path === $protectedPath || str_starts_with($path, $protectedPath . '/')) {
            return true;
        }
    }

    return false;
}

function fileChecksums(string $path): array
{
    $checksums = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $item) {
        if ($item->isFile()) {
            $relative = str_replace('\\', '/', $iterator->getSubPathName());
            $checksums[$relative] = hash_file('sha256', $item->getPathname());
        }
    }
    ksort($checksums);
    return $checksums;
}

function addToZip(ZipArchive $zip, string $path, string $base): void
{
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $item) {
        $local = str_replace('\\', '/', substr($item->getPathname(), strlen($base) + 1));
        if ($item->isDir()) {
            $zip->addEmptyDir($local);
            continue;
        }
        $zip->addFile($item->getPathname(), $local);
    }
}

function removeDirectory(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($path);
}
