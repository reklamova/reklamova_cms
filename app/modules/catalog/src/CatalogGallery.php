<?php

declare(strict_types=1);

namespace Reklamova\Cms\Modules\Catalog;

final class CatalogGallery
{
    private const LIMIT = 100;

    /** @return list<string> */
    public static function items(mixed $value): array
    {
        $rawItems = is_array($value) ? $value : (preg_split('/\R/', trim((string) $value)) ?: []);
        $items = [];
        foreach ($rawItems as $rawItem) {
            $item = trim((string) $rawItem);
            if ($item === '') {
                continue;
            }
            $scheme = strtolower((string) parse_url($item, PHP_URL_SCHEME));
            $isLocal = str_starts_with($item, '/') && !str_starts_with($item, '//');
            if (!$isLocal && !in_array($scheme, ['http', 'https'], true)) {
                throw new \InvalidArgumentException('Galeria zawiera niepoprawny adres zdjęcia.');
            }
            if (!in_array($item, $items, true)) {
                $items[] = $item;
            }
            if (count($items) >= self::LIMIT) {
                break;
            }
        }

        return $items;
    }

    public static function json(mixed $value): ?string
    {
        $items = self::items($value);
        if ($items === []) {
            return null;
        }

        return json_encode(
            $items,
            JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_INVALID_UTF8_SUBSTITUTE
                | JSON_THROW_ON_ERROR
        );
    }

    /**
     * @param array<string, mixed>|null $product
     * @return list<string>
     */
    public static function forProduct(?array $product): array
    {
        $decoded = json_decode((string) ($product['gallery_json'] ?? '[]'), true);
        $items = self::items(is_array($decoded) ? $decoded : []);
        $featured = trim((string) ($product['featured_image'] ?? ''));
        if ($featured !== '') {
            self::items([$featured]);
            $items = array_values(array_filter($items, static fn (string $item): bool => $item !== $featured));
            array_unshift($items, $featured);
        }

        return $items;
    }
}
