<?php

declare(strict_types=1);

use Reklamova\Cms\Database\ConnectionFactory;
use Reklamova\Cms\Health\HealthCheck;
use Reklamova\Cms\Modules\ModuleManager;
use Reklamova\Cms\Updates\UpdateClient;

require __DIR__ . '/app/bootstrap.php';

file_put_contents($container['storage_path'] . '/cron.last', date(DATE_ATOM));

try {
    $pdo = (new ConnectionFactory($container))->make();
    $modules = [];
    foreach ((new ModuleManager($container))->activeModules($pdo) as $slug => $module) {
        $modules[$slug] = (string) ($module['version'] ?? 'unknown');
    }

    (new UpdateClient($container))->check((new HealthCheck($container))->run(), $modules);
} catch (Throwable $exception) {
    if (!is_dir($container['storage_path'] . '/logs')) {
        mkdir($container['storage_path'] . '/logs', 0775, true);
    }
    file_put_contents($container['storage_path'] . '/logs/cron-update-check.log', date(DATE_ATOM) . ' ' . $exception->getMessage() . "\n", FILE_APPEND);
}

echo "Reklamova CMS cron OK\n";
