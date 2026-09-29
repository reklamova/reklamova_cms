<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$controller = file_get_contents($root . '/app/core/Admin/AdminController.php');
$script = file_get_contents($root . '/public/assets/core/admin-gallery.js');
$style = file_get_contents($root . '/public/assets/core/admin-2026.css');
foreach ([$controller, $script, $style] as $source) {
    if (!is_string($source)) {
        throw new RuntimeException('Nie można odczytać plików globalnego selektora mediów.');
    }
}

foreach (['/admin/media/picker', '/admin/media/upload', 'new ImageUploadService', 'Csrf::verify', 'mime_type LIKE "image/%"', "'manage_media'"] as $check) {
    if (!str_contains($controller, $check)) {
        throw new RuntimeException('Brak bezpiecznego API biblioteki mediów: ' . $check);
    }
}

foreach (['imageFieldNames', 'media-picker-dialog', 'data-media-picker-dropzone', 'data-media-field-open', 'setFieldValue', 'FormData', '/admin/media/upload', '/admin/media/picker', 'credentials: \'same-origin\''] as $check) {
    if (!str_contains($script, $check)) {
        throw new RuntimeException('Brak funkcji globalnego selektora mediów: ' . $check);
    }
}

foreach (['.media-field__preview', '.media-picker-dialog', '.media-picker-grid', '.media-picker-card', '@media (max-width: 640px)'] as $check) {
    if (!str_contains($style, $check)) {
        throw new RuntimeException('Brak stylów globalnego selektora mediów: ' . $check);
    }
}

echo "CORE_MEDIA_PICKER_TEST_OK\n";
