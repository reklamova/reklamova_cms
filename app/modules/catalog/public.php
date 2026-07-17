<?php

declare(strict_types=1);

use Reklamova\Cms\Modules\Catalog\CatalogRepository;
use Reklamova\Cms\Support\Config;

require_once __DIR__ . '/src/CatalogRepository.php';

return static function (array $container, PDO $pdo, array $module): array {
    $repo = new CatalogRepository($pdo);
    $config = new Config($container);
    $siteName = (string) $config->get('app', 'name', 'Reklamova CMS');
    $siteUrl = rtrim((string) $config->get('app', 'url', ''), '/');
    $base = 'nasza-oferta';
    $h = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');

    $layout = static function (string $title, string $body, string $description = '', string $image = '', array $schema = []) use ($siteName, $siteUrl, $h): void {
        header('Content-Type: text/html; charset=utf-8');
        $schemaHtml = '';
        foreach ($schema as $item) {
            $schemaHtml .= '<script type="application/ld+json">' . json_encode($item, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>';
        }
        echo '<!doctype html><html lang="pl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . $h($title) . ' - ' . $h($siteName) . '</title>'
            . ($description !== '' ? '<meta name="description" content="' . $h($description) . '">' : '')
            . ($image !== '' ? '<meta property="og:image" content="' . $h($image) . '">' : '')
            . '<link rel="stylesheet" href="/assets/core/page.css">'
            . '<style>.catalog-shell{width:min(1180px,calc(100% - 40px));margin:0 auto;padding:36px 0 78px}.catalog-crumbs{display:flex;gap:8px;flex-wrap:wrap;margin:0 0 28px;color:#667085;font-size:13px}.catalog-crumbs a{color:inherit;text-decoration:none}.catalog-hero{display:grid;grid-template-columns:minmax(0,1fr) minmax(280px,.48fr);gap:34px;align-items:center;margin-bottom:34px}.catalog-hero h1{margin:0 0 14px;font-size:clamp(38px,5vw,72px);line-height:1}.catalog-hero p{color:#667085;line-height:1.7}.catalog-hero img,.catalog-card img,.catalog-product__media img{width:100%;height:100%;object-fit:cover;border-radius:10px}.catalog-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:18px}.catalog-card{display:grid;gap:12px;padding:18px;border:1px solid #e5eaf3;border-radius:10px;background:#fff;box-shadow:0 14px 38px rgba(31,41,55,.06);text-decoration:none;color:#172033}.catalog-card figure{aspect-ratio:4/3;margin:0;background:#f7f9fc;border-radius:10px;overflow:hidden}.catalog-card h2{margin:0;font-size:20px}.catalog-product{display:grid;grid-template-columns:minmax(280px,.78fr) minmax(0,1fr);gap:42px}.catalog-product__media{aspect-ratio:4/3;background:#f7f9fc;border-radius:10px;overflow:hidden;box-shadow:0 18px 54px rgba(31,41,55,.08)}.catalog-product__body h1{margin:0 0 14px;font-size:clamp(34px,4.7vw,64px);line-height:1}.catalog-product__meta{display:flex;gap:10px;flex-wrap:wrap;margin:0 0 18px}.catalog-product__meta span{padding:7px 10px;border-radius:999px;background:#f3f6fb;color:#536075;font-size:13px}.catalog-specs{width:100%;margin-top:24px;border-collapse:collapse}.catalog-specs td{padding:12px;border-bottom:1px solid #e5eaf3}.catalog-specs td:first-child{color:#667085;width:34%}.catalog-gallery{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-top:24px}.catalog-gallery img{width:100%;aspect-ratio:4/3;object-fit:cover;border-radius:8px}.catalog-actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:24px}.catalog-actions a{display:inline-flex;align-items:center;min-height:44px;padding:0 16px;border-radius:8px;background:#f7b51e;color:#171200;font-weight:700;text-decoration:none}@media(max-width:860px){.catalog-hero,.catalog-product{grid-template-columns:1fr}}</style>'
            . $schemaHtml . '</head><body><main class="catalog-shell">' . $body . '</main></body></html>';
    };

    $breadcrumbs = static function (array $segments, string $base) use ($h): string {
        $html = '<nav class="catalog-crumbs"><a href="/">Start</a><span>/</span><a href="/' . $h($base) . '">Oferta</a>';
        $path = '';
        foreach ($segments as $segment) {
            $path = trim($path . '/' . $segment['slug'], '/');
            $html .= '<span>/</span><a href="/' . $h($base . '/' . $path) . '">' . $h($segment['name']) . '</a>';
        }

        return $html . '</nav>';
    };

    $categoryAncestors = static function (array $category) use ($repo): array {
        $parts = [];
        foreach (explode('/', (string) $category['full_path']) as $slug) {
            $path = trim(($parts[count($parts) - 1]['path'] ?? '') . '/' . $slug, '/');
            $found = $repo->findCategoryByPath($path);
            if ($found) {
                $parts[] = ['name' => (string) $found['name'], 'slug' => (string) $found['slug'], 'path' => (string) $found['full_path']];
            }
        }

        return $parts;
    };

    $breadcrumbSchema = static function (array $segments, string $title, string $currentUrl) use ($siteName, $siteUrl, $base): array {
        $items = [
            ['@type' => 'ListItem', 'position' => 1, 'name' => $siteName, 'item' => $siteUrl . '/'],
            ['@type' => 'ListItem', 'position' => 2, 'name' => 'Oferta', 'item' => $siteUrl . '/' . $base],
        ];
        $position = 3;
        $path = '';
        foreach ($segments as $segment) {
            $path = trim($path . '/' . $segment['slug'], '/');
            $items[] = ['@type' => 'ListItem', 'position' => $position++, 'name' => $segment['name'], 'item' => $siteUrl . '/' . $base . '/' . $path];
        }
        $items[] = ['@type' => 'ListItem', 'position' => $position, 'name' => $title, 'item' => $currentUrl];

        return ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $items];
    };

    $renderCategory = static function (?array $category = null) use ($repo, $layout, $breadcrumbs, $categoryAncestors, $breadcrumbSchema, $siteUrl, $base, $h): void {
        $children = $repo->childCategories($category ? (int) $category['id'] : null, true);
        $products = $category ? $repo->productsForCategory((int) $category['id'], true) : [];
        $title = $category ? (string) $category['name'] : 'Nasza oferta';
        $description = $category ? (string) (($category['meta_description'] ?? '') ?: ($category['summary'] ?? '')) : 'Oferta produktów i rozwiązań.';
        $image = $category ? (string) (($category['og_image'] ?? '') ?: ($category['featured_image'] ?? '')) : '';
        $segments = $category ? $categoryAncestors($category) : [];
        $hero = ($category ? $breadcrumbs($segments, $base) : $breadcrumbs([], $base))
            . '<section class="catalog-hero"><div><h1>' . $h($title) . '</h1><p>' . nl2br($h((string) ($category['summary'] ?? $description))) . '</p></div>'
            . ($image !== '' ? '<figure><img src="' . $h($image) . '" alt=""></figure>' : '') . '</section>';
        $grid = '<section class="catalog-grid">';
        foreach ($children as $child) {
            $url = '/' . $base . '/' . trim((string) $child['full_path'], '/');
            $grid .= '<a class="catalog-card" href="' . $h($url) . '">' . ((string) ($child['featured_image'] ?? '') !== '' ? '<figure><img src="' . $h($child['featured_image']) . '" alt=""></figure>' : '') . '<h2>' . $h($child['name']) . '</h2><p>' . $h($child['summary'] ?? '') . '</p></a>';
        }
        foreach ($products as $product) {
            $url = '/' . $base . '/' . trim((string) $product['full_path'], '/');
            $grid .= '<a class="catalog-card" href="' . $h($url) . '">' . ((string) ($product['featured_image'] ?? '') !== '' ? '<figure><img src="' . $h($product['featured_image']) . '" alt=""></figure>' : '') . '<h2>' . $h($product['name']) . '</h2><p>' . $h($product['summary'] ?? '') . '</p></a>';
        }
        $grid .= '</section>';
        $schema = [];
        if ($category) {
            $schema[] = $breadcrumbSchema($segments, $title, $siteUrl . '/' . $base . '/' . $category['full_path']);
        }
        $layout((string) ($category['meta_title'] ?? $title), $hero . $grid . ($category ? '<article class="cms-page__content">' . nl2br($h($category['description'] ?? '')) . '</article>' : ''), $description, $image, $schema);
    };

    $renderProduct = static function (array $product) use ($layout, $breadcrumbs, $categoryAncestors, $breadcrumbSchema, $repo, $siteUrl, $base, $h): void {
        $category = $product['category_path'] ? $repo->findCategoryByPath((string) $product['category_path']) : null;
        $segments = $category ? $categoryAncestors($category) : [];
        $image = (string) (($product['og_image'] ?? '') ?: ($product['featured_image'] ?? ''));
        $gallery = json_decode((string) ($product['gallery_json'] ?? '[]'), true) ?: [];
        $specs = json_decode((string) ($product['specs_json'] ?? '[]'), true) ?: [];
        $documents = json_decode((string) ($product['documents_json'] ?? '[]'), true) ?: [];
        $body = $breadcrumbs($segments, $base)
            . '<section class="catalog-product"><figure class="catalog-product__media">' . ($image !== '' ? '<img src="' . $h($image) . '" alt="">' : '') . '</figure><div class="catalog-product__body">'
            . '<div class="catalog-product__meta">' . ((string) ($product['brand'] ?? '') !== '' ? '<span>' . $h($product['brand']) . '</span>' : '') . ((string) ($product['sku'] ?? '') !== '' ? '<span>' . $h($product['sku']) . '</span>' : '') . '</div>'
            . '<h1>' . $h($product['name']) . '</h1><p>' . nl2br($h((string) ($product['summary'] ?? ''))) . '</p><div>' . nl2br($h((string) ($product['description'] ?? ''))) . '</div>'
            . '<div class="catalog-actions"><a href="/kontakt?produkt=' . rawurlencode((string) $product['name']) . '">Zapytaj o produkt</a></div></div></section>';
        if ($specs) {
            $body .= '<table class="catalog-specs">';
            foreach ($specs as $spec) {
                if (is_array($spec)) {
                    $body .= '<tr><td>' . $h($spec['name'] ?? '') . '</td><td>' . $h($spec['value'] ?? '') . '</td></tr>';
                }
            }
            $body .= '</table>';
        }
        if ($gallery) {
            $body .= '<section class="catalog-gallery">';
            foreach ($gallery as $url) {
                $body .= '<img src="' . $h($url) . '" alt="">';
            }
            $body .= '</section>';
        }
        if ($documents) {
            $body .= '<section class="cms-block cms-cards"><header><h2>Dokumenty</h2></header><div>';
            foreach ($documents as $url) {
                $body .= '<article><h3>Plik do pobrania</h3><a href="' . $h($url) . '">Pobierz</a></article>';
            }
            $body .= '</div></section>';
        }
        $schema = [
            $breadcrumbSchema($segments, (string) $product['name'], $siteUrl . '/' . $base . '/' . $product['full_path']),
            array_filter([
                '@context' => 'https://schema.org',
                '@type' => 'Product',
                'name' => (string) $product['name'],
                'sku' => (string) ($product['sku'] ?? ''),
                'brand' => (string) ($product['brand'] ?? ''),
                'description' => (string) (($product['meta_description'] ?? '') ?: ($product['summary'] ?? '')),
                'image' => $image,
            ]),
        ];
        $layout((string) (($product['meta_title'] ?? '') ?: $product['name']), $body, (string) (($product['meta_description'] ?? '') ?: ($product['summary'] ?? '')), $image, $schema);
    };

    $fallback = static function (string $slug) use ($repo, $renderCategory, $renderProduct, $base): bool {
        $slug = trim($slug, '/');
        if ($slug === $base) {
            $renderCategory(null);
            return true;
        }
        if (!str_starts_with($slug, $base . '/')) {
            return false;
        }

        $path = substr($slug, strlen($base) + 1);
        $product = $repo->findProductByPath($path);
        if ($product) {
            $renderProduct($product);
            return true;
        }
        $category = $repo->findCategoryByPath($path);
        if ($category) {
            $renderCategory($category);
            return true;
        }

        return false;
    };

    return ['fallbacks' => [$fallback]];
};
