<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$repository = file_get_contents($root . '/app/modules/catalog/src/CatalogRepository.php');
$migration = file_get_contents($root . '/app/modules/catalog/migrations/2026_09_11_000002_create_catalog_redirects.php');
$catalogPublic = file_get_contents($root . '/app/modules/catalog/public.php');
$powertechPublic = file_get_contents($root . '/app/modules/custom/powertech/public.php');
foreach ([$repository, $migration, $catalogPublic, $powertechPublic] as $source) {
    if (!is_string($source)) {
        throw new RuntimeException('Nie można odczytać obsługi historii adresów katalogu.');
    }
}

foreach (['CREATE TABLE IF NOT EXISTS catalog_redirects', 'UNIQUE KEY uq_catalog_redirects_old_path', 'produkcja-przyrzadow-specjalnych', 'produkcja-przyrzadow-pomiarowych'] as $check) {
    if (!str_contains($migration, $check)) {
        throw new RuntimeException('Migracja nie zawiera wymaganej historii adresów: ' . $check);
    }
}
foreach (['redirectForPath', 'catalogPathsForCategoryTree', 'rememberChangedPaths', 'rememberRedirect', 'UPDATE catalog_redirects SET new_path', 'for ($hop = 0; $hop < 8; $hop++)', '!$this->findCategoryByPath($current)', '!$this->findProductByPath($current)'] as $check) {
    if (!str_contains($repository, $check)) {
        throw new RuntimeException('Repozytorium nie zapisuje lub nie rozwiązuje przekierowań: ' . $check);
    }
}
foreach ([$catalogPublic, $powertechPublic] as $source) {
    if (!str_contains($source, '$repo->redirectForPath($path)') || !str_contains($source, 'true, 301')) {
        throw new RuntimeException('Frontend katalogu nie obsługuje automatycznego przekierowania 301.');
    }
}

echo "CATALOG_REDIRECT_HISTORY_TEST_OK\n";
