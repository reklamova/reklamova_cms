<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/core/Content/TextFormatter.php';

use Reklamova\Cms\Content\TextFormatter;

$sameTab = TextFormatter::withLinks('Zobacz [katalog](https://example.com/catalog.pdf).');
$newTab = TextFormatter::withLinks('[film](https://youtube.com/watch?v=123){new-tab}');
$local = TextFormatter::withLinks('[PDF](/uploads/catalog.pdf)');
$unsafe = TextFormatter::withLinks('[klik](javascript:alert(1))');
$escaped = TextFormatter::withLinks('<script>alert(1)</script>');

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
if (str_contains($escaped, '<script>') || !str_contains($escaped, '&lt;script&gt;')) {
    throw new RuntimeException('Treść nie jest prawidłowo escapowana.');
}

$adminScript = file_get_contents(dirname(__DIR__) . '/public/assets/core/admin-shell.js');
if (!is_string($adminScript)) {
    throw new RuntimeException('Nie można odczytać skryptu edytora.');
}
foreach (['contentFieldNames', "textarea[name]", 'textLinkEditor', 'showModal()', 'setRangeText', "'{new-tab}'"] as $check) {
    if (!str_contains($adminScript, $check)) {
        throw new RuntimeException('Brak globalnej funkcji edytora: ' . $check);
    }
}

echo "CORE_TEXT_EDITOR_TEST_OK\n";
