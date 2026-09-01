<?php

declare(strict_types=1);

use Reklamova\Cms\Modules\Catalog\CatalogRepository;
use Reklamova\Cms\Pages\PageRenderer;
use Reklamova\Cms\Pages\PageRepository;
use Reklamova\Cms\Support\Config;

require_once dirname(__DIR__, 2) . '/catalog/src/CatalogRepository.php';

return static function (array $container, PDO $pdo, array $module): array {
    $repo = new CatalogRepository($pdo);
    $config = new Config($container);
    $siteName = (string) $config->get('app', 'name', 'Reklamova CMS');
    $siteUrl = rtrim((string) $config->get('app', 'url', ''), '/');
    $base = 'nasza-oferta';
    $h = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    $renderTextWithLinks = static function (string $text) use ($h): string {
        $pattern = '/\[([^\]\r\n]+)\]\(([^)\s]+)\)/u';
        if (preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE) !== 1 && empty($matches[0])) {
            return nl2br($h($text));
        }
        $html = '';
        $offset = 0;
        foreach ($matches[0] as $index => $match) {
            $full = (string) $match[0];
            $position = (int) $match[1];
            $html .= $h(substr($text, $offset, $position - $offset));
            $label = (string) ($matches[1][$index][0] ?? '');
            $url = (string) ($matches[2][$index][0] ?? '');
            $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
            $isLocal = str_starts_with($url, '/') && !str_starts_with($url, '//');
            $isWeb = in_array($scheme, ['http', 'https'], true) && filter_var($url, FILTER_VALIDATE_URL) !== false;
            if ($isLocal || $isWeb) {
                $external = $isWeb ? ' target="_blank" rel="noopener noreferrer"' : '';
                $html .= '<a href="' . $h($url) . '"' . $external . '>' . $h($label) . '</a>';
            } else {
                $html .= $h($full);
            }
            $offset = $position + strlen($full);
        }
        $html .= $h(substr($text, $offset));
        return nl2br($html);
    };
    $bestImageUrl = static function (string $url) use ($container): string {
        $path = parse_url($url, PHP_URL_PATH);
        if (!is_string($path) || !str_starts_with($path, '/uploads/')) {
            return $url;
        }
        $candidates = [];
        $candidates[] = preg_replace('/-\d+x\d+(?=\.[^.]+$)/', '', $path) ?: $path;
        if (str_ends_with($path, '-thumb.webp')) {
            $candidates[] = substr($path, 0, -strlen('-thumb.webp')) . '-pdf-1024x724.jpg';
        }
        foreach (array_unique($candidates) as $candidate) {
            $file = rtrim((string) ($container['public_path'] ?? ''), '/\\') . str_replace('/', DIRECTORY_SEPARATOR, rawurldecode($candidate));
            if ($candidate !== $path && is_file($file)) {
                return $candidate;
            }
        }
        return $url;
    };
    $catalogSetting = static function (string $key, string $default = '') use ($pdo): string {
        try {
            $statement = $pdo->prepare('SELECT setting_value FROM cms_settings WHERE setting_key = ? LIMIT 1');
            $statement->execute([$key]);
            $value = $statement->fetchColumn();
            return is_string($value) && $value !== '' ? $value : $default;
        } catch (Throwable) {
            return $default;
        }
    };
    $productGridColumns = static function () use ($catalogSetting): int {
        $columns = (int) $catalogSetting('catalog.product_grid_columns', '4');
        return in_array($columns, [3, 4], true) ? $columns : 4;
    };
    $isLegacyVisualDescription = static function (string $html): bool {
        return preg_match('~<(img|figure)\b~i', $html) === 1
            && preg_match('~(?:href=["\']/?nasza-oferta/|pt-offer-source|uploads/powertech/)~i', $html) === 1;
    };
    $isLegacyListingDescription = static function (string $html, array $children, array $products): bool {
        $normalise = static function (string $value): string {
            $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $value = mb_strtolower(trim($value), 'UTF-8');
            $value = preg_replace('/\s+/u', ' ', $value) ?: '';

            return trim($value);
        };

        $names = [];
        foreach (array_merge($children, $products) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $name = $normalise((string) ($item['name'] ?? ''));
            if ($name !== '') {
                $names[$name] = true;
            }
        }
        if ($names === []) {
            return false;
        }

        $plain = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $lines = array_values(array_filter(array_map($normalise, preg_split('/\R+/u', $plain) ?: []), static fn (string $line): bool => $line !== ''));
        if (count($lines) < 2) {
            return false;
        }

        foreach ($lines as $line) {
            if (!isset($names[$line])) {
                return false;
            }
        }

        return true;
    };
    $powertechMenu = [];
    try {
        $rows = $pdo->query('SELECT title, slug, menu_label FROM cms_pages WHERE show_in_menu = 1 ORDER BY sort_order ASC, title ASC')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $slug = trim((string) ($row['slug'] ?? ''), '/');
            $url = $slug === '' || $slug === 'home' ? '/' : '/' . $slug . '/';
            $powertechMenu[$url] = (string) (($row['menu_label'] ?? '') ?: ($row['title'] ?? $url));
        }
    } catch (Throwable) {
        $powertechMenu = [];
    }
    if (!isset($powertechMenu['/nasza-oferta/'])) {
        $withCatalog = [];
        foreach ($powertechMenu as $href => $label) {
            $withCatalog[$href] = $label;
            if ($href === '/o-nas/') {
                $withCatalog['/nasza-oferta/'] = 'Nasza oferta';
            }
        }
        $powertechMenu = $withCatalog ?: ['/nasza-oferta/' => 'Nasza oferta'];
    }

    $powertechNavigation = static function () use ($pdo, $powertechMenu, $h): string {
        $dropdown = '';
        try {
            $rows = $pdo->query('SELECT id, parent_id, name, full_path FROM catalog_categories WHERE status = "published" ORDER BY parent_id IS NOT NULL, parent_id ASC, sort_order ASC, name ASC')->fetchAll(PDO::FETCH_ASSOC);
            $children = [];
            foreach ($rows as $row) {
                $children[(int) ($row['parent_id'] ?? 0)][] = $row;
            }
            $render = static function (int $parentId) use (&$render, $children, $h): string {
                if (empty($children[$parentId])) {
                    return '';
                }
                $html = '<ul class="pt-submenu">';
                foreach ($children[$parentId] as $child) {
                    $childDropdown = $render((int) $child['id']);
                    $html .= '<li' . ($childDropdown !== '' ? ' class="has-children"' : '') . '><a href="/nasza-oferta/' . $h(trim((string) $child['full_path'], '/')) . '/">' . $h($child['name']) . '</a>' . $childDropdown . '</li>';
                }
                return $html . '</ul>';
            };
            $dropdown = $render(0);
        } catch (Throwable) {
            $dropdown = '';
        }

        $current = $_SERVER['REQUEST_URI'] ?? '/';
        $html = '';
        foreach ($powertechMenu as $href => $label) {
            $active = str_starts_with($current, $href === '/' ? '/home-never-match' : rtrim($href, '/')) ? ' is-active' : '';
            $hasDropdown = $href === '/nasza-oferta/' && $dropdown !== '';
            $html .= '<div class="pt-menu__item' . ($hasDropdown ? ' pt-menu__item--has-children' : '') . '"><a class="' . trim($active) . '" href="' . $h($href) . '">' . $h($label) . '</a>' . ($hasDropdown ? $dropdown : '') . '</div>';
        }
        return $html;
    };

    $productSearch = static function () use ($h): string {
        return '<div class="pt-product-search" data-product-search>'
            . '<button class="pt-search" type="button" aria-label="Szukaj produktów" aria-expanded="false" data-product-search-toggle><span></span></button>'
            . '<div class="pt-product-search__panel" data-product-search-panel>'
            . '<form class="pt-product-search__form" action="/nasza-oferta/" method="get" data-product-search-form>'
            . '<label for="pt-product-search-input">Szukaj produktu</label>'
            . '<div><input id="pt-product-search-input" name="q" type="search" autocomplete="off" placeholder="Wpisz nazwę, model lub SKU" data-product-search-input><button type="submit">Szukaj</button></div>'
            . '</form>'
            . '<div class="pt-product-search__status" data-product-search-status>Wpisz minimum 2 znaki.</div>'
            . '<div class="pt-product-search__results" data-product-search-results></div>'
            . '</div></div>';
    };

    $searchProducts = static function () use ($pdo, $base): void {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, max-age=0');

        $query = trim((string) ($_GET['q'] ?? ''));
        $query = mb_substr($query, 0, 80, 'UTF-8');
        if (mb_strlen($query, 'UTF-8') < 2) {
            echo json_encode(['query' => $query, 'count' => 0, 'items' => []], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return;
        }

        $like = '%' . addcslashes($query, "\\_%") . '%';
        $prefix = addcslashes($query, "\\_%") . '%';
        $statement = $pdo->prepare(
            'SELECT p.name, p.full_path, p.sku, p.brand, p.summary, p.featured_image, c.name AS category_name
             FROM catalog_products p
             LEFT JOIN catalog_categories c ON c.id = p.category_id
             WHERE p.status = "published"
               AND (
                   p.name LIKE ? ESCAPE "\\\\"
                   OR p.sku LIKE ? ESCAPE "\\\\"
                   OR p.brand LIKE ? ESCAPE "\\\\"
                   OR p.summary LIKE ? ESCAPE "\\\\"
                   OR c.name LIKE ? ESCAPE "\\\\"
               )
             ORDER BY CASE WHEN p.name LIKE ? ESCAPE "\\\\" THEN 0 ELSE 1 END, p.sort_order ASC, p.name ASC
             LIMIT 8'
        );
        $statement->execute([$like, $like, $like, $like, $like, $prefix]);

        $items = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $items[] = [
                'title' => (string) ($row['name'] ?? ''),
                'url' => '/' . $base . '/' . trim((string) ($row['full_path'] ?? ''), '/') . '/',
                'sku' => (string) ($row['sku'] ?? ''),
                'brand' => (string) ($row['brand'] ?? ''),
                'summary' => (string) ($row['summary'] ?? ''),
                'image' => (string) ($row['featured_image'] ?? ''),
                'category' => (string) ($row['category_name'] ?? ''),
            ];
        }

        echo json_encode(['query' => $query, 'count' => count($items), 'items' => $items], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    };

    $footerCredit = '<div class="pt-footer__credit"><div class="pt-wrap pt-footer__credit-inner"><span>Strona zrealizowana przez</span><a href="https://reklamova.pl/" target="_blank" rel="noopener noreferrer" aria-label="Reklamova.pl"><img src="/assets/img/reklamova-logo.svg" alt=""><span>.pl</span></a></div></div>';

    $layout = static function (string $title, string $body, string $description = '', string $image = '', array $schema = [], string $heading = '') use ($siteName, $siteUrl, $h, $powertechNavigation, $productSearch, $footerCredit): void {
        header('Content-Type: text/html; charset=utf-8');
        $description = trim(preg_replace('/\s+/u', ' ', strip_tags($description)) ?: '');
        if (mb_strlen($description, 'UTF-8') > 160) {
            $description = rtrim(mb_substr($description, 0, 157, 'UTF-8'), " \t\n\r\0\x0B,.;:-") . '...';
        }
        $pageTitle = mb_stripos($title, $siteName, 0, 'UTF-8') !== false
            ? $title
            : $title . ' - ' . $siteName;
        $heading = $heading !== '' ? $heading : $title;
        $requestPath = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
        $canonical = rtrim($siteUrl, '/') . '/' . ltrim($requestPath, '/');
        $schemaHtml = '';
        foreach ($schema as $item) {
            $schemaHtml .= '<script type="application/ld+json">' . json_encode($item, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>';
        }
        $nav = $powertechNavigation();
        echo '<!doctype html><html lang="pl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . $h($pageTitle) . '</title>'
            . ($description !== '' ? '<meta name="description" content="' . $h($description) . '">' : '')
            . '<meta name="robots" content="index,follow">'
            . '<link rel="canonical" href="' . $h($canonical) . '">'
            . '<meta property="og:type" content="website">'
            . '<meta property="og:title" content="' . $h($pageTitle) . '">'
            . '<meta property="og:url" content="' . $h($canonical) . '">'
            . ($description !== '' ? '<meta property="og:description" content="' . $h($description) . '">' : '')
            . ($image !== '' ? '<meta property="og:image" content="' . $h($image) . '">' : '')
            . '<link rel="icon" href="/favicon.svg" type="image/svg+xml">'
            . '<link rel="stylesheet" href="/assets/core/page.css">'
            . '<link rel="stylesheet" href="/assets/css/powertech.css?v=20260901-product-media1">'
            . '<script src="/assets/js/powertech.js?v=20260901-product-media1" defer></script>'
            . $schemaHtml . '</head><body class="powertech-catalog">'
            . '<div class="pt-topbar"><div class="pt-wrap"><div class="pt-topbar__block"><span>PowerTech s.c.</span><span>ul. Beskidzka 23, 32-615 Grojec</span></div><div class="pt-topbar__block"><a href="tel:+48334871447">+48 33 487 14 47</a><a href="mailto:biuro@powertechsc.pl">biuro@powertechsc.pl</a></div></div></div>'
            . '<header class="pt-header" data-mobile-header><div class="pt-wrap"><a class="pt-logo" href="/"><img src="/uploads/powertech/2025/11/powertechsc-logotype.webp" alt="Power Tech S.C. logotyp"></a><nav class="pt-menu" id="pt-primary-menu" aria-label="Menu główne" data-mobile-menu>' . $nav . '</nav>' . $productSearch() . '<button class="pt-menu-toggle" type="button" aria-label="Otwórz menu" aria-controls="pt-primary-menu" aria-expanded="false" data-mobile-menu-toggle><span></span><span></span><span></span></button></div></header>'
            . '<section class="pt-page-title"><div class="pt-wrap"><h1>' . $h($heading) . '</h1></div></section>'
            . '<main class="catalog-shell">' . $body . '</main>'
            . '<footer class="pt-footer"><div class="pt-wrap"><div><img src="/uploads/powertech/2025/11/footer-logotype.webp" alt="PowerTech"></div><div><h2>PowerTech s.c.</h2><p>ul. Beskidzka 23<br>32-615 Grojec<br>woj. małopolskie</p></div><div><h2>Kontakt</h2><p><a href="tel:+48334871447">+48 33 487 14 47</a><br><a href="mailto:biuro@powertechsc.pl">biuro@powertechsc.pl</a><br>NIP: 551 253 62 49</p></div><div><h2>Informacje</h2><p><a href="/nasza-oferta/">Nasza oferta</a><br><a href="/pliki-do-pobrania/">Pliki do pobrania</a><br><a href="/ochrona-danych-osobowych/">Ochrona danych osobowych</a><br><a href="/polityka-plikow-cookies/">Polityka plików cookies</a></p></div></div></footer>'
            . $footerCredit . '</body></html>';
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

    $renderCategory = static function (?array $category = null) use ($repo, $layout, $breadcrumbs, $categoryAncestors, $breadcrumbSchema, $siteUrl, $base, $h, $pdo, $productGridColumns, $isLegacyVisualDescription, $isLegacyListingDescription, $bestImageUrl): void {
        $children = $repo->childCategories($category ? (int) $category['id'] : null, true);
        $products = $category ? $repo->productsForCategory((int) $category['id'], true) : [];
        $title = $category ? (string) $category['name'] : 'Nasza oferta';
        $description = $category ? (string) (($category['meta_description'] ?? '') ?: ($category['summary'] ?? '')) : 'Oferta produktów i rozwiązań.';
        $image = $category ? (string) (($category['og_image'] ?? '') ?: ($category['featured_image'] ?? '')) : '';
        $segments = $category ? $categoryAncestors($category) : [];
        $itemsCount = count($children) + count($products);
        $intro = $category
            ? (string) ($category['summary'] ?? $description)
            : 'Wybierz dział oferty i przejdź do uporządkowanych kategorii oraz produktów PowerTech.';
        $hero = ($category ? $breadcrumbs($segments, $base) : $breadcrumbs([], $base))
            . '<section class="catalog-hero' . (!$category ? ' catalog-hero--root' : '') . '"><div><span class="catalog-kicker">PowerTech Catalog</span><h2>' . $h($title) . '</h2><p>' . nl2br($h($intro)) . '</p><div class="catalog-metrics"><span>' . $itemsCount . ' pozycji</span><span>Oferta techniczna</span><span>Zapytania B2B</span></div></div>'
            . ($image !== '' ? '<figure><img src="' . $h($image) . '" alt=""></figure>' : '') . '</section>';
        $grid = '<section class="catalog-section"><header><span class="catalog-kicker">' . ($category ? 'Podkategorie i produkty' : 'Główne działy') . '</span><h2>' . ($category ? 'Zawartość kategorii' : 'Główne działy oferty') . '</h2></header><div class="catalog-grid" style="--catalog-product-columns: ' . $productGridColumns() . ';">';
        foreach ($children as $child) {
            $url = '/' . $base . '/' . trim((string) $child['full_path'], '/');
            $summary = trim((string) ($child['summary'] ?? ''));
            $summary = mb_strlen($summary, 'UTF-8') > 150 ? mb_substr($summary, 0, 147, 'UTF-8') . '...' : $summary;
            $childImage = $bestImageUrl((string) ($child['featured_image'] ?? ''));
            $grid .= '<a class="catalog-card" href="' . $h($url) . '">' . ($childImage !== '' ? '<figure><img src="' . $h($childImage) . '" alt="" loading="lazy" decoding="async"></figure>' : '') . '<h2>' . $h($child['name']) . '</h2><p>' . $h($summary) . '</p><span class="catalog-card__more">Zobacz dział</span></a>';
        }
        foreach ($products as $product) {
            $url = '/' . $base . '/' . trim((string) $product['full_path'], '/');
            $summary = trim((string) ($product['summary'] ?? ''));
            $summary = mb_strlen($summary, 'UTF-8') > 150 ? mb_substr($summary, 0, 147, 'UTF-8') . '...' : $summary;
            $productImage = $bestImageUrl((string) ($product['featured_image'] ?? ''));
            $grid .= '<a class="catalog-card catalog-card--product" href="' . $h($url) . '">' . ($productImage !== '' ? '<figure><img src="' . $h($productImage) . '" alt="" loading="lazy" decoding="async"></figure>' : '') . '<h2>' . $h($product['name']) . '</h2><p>' . $h($summary) . '</p><span class="catalog-card__more">Zobacz produkt</span></a>';
        }
        $grid .= '</div></section>';
        $schema = [];
        if ($category) {
            $schema[] = $breadcrumbSchema($segments, $title, $siteUrl . '/' . $base . '/' . $category['full_path']);
        }
        $categoryDescription = '';
        if ($category && trim((string) ($category['description'] ?? '')) !== '') {
            $rawDescription = (string) ($category['description'] ?? '');
            $settings = json_decode((string) ($category['settings_json'] ?? '{}'), true) ?: [];
            if ($isLegacyListingDescription($rawDescription, $children, $products)) {
                $categoryDescription = '';
            } elseif (
                ($settings['description_format'] ?? '') === 'html'
                && !empty($settings['show_source_description'])
                && !$isLegacyVisualDescription($rawDescription)
            ) {
                $categoryDescription = '<article class="cms-page__content pt-wp-content catalog-description">' . $rawDescription . '</article>';
            } elseif (($settings['description_format'] ?? '') !== 'html') {
                $categoryDescription = '<article class="cms-page__content catalog-description">' . nl2br($h($rawDescription)) . '</article>';
            } else {
                $categoryDescription = '';
            }
        }
        $metaTitle = $category ? (string) (($category['meta_title'] ?? '') ?: $title) : $title;
        if ($category && mb_stripos($metaTitle, 'kategoria', 0, 'UTF-8') === false) {
            $metaTitle .= ' – kategoria produktów';
        }
        $layout($metaTitle, $hero . $grid . $categoryDescription, $description, $image, $schema, $title);
    };

    $renderProduct = static function (array $product) use ($layout, $breadcrumbs, $categoryAncestors, $breadcrumbSchema, $repo, $siteUrl, $base, $h, $bestImageUrl, $renderTextWithLinks): void {
        $category = $product['category_path'] ? $repo->findCategoryByPath((string) $product['category_path']) : null;
        $segments = $category ? $categoryAncestors($category) : [];
        $gallery = json_decode((string) ($product['gallery_json'] ?? '[]'), true) ?: [];
        $mainImage = trim((string) ($product['featured_image'] ?? '')) ?: trim((string) ($gallery[0] ?? ''));
        $allImages = array_values(array_unique(array_map($bestImageUrl, array_filter(array_merge([$mainImage], $gallery), static fn (mixed $url): bool => is_string($url) && trim($url) !== ''))));
        $mainImage = $allImages[0] ?? $mainImage;
        $metaImage = $bestImageUrl(trim((string) ($product['og_image'] ?? '')) ?: $mainImage);
        $specs = json_decode((string) ($product['specs_json'] ?? '[]'), true) ?: [];
        $documents = json_decode((string) ($product['documents_json'] ?? '[]'), true) ?: [];
        $productPath = '/' . $base . '/' . trim((string) $product['full_path'], '/');
        $productUrl = ($siteUrl !== '' ? $siteUrl : '') . $productPath;
        $productInquiry = '<section class="catalog-inquiry" id="zapytanie-ofertowe"><span class="catalog-kicker">Zapytanie ofertowe</span><h2>Zapytaj o ten produkt</h2>'
            . '<p>Wyślij krótką wiadomość, a przygotujemy odpowiednią konfigurację, dostępność i wycenę dla: <strong>' . $h($product['name']) . '</strong>.</p>'
            . '<form method="post" action="/api/forms/submit"><input type="hidden" name="form_type" value="product_inquiry">'
            . '<input type="hidden" name="product_name" value="' . $h($product['name']) . '">'
            . '<input type="hidden" name="product_sku" value="' . $h((string) ($product['sku'] ?? '')) . '">'
            . '<input type="hidden" name="product_url" value="' . $h($productUrl) . '">'
            . '<label>Imię i nazwisko<input name="name" autocomplete="name"></label>'
            . '<label>Email<input type="email" name="email" autocomplete="email" required></label>'
            . '<label>NIP<input name="nip" autocomplete="organization" required placeholder="np. 123-456-78-90"></label>'
            . '<label>Telefon<input name="phone" autocomplete="tel" placeholder="np. +48 600 000 000"></label>'
            . '<label class="catalog-inquiry__message">Wiadomość<textarea name="message" required placeholder="Napisz, czego potrzebujesz: zakres pomiarowy, normę, zastosowanie lub termin realizacji."></textarea></label>'
            . '<div class="catalog-inquiry__consents"><label><input type="checkbox" name="privacy_consent" value="1" required> Wyrażam zgodę na przetwarzanie danych z formularza w celu obsługi zapytania. Zapoznałem/am się z polityką prywatności.</label>'
            . '<label><input type="checkbox" name="marketing_consent" value="1"> Wyrażam zgodę na kontakt marketingowy dotyczący produktów, usług i ofert PowerTech s.c. Zgodę można wycofać w dowolnym momencie.</label></div>'
            . '<button class="cms-button" type="submit">Wyślij zapytanie o produkt</button></form></section>';
        $media = '<div class="catalog-product__media">';
        if (count($allImages) > 1) {
            $media .= '<div class="catalog-carousel" data-product-carousel aria-roledescription="karuzela" aria-label="Galeria produktu"><div class="catalog-carousel__track" data-carousel-track>';
            foreach ($allImages as $index => $url) {
                $media .= '<figure class="catalog-carousel__slide" data-carousel-slide aria-label="Zdjęcie ' . ($index + 1) . ' z ' . count($allImages) . '"><img src="' . $h($url) . '" alt="' . $h($product['name']) . ' – zdjęcie ' . ($index + 1) . '" data-lightbox-image tabindex="0" role="button" aria-label="Powiększ zdjęcie ' . ($index + 1) . '"' . ($index > 0 ? ' loading="lazy"' : '') . ' decoding="async"></figure>';
            }
            $media .= '</div><div class="catalog-carousel__controls"><button type="button" data-carousel-prev aria-label="Poprzednie zdjęcie">←</button><span data-carousel-status>1 / ' . count($allImages) . '</span><button type="button" data-carousel-next aria-label="Następne zdjęcie">→</button></div></div>';
        } elseif ($mainImage !== '') {
            $media .= '<figure><img src="' . $h($mainImage) . '" alt="' . $h($product['name']) . '" data-lightbox-image tabindex="0" role="button" aria-label="Powiększ zdjęcie" decoding="async"></figure>';
        }
        $media .= '</div>';
        $body = $breadcrumbs($segments, $base)
            . '<section class="catalog-product">' . $media . '<div class="catalog-product__body">'
            . '<div class="catalog-product__meta">' . ((string) ($product['brand'] ?? '') !== '' ? '<span>' . $h($product['brand']) . '</span>' : '') . ((string) ($product['sku'] ?? '') !== '' ? '<span>' . $h($product['sku']) . '</span>' : '') . '</div>'
            . '<h2>' . $h($product['name']) . '</h2><p>' . nl2br($h((string) ($product['summary'] ?? ''))) . '</p><div class="catalog-product__description">' . $renderTextWithLinks((string) ($product['description'] ?? '')) . '</div>'
            . '<div class="catalog-actions"><a href="#zapytanie-ofertowe">Zapytaj o produkt</a></div></div></section>' . $productInquiry;
        if ($specs) {
            $body .= '<table class="catalog-specs">';
            foreach ($specs as $spec) {
                if (is_array($spec)) {
                    $body .= '<tr><td>' . $h($spec['name'] ?? '') . '</td><td>' . $h($spec['value'] ?? '') . '</td></tr>';
                }
            }
            $body .= '</table>';
        }
        if ($documents) {
            $body .= '<section class="cms-block cms-cards catalog-documents"><header><h2>Dokumenty do pobrania</h2></header><div>';
            foreach ($documents as $url) {
                $path = parse_url((string) $url, PHP_URL_PATH);
                $filename = rawurldecode(basename(is_string($path) ? $path : (string) $url)) ?: 'Dokument PDF';
                $label = preg_replace('/-[a-f0-9]{12}(?=\.pdf$)/i', '', $filename) ?: $filename;
                $label = preg_replace('/\.pdf$/i', '', $label) ?: $label;
                $body .= '<article><span class="catalog-document__badge">PDF</span><h3>' . $h(str_replace('-', ' ', $label)) . '</h3><a href="' . $h($url) . '" target="_blank" rel="noopener">Otwórz lub pobierz PDF</a></article>';
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
                'image' => $mainImage,
            ]),
        ];
        $metaTitle = (string) (($product['meta_title'] ?? '') ?: $product['name']);
        $sku = trim((string) ($product['sku'] ?? ''));
        if ($sku !== '' && mb_stripos($metaTitle, $sku, 0, 'UTF-8') === false) {
            $metaTitle .= ' ' . $sku;
        }
        $model = trim((string) ($product['model'] ?? ''));
        if ($model !== '' && mb_stripos($metaTitle, $model, 0, 'UTF-8') === false) {
            $metaTitle .= ' ' . $model;
        }
        if ($sku === '' && $model === '') {
            $metaTitle .= ' – produkt ' . (int) $product['id'];
        }
        $layout($metaTitle, $body, (string) (($product['meta_description'] ?? '') ?: ($product['summary'] ?? '')), $metaImage, $schema, (string) $product['name']);
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

        $aliases = [
            'plastiform-masy-plastyczne' => 'plastiform',
            'produkcja-przyrzadow-specjalnych' => 'produkcja-przyrzadow-pomiarowych',
            'serwis-przyrzadow' => 'naprawa-oraz-serwis-przyrzadow-pomiarowych',
            'silomierze-i-przyrzady-pwytrzymalosciowe' => 'maszyny-do-badan-wytrzymalosciowych',
            'przyrzady-pomiarowe/mikroskopy-i-lupy' => 'pomiary-optyczne',
            'przyrzady-pomiarowe/twardosciomierze' => 'twardosciomierze',
        ];
        if (isset($aliases[$path])) {
            header('Location: /' . $base . '/' . $aliases[$path] . '/', true, 301);
            return true;
        }

        return false;
    };

    $pageRepository = new PageRepository($pdo);
    $pageRenderer = new PageRenderer();
    $pageFallback = static function (string $slug) use ($pageRepository, $pageRenderer, $siteName, $siteUrl, $powertechNavigation, $productSearch, $footerCredit, $h): bool {
        $page = $pageRepository->findPublishedBySlug($slug);
        if (!$page) {
            return false;
        }

        $meta = $pageRenderer->meta($page, $siteName, $siteUrl);
        $settings = json_decode((string) ($page['settings_json'] ?? '{}'), true) ?: [];
        $hideTitle = !empty($settings['hide_title']);
        $title = (string) ($meta['title'] ?? $siteName);
        $description = (string) ($meta['description'] ?? '');
        $canonical = (string) ($meta['canonical'] ?? '');
        $robots = (string) ($meta['robots'] ?? 'index,follow');
        $image = (string) ($meta['image'] ?? '');
        $schema = (string) ($meta['schema'] ?? '');
        $body = $pageRenderer->render($page);
        $pageHeading = (string) (($page['title'] ?? '') ?: $title);
        $bodyHasHeading = preg_match('/<h1\b/i', $body) === 1;
        $headingHtml = $bodyHasHeading
            ? ''
            : (!$hideTitle
                ? '<section class="pt-page-title"><div class="pt-wrap"><h1>' . $h($pageHeading) . '</h1></div></section>'
                : '<h1 class="pt-sr-only">' . $h($pageHeading) . '</h1>');

        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="pl"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . $h($title) . '</title>'
            . ($description !== '' ? '<meta name="description" content="' . $h($description) . '">' : '')
            . '<meta name="robots" content="' . $h($robots) . '">'
            . ($canonical !== '' ? '<link rel="canonical" href="' . $h($canonical) . '">' : '')
            . '<meta property="og:title" content="' . $h($title) . '">'
            . ($description !== '' ? '<meta property="og:description" content="' . $h($description) . '">' : '')
            . ($image !== '' ? '<meta property="og:image" content="' . $h($image) . '">' : '')
            . '<link rel="icon" href="/favicon.svg" type="image/svg+xml">'
            . '<link rel="stylesheet" href="/assets/core/page.css">'
            . '<link rel="stylesheet" href="/assets/css/powertech.css?v=20260901-product-media1">'
            . '<script src="/assets/js/powertech.js?v=20260901-product-media1" defer></script>'
            . $schema
            . '</head><body class="powertech-catalog">'
            . '<div class="pt-topbar"><div class="pt-wrap"><div class="pt-topbar__block"><span>PowerTech s.c.</span><span>ul. Beskidzka 23, 32-615 Grojec</span></div><div class="pt-topbar__block"><a href="tel:+48334871447">+48 33 487 14 47</a><a href="mailto:biuro@powertechsc.pl">biuro@powertechsc.pl</a></div></div></div>'
            . '<header class="pt-header" data-mobile-header><div class="pt-wrap"><a class="pt-logo" href="/"><img src="/uploads/powertech/2025/11/powertechsc-logotype.webp" alt="Power Tech S.C. logotyp"></a><nav class="pt-menu" id="pt-primary-menu" aria-label="Menu główne" data-mobile-menu>' . $powertechNavigation() . '</nav>' . $productSearch() . '<button class="pt-menu-toggle" type="button" aria-label="Otwórz menu" aria-controls="pt-primary-menu" aria-expanded="false" data-mobile-menu-toggle><span></span><span></span><span></span></button></div></header>'
            . $headingHtml
            . $body
            . '<footer class="pt-footer"><div class="pt-wrap"><div><img src="/uploads/powertech/2025/11/footer-logotype.webp" alt="PowerTech"></div><div><h2>PowerTech s.c.</h2><p>ul. Beskidzka 23<br>32-615 Grojec<br>woj. małopolskie</p></div><div><h2>Kontakt</h2><p><a href="tel:+48334871447">+48 33 487 14 47</a><br><a href="mailto:biuro@powertechsc.pl">biuro@powertechsc.pl</a><br>NIP: 551 253 62 49</p></div><div><h2>Informacje</h2><p><a href="/nasza-oferta/">Nasza oferta</a><br><a href="/pliki-do-pobrania/">Pliki do pobrania</a><br><a href="/ochrona-danych-osobowych/">Ochrona danych osobowych</a><br><a href="/polityka-plikow-cookies/">Polityka plików cookies</a></p></div></div></footer>'
            . $footerCredit . '</body></html>';

        return true;
    };

    $sitemap = static function () use ($pdo, $repo, $siteUrl, $base): void {
        header('Content-Type: application/xml; charset=utf-8');
        header('Cache-Control: public, max-age=3600');
        $origin = rtrim($siteUrl, '/');
        $urls = [$origin . '/'];
        $pages = $pdo->query('SELECT slug FROM cms_pages WHERE status = "published" ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($pages as $slug) {
            $slug = trim((string) $slug, '/');
            if ($slug !== '' && $slug !== 'home') {
                $urls[] = $origin . '/' . $slug . '/';
            }
        }
        $urls[] = $origin . '/' . $base . '/';
        foreach ($repo->categories(true) as $category) {
            $path = trim((string) ($category['full_path'] ?? ''), '/');
            if ($path !== '') {
                $urls[] = $origin . '/' . $base . '/' . $path . '/';
            }
        }
        foreach ($repo->products(true) as $product) {
            $path = trim((string) ($product['full_path'] ?? ''), '/');
            if ($path !== '') {
                $urls[] = $origin . '/' . $base . '/' . $path . '/';
            }
        }
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
        foreach (array_values(array_unique($urls)) as $url) {
            $xml .= '<url><loc>' . htmlspecialchars($url, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</loc></url>';
        }
        echo $xml . '</urlset>';
    };

    return [
        'routes' => ['/api/catalog/search' => $searchProducts, '/sitemap.xml' => $sitemap],
        'fallbacks' => [$fallback, $pageFallback],
    ];
};
