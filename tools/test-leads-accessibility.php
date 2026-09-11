<?php

declare(strict_types=1);

$source = file_get_contents(dirname(__DIR__) . '/app/modules/leads/admin.php');
if (!is_string($source)) {
    throw new RuntimeException('Nie można odczytać modułu Leads.');
}

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$assert(str_contains($source, 'for="\' . $statusId'), 'Pole statusu leada nie ma powiązanej etykiety.');
$assert(str_contains($source, 'id="\' . $statusId'), 'Pole statusu leada nie ma unikalnego identyfikatora.');
$assert(str_contains($source, 'for="\' . $noteId'), 'Pole notatki leada nie ma powiązanej etykiety.');
$assert(str_contains($source, 'id="\' . $noteId'), 'Pole notatki leada nie ma unikalnego identyfikatora.');
$assert(substr_count($source, 'class="sr-only"') >= 2, 'Etykiety pomocnicze powinny pozostać wizualnie ukryte.');

echo "LEADS_ACCESSIBILITY_TEST_OK\n";
