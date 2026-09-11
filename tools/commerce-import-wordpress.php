<?php

declare(strict_types=1);

use Reklamova\Cms\Commerce\Import\CommerceImportRepository;
use Reklamova\Cms\Commerce\Import\ProductMediaMigrator;
use Reklamova\Cms\Commerce\Import\WordPressDatabaseReader;
use Reklamova\Cms\Commerce\Import\WordPressImporter;
use Reklamova\Cms\Database\ConnectionFactory;
use Reklamova\Cms\Pages\PageRepository;

require dirname(__DIR__) . '/app/bootstrap.php';

$arguments = array_slice($argv, 1);
$apply = in_array('--apply', $arguments, true);
$dryRun = !$apply;
$reportPath = null;
$snapshotPath = null;
foreach ($arguments as $argument) {
    if (str_starts_with($argument, '--report=')) {
        $reportPath = substr($argument, strlen('--report='));
    }
    if (str_starts_with($argument, '--snapshot=')) {
        $snapshotPath = substr($argument, strlen('--snapshot='));
    }
}

$configPath = $container['config_path'] . '/commerce-import.php';
if (!is_file($configPath)) {
    fwrite(STDERR, "Missing app/config/commerce-import.php. Copy the example and provide a read-only WordPress database user.\n");
    exit(2);
}
$config = require $configPath;
$source = is_array($config['source'] ?? null) ? $config['source'] : [];
$store = is_array($config['store'] ?? null) ? $config['store'] : [];
if ($snapshotPath === null) {
    foreach (['host', 'database', 'username', 'table_prefix'] as $required) {
        if (trim((string) ($source[$required] ?? '')) === '') {
            fwrite(STDERR, "Missing source configuration: {$required}\n");
            exit(2);
        }
    }
}
foreach (['code', 'name', 'currency', 'country_code'] as $required) {
    if (trim((string) ($store[$required] ?? '')) === '') {
        fwrite(STDERR, "Missing store configuration: {$required}\n");
        exit(2);
    }
}

$targetPdo = (new ConnectionFactory($container))->make();
if ($snapshotPath !== null) {
    if (!is_file($snapshotPath) || filesize($snapshotPath) > 1073741824) {
        fwrite(STDERR, "Snapshot is missing or too large.\n");
        exit(2);
    }
    $snapshot = json_decode((string) file_get_contents($snapshotPath), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($snapshot) || ($snapshot['source_system'] ?? null) !== 'woocommerce') {
        fwrite(STDERR, "Snapshot format is invalid.\n");
        exit(2);
    }
} else {
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $source['host'],
        (int) ($source['port'] ?? 3306),
        $source['database'],
        $source['charset'] ?? 'utf8mb4'
    );
    $sourcePdo = new PDO($dsn, (string) $source['username'], (string) ($source['password'] ?? ''), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $snapshot = (new WordPressDatabaseReader($sourcePdo, (string) $source['table_prefix']))->snapshot();
}
$media = null;
if ($apply) {
    $uploadsPath = (string) ($source['uploads_path'] ?? '');
    if ($uploadsPath === '') {
        fwrite(STDERR, "A real import requires source.uploads_path.\n");
        exit(2);
    }
    $media = new ProductMediaMigrator($uploadsPath, $container['public_path']);
}

$report = (new WordPressImporter(new CommerceImportRepository($targetPdo), $media, new PageRepository($targetPdo)))
    ->run($snapshot, $store + ['prices_include_tax' => true], $dryRun);
$json = json_encode(
    $report,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
) . PHP_EOL;
echo $json;

if (is_string($reportPath) && $reportPath !== '') {
    $directory = dirname($reportPath);
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        fwrite(STDERR, "Cannot create report directory.\n");
        exit(2);
    }
    if (file_put_contents($reportPath, $json, LOCK_EX) === false) {
        fwrite(STDERR, "Cannot write import report.\n");
        exit(2);
    }
}

exit(in_array($report['status'] ?? '', ['dry_run_ok', 'imported'], true) ? 0 : 1);
