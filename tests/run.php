<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Reklamova\Cms\Auth\Csrf;
use Reklamova\Cms\Auth\PermissionManager;
use Reklamova\Cms\Media\MediaUploadPolicy;

$passed = 0;
$failed = 0;

$test = static function (string $name, callable $callback) use (&$passed, &$failed): void {
    try {
        $callback();
        $passed++;
        echo "PASS {$name}\n";
    } catch (Throwable $exception) {
        $failed++;
        fwrite(STDERR, "FAIL {$name}: {$exception->getMessage()}\n");
    }
};

$assert = static function (bool $condition, string $message = 'Assertion failed'): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$test('admin session cookies use secure defaults', static function () use ($assert): void {
    $_SERVER['HTTPS'] = 'on';
    Csrf::startSession();
    $params = session_get_cookie_params();
    $assert($params['secure'] === true);
    $assert($params['httponly'] === true);
    $assert(($params['samesite'] ?? '') === 'Lax');
    $assert(ini_get('session.use_strict_mode') === '1');
});

$test('internal access depends on role, not hostname', static function () use ($assert): void {
    $_SERVER['HTTP_HOST'] = 'cms.reklamova.pl';
    $manager = (new ReflectionClass(PermissionManager::class))->newInstanceWithoutConstructor();
    $assert(!$manager->isInternalUser(['role' => 'admin']));
    $assert($manager->isInternalUser(['role' => 'developer']));
});

$test('media policy accepts a real PNG', static function () use ($assert): void {
    $path = tempnam(sys_get_temp_dir(), 'reklamova-test-');
    if ($path === false) {
        throw new RuntimeException('Cannot create temporary file.');
    }
    try {
        file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true));
        $result = (new MediaUploadPolicy(1024))->validate([
            'error' => UPLOAD_ERR_OK,
            'tmp_name' => $path,
            'name' => 'pixel.png',
        ]);
        $assert($result['mime_type'] === 'image/png');
        $assert($result['size'] > 0);
    } finally {
        @unlink($path);
    }
});

$test('media policy rejects mismatched extension', static function (): void {
    $path = tempnam(sys_get_temp_dir(), 'reklamova-test-');
    if ($path === false) {
        throw new RuntimeException('Cannot create temporary file.');
    }
    try {
        file_put_contents($path, '<?php echo "unsafe";');
        try {
            (new MediaUploadPolicy(1024))->validate([
                'error' => UPLOAD_ERR_OK,
                'tmp_name' => $path,
                'name' => 'fake.png',
            ]);
        } catch (RuntimeException) {
            return;
        }
        throw new RuntimeException('Mismatched content was accepted.');
    } finally {
        @unlink($path);
    }
});

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
