<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$public = file_get_contents($root . '/app/modules/custom/powertech/public.php');
$admin = file_get_contents($root . '/app/modules/catalog/admin.php');
$script = file_get_contents($root . '/app/themes/powertech/assets/powertech.js');
$style = file_get_contents($root . '/app/themes/powertech/assets/powertech.css');
$adminScript = file_get_contents($root . '/public/assets/core/admin-shell.js');
$adminStyle = file_get_contents($root . '/public/assets/core/admin-2026.css');

foreach ([$public, $admin, $script, $style, $adminScript, $adminStyle] as $source) {
    if (!is_string($source)) {
        throw new RuntimeException('Nie można odczytać plików karty produktu PowerTech.');
    }
}

foreach ([
    'TextFormatter::withLinks',
    'data-lightbox-image',
    'tabindex="0"',
    'catalog-product__description',
] as $check) {
    if (!str_contains($public, $check)) {
        throw new RuntimeException('Brak bezpiecznej obsługi treści produktu: ' . $check);
    }
}

foreach (['data-content-editor', 'Zaznacz tekst'] as $check) {
    if (!str_contains($admin, $check)) {
        throw new RuntimeException('Brak instrukcji linkowania w panelu: ' . $check);
    }
}

foreach (['text-link-dialog', 'showModal()', "'{new-tab}'", 'Otwórz link w nowej karcie', 'setRangeText'] as $check) {
    if (!str_contains($adminScript, $check)) {
        throw new RuntimeException('Brak obsługi modala linków w edytorze: ' . $check);
    }
}

if (!str_contains($adminStyle, '.text-link-dialog')) {
    throw new RuntimeException('Brak stylów modala linków.');
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
