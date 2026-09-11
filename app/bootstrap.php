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

foreach (glob(__DIR__ . '/modules/*/helpers.php') ?: [] as $helperFile) {
    require_once $helperFile;
}

foreach (glob(__DIR__ . '/modules/custom/*/helpers.php') ?: [] as $helperFile) {
    require_once $helperFile;
}

$rootPath = dirname(__DIR__);
$appConfigPath = $rootPath . '/app/config/app.php';
$appConfig = is_file($appConfigPath) ? require $appConfigPath : [];

$publicPath = null;
$configuredPublicPath = trim((string) ($appConfig['public_path'] ?? ''));
if ($configuredPublicPath !== '') {
    $isAbsolutePath = str_starts_with($configuredPublicPath, '/')
        || preg_match('/^[A-Za-z]:[\\\\\/]/', $configuredPublicPath) === 1;
    $candidatePublicPath = $isAbsolutePath
        ? $configuredPublicPath
        : $rootPath . DIRECTORY_SEPARATOR . $configuredPublicPath;
    if (is_dir($candidatePublicPath)) {
        $publicPath = realpath($candidatePublicPath) ?: $candidatePublicPath;
    }
}

// Some shared hosts deploy the CMS with the installation root itself as the
// document root while retaining the package's public/ directory. Prefer the
// active web-server document root when it belongs to this installation so new
// uploads are written to the directory that actually serves their public URL.
$documentRoot = trim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
if ($publicPath === null && $documentRoot !== '') {
    $resolvedRootPath = realpath($rootPath);
    $resolvedDocumentRoot = realpath($documentRoot);
    if (
        $resolvedRootPath !== false
        && $resolvedDocumentRoot !== false
        && (
            $resolvedDocumentRoot === $resolvedRootPath
            || str_starts_with($resolvedDocumentRoot, $resolvedRootPath . DIRECTORY_SEPARATOR)
        )
    ) {
        $publicPath = $resolvedDocumentRoot;
    }
}

$publicPath ??= is_dir($rootPath . '/public_html')
    ? $rootPath . '/public_html'
    : $rootPath . '/public';

$container = [
    'root_path' => $rootPath,
    'app_path' => $rootPath . '/app',
    'public_path' => $publicPath,
    'storage_path' => $rootPath . '/app/storage',
    'config_path' => $rootPath . '/app/config',
    'cms_version' => \Reklamova\Cms\Version::current(),
    'active_modules' => $appConfig['active_modules'] ?? [],
];
