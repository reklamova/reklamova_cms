<?php

declare(strict_types=1);

$rootPath = dirname(__DIR__);
$_SERVER['DOCUMENT_ROOT'] = $rootPath;

require $rootPath . '/app/bootstrap.php';

if (!isset($container) || !is_array($container)) {
    throw new RuntimeException('Kontener CMS nie został zainicjalizowany.');
}

$expected = realpath($rootPath);
if ($expected === false || ($container['public_path'] ?? null) !== $expected) {
    throw new RuntimeException('Aktywny document root nie został wybrany jako katalog publiczny.');
}

echo "PUBLIC_PATH_TEST_OK\n";
