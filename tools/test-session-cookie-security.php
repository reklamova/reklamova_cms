<?php

declare(strict_types=1);

use Reklamova\Cms\Auth\Csrf;

require dirname(__DIR__) . '/app/core/Auth/Csrf.php';

$_SERVER['HTTPS'] = 'on';
session_name('REKLAMOVA_COOKIE_SECURITY_TEST');
session_id(bin2hex(random_bytes(16)));
Csrf::startSession();

$params = session_get_cookie_params();
$expected = [
    'path' => '/',
    'secure' => true,
    'httponly' => true,
    'samesite' => 'Lax',
];

foreach ($expected as $key => $value) {
    if (($params[$key] ?? null) !== $value) {
        throw new RuntimeException('Niepoprawny parametr cookie sesji: ' . $key);
    }
}

$_SESSION = [];
session_destroy();

echo "SESSION_COOKIE_SECURITY_TEST_OK\n";
