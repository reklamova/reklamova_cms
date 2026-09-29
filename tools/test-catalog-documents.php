<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$serviceSource = file_get_contents($root . '/app/core/Media/DocumentUploadService.php');
$adminSource = file_get_contents($root . '/app/modules/catalog/admin.php');
$publicSource = file_get_contents($root . '/app/modules/custom/powertech/public.php');

foreach ([$serviceSource, $adminSource, $publicSource] as $source) {
    if (!is_string($source)) {
        throw new RuntimeException('Nie można odczytać plików obsługi dokumentów katalogu.');
    }
}

foreach ([
    'private const MAX_BYTES = 25 * 1024 * 1024',
    'class_exists(\\finfo::class)',
    "function_exists('mime_content_type')",
    "'application/x-pdf'",
    '$signature !== \'%PDF-\'',
    'INSERT INTO cms_media',
] as $check) {
    if (!str_contains($serviceSource, $check)) {
        throw new RuntimeException('Brak zabezpieczenia uploadu PDF: ' . $check);
    }
}

foreach ([
    'data-document-manager',
    'accept="application/pdf,.pdf"',
    '/admin/catalog/document-upload',
    'http_response_code(405)',
    'http_response_code(419)',
    'new DocumentUploadService',
] as $check) {
    if (!str_contains($adminSource, $check)) {
        throw new RuntimeException('Brak elementu obsługi PDF w panelu: ' . $check);
    }
}

foreach (['Dokumenty do pobrania', 'catalog-document__badge', 'target="_blank" rel="noopener"'] as $check) {
    if (!str_contains($publicSource, $check)) {
        throw new RuntimeException('Brak elementu dokumentów na froncie: ' . $check);
    }
}

echo "CATALOG_DOCUMENTS_TEST_OK\n";
