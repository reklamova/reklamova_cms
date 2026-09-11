<?php

declare(strict_types=1);

$source = file_get_contents(dirname(__DIR__) . '/app/modules/custom/powertech/public.php');
if (!is_string($source)) {
    throw new RuntimeException('Nie można odczytać modułu PowerTech.');
}

foreach ([
    "'/sitemap.xml' => \$sitemap",
    'SELECT slug FROM cms_pages WHERE status = "published"',
    '$repo->categories(true)',
    '$repo->products(true)',
    'array_unique($urls)',
    'ENT_XML1 | ENT_QUOTES',
] as $check) {
    if (!str_contains($source, $check)) {
        throw new RuntimeException('Brak elementu dynamicznej mapy strony: ' . $check);
    }
}

echo "POWERTECH_SITEMAP_TEST_OK\n";
