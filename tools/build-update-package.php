<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from CLI.\n");
    exit(1);
}

$root = dirname(__DIR__);
$version = option('version') ?: null;
$packageId = option('package-id') ?: null;
$channel = option('channel');
$baseUrl = rtrim(option('base-url') ?: 'https://updates.reklamova.pl', '/');
$outDir = option('out') ?: $root . '/build/update-packages';
$validateOnly = flag('validate-only');

$allowedCorePaths = [
    'app/core',
    'app/migrations/core',
    'app/modules/business',
    'app/modules/catalog',
    'app/modules/forms',
    'app/modules/knowledge',
    'app/modules/landing',
    'app/modules/leads',
    'app/modules/media',
    'app/modules/pages',
    'app/modules/privacy',
    'app/modules/seo',
    'app/modules/trust',
    'app/modules/updates',
    'public/assets/core',
    'docs',
    'reklamova.json',
    'app/config/placements.example.php',
];

$allowedProtectedFiles = [
    'app/config/placements.example.php',
];

if (!$version) {
    fwrite(STDERR, "Missing --version=x.y.z\n");
    exit(1);
}

if (!$packageId) {
    $packageVersion = trim((string) preg_replace('/[^a-zA-Z0-9]+/', '_', $version), '_');
    $packageId = 'pkg_core_' . $packageVersion;
}

$manifestConfig = json_decode((string) file_get_contents($root . '/reklamova.json'), true) ?: [];
$corePaths = $manifestConfig['core_paths'] ?? [];
$protectedPaths = $manifestConfig['protected_paths'] ?? [];
$channel = $channel ?: (string) ($manifestConfig['update_channel'] ?? 'stable');
validateCorePackageScope($corePaths, $protectedPaths, $allowedCorePaths, $allowedProtectedFiles);

if ($validateOnly) {
    echo json_encode([
        'status' => 'valid',
        'version' => $version,
        'channel' => $channel,
        'package_id' => $packageId,
        'core_paths' => array_values($corePaths),
        'protected_paths' => array_values($protectedPaths),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(0);
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

$work = sys_get_temp_dir() . '/reklamova-update-' . bin2hex(random_bytes(6));
$filesRoot = $work . '/files';
mkdir($filesRoot, 0775, true);

foreach ($corePaths as $relativePath) {
    $source = $root . '/' . $relativePath;
    if (!file_exists($source)) {
        continue;
    }
    assertNotProtected($relativePath, $protectedPaths, $allowedProtectedFiles);
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

function flag(string $name): bool
{
    return in_array('--' . $name, $_SERVER['argv'], true);
}

function normalizePath(string $path): string
{
    return trim(str_replace('\\', '/', $path), '/');
}

function validateCorePackageScope(array $corePaths, array $protectedPaths, array $allowedCorePaths, array $allowedProtectedFiles): void
{
    if ($corePaths === []) {
        throw new RuntimeException('Core package paths are empty.');
    }

    $allowed = array_fill_keys(array_map('normalizePath', $allowedCorePaths), true);
    foreach ($corePaths as $path) {
        $path = normalizePath((string) $path);
        if ($path === 'app/modules/custom' || str_starts_with($path, 'app/modules/custom/')) {
            throw new RuntimeException('Custom modules must be released as site-specific patches: ' . $path);
        }

        if ($path === '' || !isset($allowed[$path])) {
            throw new RuntimeException('Path is outside the core package allowlist: ' . ($path ?: '[empty]'));
        }

        assertNotProtected($path, $protectedPaths, $allowedProtectedFiles);
    }
}

function assertNotProtected(string $path, array $protectedPaths, array $allowedProtectedFiles = []): void
{
    $path = normalizePath($path);
    $allowed = array_map('normalizePath', $allowedProtectedFiles);
    if (in_array($path, $allowed, true)) {
        return;
    }

    foreach ($protectedPaths as $protectedPath) {
        $protectedPath = normalizePath((string) $protectedPath);
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
