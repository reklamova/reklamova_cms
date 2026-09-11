<?php

declare(strict_types=1);

use Reklamova\Cms\Commerce\Import\WordPressDatabaseReader;

require dirname(__DIR__) . '/app/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$arguments = array_slice($argv, 1);
$output = '';
foreach ($arguments as $argument) {
    if (str_starts_with($argument, '--output=')) {
        $output = substr($argument, strlen('--output='));
    }
}
if ($output === '') {
    fwrite(STDERR, "Use --output=app/storage/private-import/woocommerce-snapshot.json\n");
    exit(2);
}

$configPath = $container['config_path'] . '/commerce-import.php';
if (!is_file($configPath)) {
    fwrite(STDERR, "Missing app/config/commerce-import.php.\n");
    exit(2);
}
$config = require $configPath;
$source = is_array($config['source'] ?? null) ? $config['source'] : [];
foreach (['host', 'database', 'username', 'table_prefix'] as $required) {
    if (trim((string) ($source[$required] ?? '')) === '') {
        fwrite(STDERR, "Missing source configuration: {$required}\n");
        exit(2);
    }
}

$storageRoot = realpath((string) $container['storage_path']);
if ($storageRoot === false) {
    fwrite(STDERR, "Storage directory is unavailable.\n");
    exit(2);
}
$outputPath = str_starts_with($output, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $output) === 1
    ? $output
    : dirname(__DIR__) . '/' . ltrim($output, '/\\');
$directory = dirname($outputPath);
if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
    fwrite(STDERR, "Cannot create snapshot directory.\n");
    exit(2);
}
$resolvedDirectory = realpath($directory);
if ($resolvedDirectory === false || !str_starts_with(
    rtrim($resolvedDirectory, '/\\') . DIRECTORY_SEPARATOR,
    rtrim($storageRoot, '/\\') . DIRECTORY_SEPARATOR,
)) {
    fwrite(STDERR, "Snapshot must be stored below app/storage.\n");
    exit(2);
}
if (is_link($outputPath)) {
    fwrite(STDERR, "Snapshot path cannot be a symbolic link.\n");
    exit(2);
}

$dsn = sprintf(
    'mysql:host=%s;port=%d;dbname=%s;charset=%s',
    $source['host'],
    (int) ($source['port'] ?? 3306),
    $source['database'],
    $source['charset'] ?? 'utf8mb4',
);
$pdo = new PDO($dsn, (string) $source['username'], (string) ($source['password'] ?? ''), [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$snapshot = (new WordPressDatabaseReader($pdo, (string) $source['table_prefix']))->snapshot();
$json = json_encode(
    $snapshot,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR,
) . PHP_EOL;
if (file_put_contents($outputPath, $json, LOCK_EX) === false) {
    fwrite(STDERR, "Cannot write snapshot.\n");
    exit(2);
}
@chmod($outputPath, 0600);

echo json_encode([
    'status' => 'exported',
    'counts' => $snapshot['counts'] ?? [],
    'sha256' => hash('sha256', $json),
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . PHP_EOL;
