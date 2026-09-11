<?php

declare(strict_types=1);

use Reklamova\Cms\Auth\AuthManager;
use Reklamova\Cms\Auth\Csrf;
use Reklamova\Cms\Database\ConnectionFactory;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This test must be run from the command line.\n");
    exit(1);
}

$rootPath = realpath($argv[1] ?? dirname(__DIR__));
if (!is_string($rootPath) || !is_file($rootPath . '/app/bootstrap.php')) {
    fwrite(STDERR, "Usage: php tools/test-auth-session.php [installation-root]\n");
    exit(1);
}

require $rootPath . '/app/bootstrap.php';

if (!isset($container) || !is_array($container)) {
    throw new RuntimeException('CMS container was not initialized.');
}

session_name('REKLAMOVA_AUTH_TEST');
session_id(bin2hex(random_bytes(16)));

$pdo = (new ConnectionFactory($container))->make();
$auth = new AuthManager($pdo);
$testId = bin2hex(random_bytes(8));
$email = 'auth-session-' . $testId . '@example.invalid';
$password = bin2hex(random_bytes(24));
$userId = null;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

try {
    $insert = $pdo->prepare('INSERT INTO cms_users (email, name, password_hash, role, active) VALUES (?, ?, ?, ?, 1)');
    $insert->execute([$email, 'Auth session test', password_hash($password, PASSWORD_DEFAULT), 'client_admin']);
    $userId = (int) $pdo->lastInsertId();

    Csrf::startSession();
    $sessionBeforeLogin = session_id();
    $assert($auth->attempt($email, $password), 'A valid active user could not log in.');
    $assert(session_id() !== $sessionBeforeLogin, 'The session identifier was not rotated after login.');

    $current = $auth->user();
    $assert(is_array($current) && ($current['id'] ?? 0) === $userId, 'The active session user was not returned.');

    $update = $pdo->prepare('UPDATE cms_users SET name = ?, role = ? WHERE id = ?');
    $update->execute(['Updated auth session test', 'editor', $userId]);
    $current = $auth->user();
    $assert(
        is_array($current)
        && ($current['name'] ?? '') === 'Updated auth session test'
        && ($current['role'] ?? '') === 'editor',
        'Current account data was not refreshed from the database.'
    );

    $pdo->prepare('UPDATE cms_users SET active = 0 WHERE id = ?')->execute([$userId]);
    $assert($auth->user() === null, 'A deactivated user kept an authenticated session.');

    $pdo->prepare('UPDATE cms_users SET active = 1 WHERE id = ?')->execute([$userId]);
    $assert($auth->attempt($email, $password), 'A reactivated user could not log in.');
    $pdo->prepare('DELETE FROM cms_users WHERE id = ?')->execute([$userId]);
    $userId = null;
    $assert($auth->user() === null, 'A deleted user kept an authenticated session.');

    fwrite(STDOUT, "Auth session tests passed.\n");
} finally {
    if (is_int($userId) && $userId > 0) {
        $pdo->prepare('DELETE FROM cms_users WHERE id = ?')->execute([$userId]);
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION = [];
        session_destroy();
    }
}
