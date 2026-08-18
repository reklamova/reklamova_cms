<?php

declare(strict_types=1);

$source = file_get_contents(dirname(__DIR__) . '/app/modules/custom/powertech/public.php');
if (!is_string($source)) {
    throw new RuntimeException('Nie można odczytać renderera PowerTech.');
}

$required = [
    '<meta name="robots" content="index,follow">',
    '<link rel="canonical"',
    '<meta property="og:title"',
    '<meta property="og:url"',
    '<meta property="og:description"',
    "mb_stripos(\$title, \$siteName",
];

foreach ($required as $markup) {
    if (!str_contains($source, $markup)) {
        throw new RuntimeException('Brak wymaganego znacznika SEO: ' . $markup);
    }
}

if (str_contains($source, '<section class="catalog-product"><figure') && !str_contains($source, "'<h2>' . \$h(\$product['name'])")) {
    throw new RuntimeException('Nazwa produktu powinna być nagłówkiem H2 pod głównym H1 strony.');
}

echo "POWERTECH_SEO_TEST_OK\n";
