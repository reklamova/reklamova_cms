<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'Reklamova\\Cms\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/core/' . str_replace('\\', '/', $relative) . '.php';

    if (is_file($path)) {
        require $path;
    }
});

$rootPath = dirname(__DIR__);
$appConfigPath = $rootPath . '/app/config/app.php';
$appConfig = is_file($appConfigPath) ? require $appConfigPath : [];

$container = [
    'root_path' => $rootPath,
    'app_path' => $rootPath . '/app',
    'public_path' => is_dir($rootPath . '/public_html') ? $rootPath . '/public_html' : $rootPath . '/public',
    'storage_path' => $rootPath . '/app/storage',
    'config_path' => $rootPath . '/app/config',
    'cms_version' => '0.1.0',
    'active_modules' => $appConfig['active_modules'] ?? [],
];
