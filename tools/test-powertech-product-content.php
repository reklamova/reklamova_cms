<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$public = file_get_contents($root . '/app/modules/custom/powertech/public.php');
$admin = file_get_contents($root . '/app/modules/catalog/admin.php');
$script = file_get_contents($root . '/app/themes/powertech/assets/powertech.js');
$style = file_get_contents($root . '/app/themes/powertech/assets/powertech.css');

foreach ([$public, $admin, $script, $style] as $source) {
    if (!is_string($source)) {
        throw new RuntimeException('Nie można odczytać plików karty produktu PowerTech.');
    }
}

foreach ([
    '$renderTextWithLinks',
    "in_array(\$scheme, ['http', 'https'], true)",
    "!str_starts_with(\$url, '//')",
    'FILTER_VALIDATE_URL',
    'data-lightbox-image',
    'tabindex="0"',
    'catalog-product__description',
] as $check) {
    if (!str_contains($public, $check)) {
        throw new RuntimeException('Brak bezpiecznej obsługi treści produktu: ' . $check);
    }
}

foreach (['[Katalog PDF]', '[Film na YouTube]', 'Tekst w nawiasie kwadratowym będzie klikalny'] as $check) {
    if (!str_contains($admin, $check)) {
        throw new RuntimeException('Brak instrukcji linkowania w panelu: ' . $check);
    }
}

foreach (['pt-lightbox', 'aria-modal', 'Powiększone zdjęcie produktu', "event.key === 'Escape'", "event.key === 'ArrowRight'"] as $check) {
    if (!str_contains($script, $check)) {
        throw new RuntimeException('Brak elementu lightbox: ' . $check);
    }
}

foreach (['.pt-lightbox', 'cursor: zoom-in', '.catalog-product__description a'] as $check) {
    if (!str_contains($style, $check)) {
        throw new RuntimeException('Brak stylu powiększenia lub linków: ' . $check);
    }
}

echo "POWERTECH_PRODUCT_CONTENT_TEST_OK\n";
