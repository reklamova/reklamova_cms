<?php

declare(strict_types=1);

$source = file_get_contents(dirname(__DIR__) . '/app/core/Admin/AdminController.php');
if (!is_string($source)) {
    throw new RuntimeException('Nie można odczytać kontrolera panelu.');
}

if (!str_contains($source, 'aria-label="Zdjęcie \' . $slot . \' galerii w sekcji')) {
    throw new RuntimeException('Pola wyboru zdjęć galerii nie mają dostępnych nazw.');
}

echo "PAGE_EDITOR_ACCESSIBILITY_TEST_OK\n";
