<?php

declare(strict_types=1);

$source = file_get_contents(dirname(__DIR__) . '/app/core/Http/Application.php');
if (!is_string($source)) {
    throw new RuntimeException('Nie można odczytać Application.php.');
}

foreach ([
    '$notFound = !$page;',
    '$meta[\'robots\'] = \'noindex,nofollow\';',
    '$meta[\'canonical\'] = \'\';',
] as $check) {
    if (!str_contains($source, $check)) {
        throw new RuntimeException('Brak zabezpieczenia SEO strony 404: ' . $check);
    }
}

echo "NOT_FOUND_SEO_TEST_OK\n";
