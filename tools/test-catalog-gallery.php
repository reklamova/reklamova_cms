<?php

declare(strict_types=1);

use Reklamova\Cms\Modules\Catalog\CatalogGallery;

require dirname(__DIR__) . '/app/modules/catalog/src/CatalogGallery.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$items = CatalogGallery::items([
    ' /uploads/catalog/a.webp ',
    '/uploads/catalog/b.jpg',
    '/uploads/catalog/a.webp',
    'https://cdn.example.test/image.png',
]);
$assert($items === [
    '/uploads/catalog/a.webp',
    '/uploads/catalog/b.jpg',
    'https://cdn.example.test/image.png',
], 'Kolejność lub deduplikacja galerii jest niepoprawna.');

$productItems = CatalogGallery::forProduct([
    'featured_image' => '/uploads/catalog/main.webp',
    'gallery_json' => json_encode(['/uploads/catalog/detail.webp', '/uploads/catalog/main.webp']),
]);
$assert($productItems === [
    '/uploads/catalog/main.webp',
    '/uploads/catalog/detail.webp',
], 'Zdjęcie główne nie zostało ustawione jako pierwsze.');

$assert(CatalogGallery::json([]) === null, 'Pusta galeria powinna być zapisana jako NULL.');
$assert(
    CatalogGallery::json($items) === '["/uploads/catalog/a.webp","/uploads/catalog/b.jpg","https://cdn.example.test/image.png"]',
    'Serializacja galerii jest niepoprawna.'
);

$invalidRejected = false;
try {
    CatalogGallery::items(['javascript:alert(1)']);
} catch (InvalidArgumentException) {
    $invalidRejected = true;
}
$assert($invalidRejected, 'Niebezpieczny schemat URL nie został odrzucony.');

$many = [];
for ($index = 0; $index < 110; $index++) {
    $many[] = '/uploads/catalog/' . $index . '.webp';
}
$assert(count(CatalogGallery::items($many)) === 100, 'Limit liczby zdjęć nie działa.');

echo "CATALOG_GALLERY_TEST_OK\n";
