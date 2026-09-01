<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/core/Content/TextFormatter.php';
require_once dirname(__DIR__) . '/app/core/Pages/PageRenderer.php';

use Reklamova\Cms\Content\TextFormatter;
use Reklamova\Cms\Pages\PageRenderer;

$sameTab = TextFormatter::withLinks('Zobacz [katalog](https://example.com/catalog.pdf).');
$newTab = TextFormatter::withLinks('[film](https://youtube.com/watch?v=123){new-tab}');
$local = TextFormatter::withLinks('[PDF](/uploads/catalog.pdf)');
$unsafe = TextFormatter::withLinks('[klik](javascript:alert(1))');
$unsafeNetworkPath = TextFormatter::withLinks('[klik](//example.com/path)');
$unsafeBackslash = TextFormatter::withLinks('[klik](/\\example.com/path)');
$escaped = TextFormatter::withLinks('<script>alert(1)</script>');
$multiple = TextFormatter::withLinks("[Pierwszy](/pierwszy)\n[Drugi](https://example.com/drugi){new-tab}");

if (!str_contains($sameTab, '<a href="https://example.com/catalog.pdf">katalog</a>') || str_contains($sameTab, 'target=')) {
    throw new RuntimeException('Link w tej samej karcie jest renderowany niepoprawnie.');
}
if (!str_contains($newTab, 'target="_blank" rel="noopener noreferrer"')) {
    throw new RuntimeException('Brak bezpiecznej obsługi nowej karty.');
}
if (!str_contains($local, '<a href="/uploads/catalog.pdf">PDF</a>')) {
    throw new RuntimeException('Lokalny link nie jest renderowany.');
}
if (str_contains($unsafe, '<a ') || !str_contains($unsafe, 'javascript:')) {
    throw new RuntimeException('Niebezpieczny protokół nie został odrzucony.');
}
if (str_contains($unsafeNetworkPath . $unsafeBackslash, '<a ')) {
    throw new RuntimeException('Niebezpieczna ścieżka sieciowa nie została odrzucona.');
}
if (str_contains($escaped, '<script>') || !str_contains($escaped, '&lt;script&gt;')) {
    throw new RuntimeException('Treść nie jest prawidłowo escapowana.');
}
if (substr_count($multiple, '<a ') !== 2 || !str_contains($multiple, '<br')) {
    throw new RuntimeException('Wiele linków lub podziały wierszy są renderowane niepoprawnie.');
}

$page = [
    'title' => 'Test',
    'settings_json' => '{}',
    'blocks_json' => json_encode([
        ['type' => 'hero', 'title' => 'Hero', 'text' => '[Link](https://example.com/docs)'],
        ['type' => 'text', 'title' => 'Tekst', 'text' => '[Link](https://example.com/docs)'],
        ['type' => 'image_text', 'title' => 'Obraz', 'text' => '[Link](https://example.com/docs)'],
        ['type' => 'cards', 'title' => 'Karty', 'text' => '[Link](https://example.com/docs)', 'items' => []],
        ['type' => 'cta', 'title' => 'CTA', 'text' => '[Link](https://example.com/docs)'],
        ['type' => 'gallery', 'title' => 'Galeria', 'text' => '[Link](https://example.com/docs)', 'gallery' => [['url' => '/uploads/test.webp']]],
        ['type' => 'map', 'title' => 'Mapa', 'text' => '[Link](https://example.com/docs)'],
        ['type' => 'form', 'title' => 'Formularz', 'text' => '[Link](https://example.com/docs)'],
    ], JSON_UNESCAPED_SLASHES),
];
$pageHtml = (new PageRenderer())->render($page);
if (substr_count($pageHtml, '<a href="https://example.com/docs">Link</a>') !== 8) {
    throw new RuntimeException('Nie wszystkie typy sekcji podstrony korzystają ze wspólnego renderera linków.');
}

$root = dirname(__DIR__);
$adminScript = file_get_contents($root . '/public/assets/core/admin-shell.js');
if (!is_string($adminScript)) {
    throw new RuntimeException('Nie można odczytać skryptu edytora.');
}
foreach (['textarea[data-content-editor]', 'content-link-dialog', 'textLinkEditor', 'showModal()', 'setRangeText', "'{new-tab}'", 'findSelectedLink'] as $check) {
    if (!str_contains($adminScript, $check)) {
        throw new RuntimeException('Brak globalnej funkcji edytora: ' . $check);
    }
}
if (str_contains($adminScript, 'contentFieldNames') || str_contains($adminScript, "querySelectorAll('textarea[name]')")) {
    throw new RuntimeException('Edytor nie może zgadywać typu pola wyłącznie na podstawie jego nazwy.');
}
if (substr_count($adminScript, "document.createElement('dialog')") !== 1) {
    throw new RuntimeException('Edytor powinien używać jednego wspólnego modala.');
}

$contentEditors = [
    $root . '/app/core/Admin/AdminController.php' => 'name="block_text[]" data-content-editor',
    $root . '/app/modules/catalog/admin.php' => 'name="description" data-content-editor',
    $root . '/app/modules/knowledge/admin.php' => 'name="content" data-content-editor',
    $root . '/app/modules/business/admin.php' => "'content_editor' => true",
    $root . '/app/modules/trust/admin.php' => 'name="description" data-content-editor',
    $root . '/app/modules/privacy/admin.php' => 'name="content" data-content-editor',
];
foreach ($contentEditors as $path => $needle) {
    $source = file_get_contents($path);
    if (!is_string($source) || !str_contains($source, $needle)) {
        throw new RuntimeException('Brak edytora linków w polu treściowym: ' . basename($path));
    }
    if (preg_match('/name="(?:meta_description|schema_json|schema_custom_jsonld|csv|code)"[^>]*data-content-editor/', $source) === 1) {
        throw new RuntimeException('Edytor linków został błędnie dodany do pola SEO lub technicznego: ' . basename($path));
    }
}

echo "CORE_TEXT_EDITOR_TEST_OK\n";
