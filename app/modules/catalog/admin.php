<?php

declare(strict_types=1);

use Reklamova\Cms\Admin\AdminView;
use Reklamova\Cms\Auth\Csrf;
use Reklamova\Cms\Modules\Catalog\CatalogRepository;
use Reklamova\Cms\Support\Url;

require_once __DIR__ . '/src/CatalogRepository.php';

return static function (array $container, PDO $pdo, array $module): array {
    $repo = new CatalogRepository($pdo);
    $h = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');

    $statusLabel = static fn (string $status): string => match ($status) {
        'published' => 'Opublikowany',
        'archived' => 'Archiwum',
        default => 'Szkic',
    };

    $statusPill = static function (string $status) use ($h, $statusLabel): string {
        return '<span class="status-pill status-pill--' . $h($status) . '">' . $h($statusLabel($status)) . '</span>';
    };

    $tabs = static function (string $active) use ($h): string {
        $items = [
            '/admin/catalog/products' => 'Produkty',
            '/admin/catalog/categories' => 'Kategorie produktów',
            '/admin/catalog/import' => 'Import',
        ];
        $html = '<div class="privacy-tabs catalog-tabs">';
        foreach ($items as $href => $label) {
            $html .= '<a class="button ' . ($href === $active ? '' : 'secondary') . '" href="' . $h($href) . '"' . ($href === $active ? ' aria-current="page"' : '') . '>' . $h($label) . '</a>';
        }

        return $html . '</div>';
    };

    $statusOptions = static function (string $selected) use ($h): string {
        $items = ['draft' => 'Szkic', 'published' => 'Opublikowany', 'archived' => 'Archiwum'];
        $html = '';
        foreach ($items as $value => $label) {
            $html .= '<option value="' . $h($value) . '"' . ($value === $selected ? ' selected' : '') . '>' . $h($label) . '</option>';
        }

        return $html;
    };

    $mediaOptions = static function (?string $selected = null) use ($pdo, $h): string {
        $html = '<option value="">Brak</option>';
        try {
            $rows = $pdo->query('SELECT filename, path FROM cms_media WHERE mime_type LIKE "image/%" ORDER BY created_at DESC, id DESC LIMIT 250')->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            $rows = [];
        }
        foreach ($rows as $row) {
            $path = (string) ($row['path'] ?? '');
            if ($path === '') {
                continue;
            }
            $html .= '<option value="' . $h($path) . '"' . ($path === (string) $selected ? ' selected' : '') . '>' . $h(($row['filename'] ?? '') ?: $path) . '</option>';
        }
        if ($selected && !str_contains($html, 'value="' . $h($selected) . '"')) {
            $html .= '<option value="' . $h($selected) . '" selected>' . $h($selected) . '</option>';
        }

        return $html;
    };

    $queryString = static function (array $overrides = []): string {
        $query = array_merge($_GET, $overrides);
        foreach ($query as $key => $value) {
            if ($value === null || $value === '') {
                unset($query[$key]);
            }
        }

        return http_build_query($query);
    };

    $pager = static function (string $basePath, int $page, int $pages, array $query = []) use ($h): string {
        if ($pages <= 1) {
            return '';
        }
        $prev = max(1, $page - 1);
        $next = min($pages, $page + 1);
        $prevUrl = $basePath . '?' . http_build_query(array_merge($query, ['page' => $prev]));
        $nextUrl = $basePath . '?' . http_build_query(array_merge($query, ['page' => $next]));

        return '<div class="pagination"><a class="button secondary" href="' . $h($prevUrl) . '">Poprzednia</a><span>Strona ' . $page . ' z ' . $pages . '</span><a class="button secondary" href="' . $h($nextUrl) . '">Następna</a></div>';
    };

    $dashboard = static function (AdminView $view, array $user) use ($repo, $tabs): void {
        $categories = count($repo->categories(false));
        $products = count($repo->products(false));
        $publishedProducts = count($repo->products(true));
        $content = $tabs('/admin/catalog/products')
            . '<section class="panel system-hero page-studio-hero"><div><span class="eyebrow">Katalog produktów</span><h2>Oferta z kategoriami i kartami produktów</h2><p>Zarządzaj strukturą kategorii, produktami, zdjęciami, parametrami i opisami SEO. Każdy produkt ma własny adres URL.</p></div><a class="button" href="/admin/catalog/products?new=1">Dodaj produkt</a></section>'
            . '<div class="grid">'
            . '<div class="metric"><span>Kategorie</span><b>' . $categories . '</b></div>'
            . '<div class="metric"><span>Produkty</span><b>' . $products . '</b></div>'
            . '<div class="metric"><span>Opublikowane</span><b>' . $publishedProducts . '</b></div>'
            . '<div class="metric"><span>URL bazowy</span><b>/nasza-oferta</b></div>'
            . '</div>';
        $view->render('Katalog produktów', $content, $user);
    };

    $categoryForm = static function (?array $edit = null) use ($repo, $tabs, $statusOptions, $mediaOptions, $h): string {
        return $tabs('/admin/catalog/categories')
            . '<section class="panel page-editor__panel"><div class="page-editor__head"><div><span class="eyebrow">Kategorie produktów</span><h2>' . ($edit ? 'Edytuj kategorię' : 'Dodaj kategorię') . '</h2><p>Kategoria buduje adresy URL i strukturę katalogu widoczną dla klienta.</p></div><div class="actions"><a class="button secondary" href="/admin/catalog/categories">Wróć do listy</a><button form="catalog-category-form">Zapisz kategorię</button></div></div>'
            . '<form id="catalog-category-form" method="post" class="privacy-settings-grid">' . Csrf::field()
            . ($edit ? '<input type="hidden" name="id" value="' . (int) $edit['id'] . '">' : '')
            . '<label class="field field--half">Nazwa kategorii<input name="name" required value="' . $h($edit['name'] ?? '') . '"></label>'
            . '<label class="field field--half">Adres URL<input name="slug" value="' . $h($edit['slug'] ?? '') . '" placeholder="np. mikrometry-analogowe"></label>'
            . '<label class="field field--half">Kategoria nadrzędna<select name="parent_id">' . $repo->categoryTreeOptions(isset($edit['parent_id']) ? (int) $edit['parent_id'] : null, isset($edit['id']) ? (int) $edit['id'] : null) . '</select></label>'
            . '<label class="field">Status<select name="status">' . $statusOptions((string) ($edit['status'] ?? 'draft')) . '</select></label>'
            . '<label class="field">Kolejność<input type="number" name="sort_order" value="' . $h($edit['sort_order'] ?? 100) . '"></label>'
            . '<label class="field">Ikona<input name="icon" value="' . $h($edit['icon'] ?? '') . '"></label>'
            . '<label class="field field--half">Obraz z Media<select name="featured_image">' . $mediaOptions((string) ($edit['featured_image'] ?? '')) . '</select></label>'
            . '<label class="field field--wide">Krótki opis<textarea name="summary">' . $h($edit['summary'] ?? '') . '</textarea></label>'
            . '<label class="field field--wide">Opis kategorii<textarea name="description">' . $h($edit['description'] ?? '') . '</textarea></label>'
            . '<details class="field field--wide seo-accordion"><summary><span class="eyebrow">Ustawienia SEO</span><b>Opis dla Google i udostępnień</b></summary><div class="privacy-settings-grid">'
            . '<label class="field field--half">Tytuł w Google<input name="meta_title" value="' . $h($edit['meta_title'] ?? '') . '"></label>'
            . '<label class="field field--half">Obraz Open Graph<select name="og_image">' . $mediaOptions((string) (($edit['og_image'] ?? '') ?: ($edit['featured_image'] ?? ''))) . '</select></label>'
            . '<label class="field field--wide">Opis w Google<textarea name="meta_description">' . $h($edit['meta_description'] ?? '') . '</textarea></label>'
            . '</div></details>'
            . '</form></section>';
    };

    $categories = static function (AdminView $view, array $user) use ($repo, $tabs, $categoryForm, $statusPill, $h, $pager): void {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && Csrf::verify($_POST['_csrf'] ?? null)) {
            if (($_POST['action'] ?? '') === 'delete') {
                $repo->deleteCategory((int) ($_POST['id'] ?? 0));
                Url::redirect('/admin/catalog/categories?deleted=1');
            }
            $id = isset($_POST['id']) && $_POST['id'] !== '' ? (int) $_POST['id'] : null;
            $savedId = $repo->saveCategory($_POST, $id);
            Url::redirect('/admin/catalog/categories?id=' . $savedId . '&saved=1');
        }

        if (isset($_GET['id']) || isset($_GET['new'])) {
            $edit = isset($_GET['id']) ? $repo->findCategory((int) $_GET['id']) : null;
            $notice = isset($_GET['saved']) ? '<div class="notice">Kategoria została zapisana.</div>' : '';
            $view->render($edit ? 'Edytuj kategorię' : 'Dodaj kategorię', $notice . $categoryForm($edit), $user);
            return;
        }

        $q = trim((string) ($_GET['q'] ?? ''));
        $status = trim((string) ($_GET['status'] ?? ''));
        $rows = array_values(array_filter($repo->categories(false), static function (array $category) use ($q, $status): bool {
            if ($status !== '' && (string) ($category['status'] ?? '') !== $status) {
                return false;
            }
            if ($q !== '' && stripos((string) ($category['name'] ?? '') . ' ' . (string) ($category['full_path'] ?? ''), $q) === false) {
                return false;
            }

            return true;
        }));
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = 40;
        $pages = max(1, (int) ceil(count($rows) / $perPage));
        $slice = array_slice($rows, ($page - 1) * $perPage, $perPage);

        $body = '';
        foreach ($slice as $category) {
            $body .= '<tr><td><b>' . $h($category['name']) . '</b><br><small>/nasza-oferta/' . $h($category['full_path']) . '</small></td><td>' . $statusPill((string) $category['status']) . '</td><td>' . (int) $category['sort_order'] . '</td><td><div class="actions"><a class="button secondary" href="/admin/catalog/categories?id=' . (int) $category['id'] . '">Edytuj</a><form method="post" onsubmit="return confirm(\'Usunąć kategorię?\')">' . Csrf::field() . '<input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="' . (int) $category['id'] . '"><button class="secondary">Usuń</button></form></div></td></tr>';
        }

        $notice = isset($_GET['deleted']) ? '<div class="notice">Kategoria została usunięta.</div>' : '';
        $content = $tabs('/admin/catalog/categories') . $notice
            . '<section class="panel system-hero"><div><span class="eyebrow">Kategorie produktów</span><h2>Drzewo oferty</h2><p>Najpierw porządek w strukturze, potem edycja konkretnej kategorii. Dzięki temu długa lista nie walczy z formularzem.</p></div><a class="button" href="/admin/catalog/categories?new=1">Dodaj kategorię</a></section>'
            . '<section class="panel list-toolbar"><form method="get" class="filters"><label>Szukaj<input name="q" value="' . $h($q) . '" placeholder="nazwa lub URL"></label><label>Status<select name="status"><option value="">Wszystkie</option><option value="published"' . ($status === 'published' ? ' selected' : '') . '>Opublikowane</option><option value="draft"' . ($status === 'draft' ? ' selected' : '') . '>Szkice</option></select></label><button>Filtruj</button><a class="button secondary" href="/admin/catalog/categories">Wyczyść</a></form></section>'
            . '<section class="panel"><table><thead><tr><th>Kategoria</th><th>Status</th><th>Kolejność</th><th></th></tr></thead><tbody>' . ($body ?: '<tr><td colspan="4">Brak kategorii dla wybranych filtrów.</td></tr>') . '</tbody></table>' . $pager('/admin/catalog/categories', $page, $pages, ['q' => $q, 'status' => $status]) . '</section>';
        $view->render('Kategorie produktów', $content, $user);
    };

    $productForm = static function (?array $edit = null) use ($repo, $tabs, $statusOptions, $mediaOptions, $h): string {
        $specs = '';
        foreach (json_decode((string) ($edit['specs_json'] ?? '[]'), true) ?: [] as $spec) {
            if (is_array($spec)) {
                $specs .= (string) ($spec['name'] ?? '') . ' | ' . (string) ($spec['value'] ?? '') . "\n";
            }
        }
        $gallery = implode("\n", json_decode((string) ($edit['gallery_json'] ?? '[]'), true) ?: []);
        $documents = implode("\n", json_decode((string) ($edit['documents_json'] ?? '[]'), true) ?: []);

        return $tabs('/admin/catalog/products')
            . '<section class="panel page-editor__panel"><div class="page-editor__head"><div><span class="eyebrow">Produkty</span><h2>' . ($edit ? 'Edytuj produkt' : 'Dodaj produkt') . '</h2><p>Produkt ma własną kartę, URL, zdjęcia, parametry i opis SEO. Nie pokazujemy pól sklepowych, jeśli katalog nie jest sklepem.</p></div><div class="actions"><a class="button secondary" href="/admin/catalog/products">Wróć do listy</a><button form="catalog-product-form">Zapisz produkt</button></div></div>'
            . '<form id="catalog-product-form" method="post" class="privacy-settings-grid">' . Csrf::field()
            . ($edit ? '<input type="hidden" name="id" value="' . (int) $edit['id'] . '">' : '')
            . '<details class="editor-section" open><summary><b>Podstawowe</b><span>Nazwa, URL, status i organizacja</span></summary><div class="privacy-settings-grid">'
            . '<label class="field field--half">Nazwa produktu<input name="name" required value="' . $h($edit['name'] ?? '') . '"></label>'
            . '<label class="field field--half">Adres URL<input name="slug" value="' . $h($edit['slug'] ?? '') . '" placeholder="np. mikrometr-helios-preisser-0800-501"></label>'
            . '<label class="field field--half">Kategoria<select name="category_id">' . $repo->categoryTreeOptions(isset($edit['category_id']) ? (int) $edit['category_id'] : null) . '</select></label>'
            . '<label class="field">Status<select name="status">' . $statusOptions((string) ($edit['status'] ?? 'draft')) . '</select></label>'
            . '<label class="field">Kolejność<input type="number" name="sort_order" value="' . $h($edit['sort_order'] ?? 100) . '"></label>'
            . '<label class="field field--switch"><input type="checkbox" name="is_featured" value="1"' . (!empty($edit['is_featured']) ? ' checked' : '') . '> Wyróżniony</label>'
            . '<label class="field">SKU / symbol<input name="sku" value="' . $h($edit['sku'] ?? '') . '"></label>'
            . '<label class="field">Marka<input name="brand" value="' . $h($edit['brand'] ?? '') . '"></label>'
            . '<label class="field">Model<input name="model" value="' . $h($edit['model'] ?? '') . '"></label>'
            . '</div></details>'
            . '<details class="editor-section" open><summary><b>Opis i zdjęcia</b><span>Treści widoczne na karcie produktu</span></summary><div class="privacy-settings-grid">'
            . '<label class="field field--half">Zdjęcie główne<select name="featured_image">' . $mediaOptions((string) ($edit['featured_image'] ?? '')) . '</select></label>'
            . '<label class="field field--wide">Krótki opis<textarea name="summary">' . $h($edit['summary'] ?? '') . '</textarea></label>'
            . '<label class="field field--wide">Opis produktu<textarea name="description">' . $h($edit['description'] ?? '') . '</textarea></label>'
            . '<label class="field field--wide">Galeria, po jednym URL w linii<textarea name="gallery">' . $h($gallery) . '</textarea></label>'
            . '<label class="field field--wide">Dokumenty / katalogi PDF, po jednym URL w linii<textarea name="documents">' . $h($documents) . '</textarea></label>'
            . '</div></details>'
            . '<details class="editor-section"><summary><b>Parametry</b><span>Specyfikacja techniczna produktu</span></summary><label class="field field--wide">Specyfikacja, jedna linia: parametr | wartość<textarea name="specs">' . $h(trim($specs)) . '</textarea></label></details>'
            . '<details class="editor-section seo-accordion"><summary><b>Ustawienia SEO</b><span>Opcjonalne pola dla Google i social media</span></summary><div class="privacy-settings-grid">'
            . '<label class="field field--half">Tytuł w Google<input name="meta_title" value="' . $h($edit['meta_title'] ?? '') . '"></label>'
            . '<label class="field field--half">Obraz Open Graph<select name="og_image">' . $mediaOptions((string) (($edit['og_image'] ?? '') ?: ($edit['featured_image'] ?? ''))) . '</select></label>'
            . '<label class="field field--wide">Opis w Google<textarea name="meta_description">' . $h($edit['meta_description'] ?? '') . '</textarea></label>'
            . '<label class="field field--wide">Własny JSON schema produktu<textarea name="schema_json" class="code-area">' . $h($edit['schema_json'] ?? '') . '</textarea></label>'
            . '</div></details>'
            . '</form></section>';
    };

    $products = static function (AdminView $view, array $user) use ($repo, $tabs, $productForm, $statusPill, $h, $pager): void {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && Csrf::verify($_POST['_csrf'] ?? null)) {
            if (($_POST['action'] ?? '') === 'duplicate') {
                $copyId = $repo->duplicateProduct((int) ($_POST['id'] ?? 0));
                Url::redirect('/admin/catalog/products?id=' . $copyId . '&duplicated=1');
            }
            if (($_POST['action'] ?? '') === 'delete') {
                $repo->deleteProduct((int) ($_POST['id'] ?? 0));
                Url::redirect('/admin/catalog/products?deleted=1');
            }
            $id = isset($_POST['id']) && $_POST['id'] !== '' ? (int) $_POST['id'] : null;
            $savedId = $repo->saveProduct($_POST, $id);
            Url::redirect('/admin/catalog/products?id=' . $savedId . '&saved=1');
        }

        if (isset($_GET['id']) || isset($_GET['new'])) {
            $edit = isset($_GET['id']) ? $repo->findProduct((int) $_GET['id']) : null;
            $notice = isset($_GET['saved']) ? '<div class="notice">Produkt został zapisany.</div>' : '';
            $notice .= isset($_GET['duplicated']) ? '<div class="notice">Produkt został powielony jako szkic.</div>' : '';
            $view->render($edit ? 'Edytuj produkt' : 'Dodaj produkt', $notice . $productForm($edit), $user);
            return;
        }

        $q = trim((string) ($_GET['q'] ?? ''));
        $status = trim((string) ($_GET['status'] ?? ''));
        $categoryId = trim((string) ($_GET['category_id'] ?? ''));
        $rows = array_values(array_filter($repo->products(false), static function (array $product) use ($q, $status, $categoryId): bool {
            if ($status !== '' && (string) ($product['status'] ?? '') !== $status) {
                return false;
            }
            if ($categoryId !== '' && (int) ($product['category_id'] ?? 0) !== (int) $categoryId) {
                return false;
            }
            if ($q !== '' && stripos((string) ($product['name'] ?? '') . ' ' . (string) ($product['sku'] ?? '') . ' ' . (string) ($product['full_path'] ?? ''), $q) === false) {
                return false;
            }

            return true;
        }));
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = 40;
        $pages = max(1, (int) ceil(count($rows) / $perPage));
        $slice = array_slice($rows, ($page - 1) * $perPage, $perPage);

        $body = '';
        foreach ($slice as $product) {
            $productId = (int) $product['id'];
            $duplicateForm = '<form method="post">' . Csrf::field() . '<input type="hidden" name="action" value="duplicate"><input type="hidden" name="id" value="' . $productId . '"><button class="secondary">Powiel</button></form>';
            $body .= '<tr><td><b>' . $h($product['name']) . '</b><br><small>/nasza-oferta/' . $h($product['full_path']) . '</small></td><td>' . $h($product['category_name'] ?? '') . '</td><td>' . $h($product['sku'] ?? '') . '</td><td>' . $statusPill((string) $product['status']) . '</td><td><div class="actions"><a class="button secondary" href="/admin/catalog/products?id=' . $productId . '">Edytuj</a>' . $duplicateForm . '<form method="post" onsubmit="return confirm(\'Usunąć produkt?\')">' . Csrf::field() . '<input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="' . $productId . '"><button class="secondary">Usuń</button></form></div></td></tr>';
        }

        $notice = isset($_GET['deleted']) ? '<div class="notice">Produkt został usunięty.</div>' : '';
        $query = ['q' => $q, 'status' => $status, 'category_id' => $categoryId];
        $content = $tabs('/admin/catalog/products') . $notice
            . '<section class="panel system-hero"><div><span class="eyebrow">Produkty</span><h2>Lista produktów</h2><p>Wyszukuj, filtruj i edytuj pojedyncze karty produktów. Formularz edycji otwiera się dopiero po wyborze produktu.</p></div><a class="button" href="/admin/catalog/products?new=1">Dodaj produkt</a></section>'
            . '<section class="panel list-toolbar"><form method="get" class="filters"><label>Szukaj<input name="q" value="' . $h($q) . '" placeholder="nazwa, SKU lub URL"></label><label>Kategoria<select name="category_id">' . $repo->categoryTreeOptions($categoryId !== '' ? (int) $categoryId : null) . '</select></label><label>Status<select name="status"><option value="">Wszystkie</option><option value="published"' . ($status === 'published' ? ' selected' : '') . '>Opublikowane</option><option value="draft"' . ($status === 'draft' ? ' selected' : '') . '>Szkice</option></select></label><button>Filtruj</button><a class="button secondary" href="/admin/catalog/products">Wyczyść</a></form></section>'
            . '<section class="panel"><table><thead><tr><th>Produkt</th><th>Kategoria</th><th>SKU</th><th>Status</th><th></th></tr></thead><tbody>' . ($body ?: '<tr><td colspan="5">Brak produktów dla wybranych filtrów.</td></tr>') . '</tbody></table>' . $pager('/admin/catalog/products', $page, $pages, $query) . '</section>';
        $view->render('Produkty', $content, $user);
    };

    $import = static function (AdminView $view, array $user) use ($repo, $tabs, $h): void {
        $notice = '';
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && Csrf::verify($_POST['_csrf'] ?? null)) {
            $result = $repo->importCsv((string) ($_POST['csv'] ?? ''));
            $notice = '<div class="notice">Import zakończony. Kategorie dotknięte: ' . (int) $result['categories_touched'] . ', produkty utworzone: ' . (int) $result['products_created'] . '.</div>';
        }

        $example = "category_path;product_name;sku;brand;image;summary\nPrzyrządy pomiarowe > Mikrometry > Mikrometry analogowe;Mikrometr analogowy Helios Preisser 0800 501;0800 501;Helios Preisser;/uploads/mikrometr.jpg;Krótki opis produktu";
        $content = $tabs('/admin/catalog/import') . $notice
            . '<section class="panel system-hero"><div><span class="eyebrow">Import katalogu</span><h2>CSV jako etap przejściowy migracji</h2><p>Importer tworzy brakujące kategorie w drzewie i osobne karty produktów. Produkty po imporcie są szkicami, żeby można było je uzupełnić przed publikacją.</p></div></section>'
            . '<section class="panel"><form method="post">' . Csrf::field()
            . '<label>CSV rozdzielany średnikiem<textarea name="csv" class="code-area" placeholder="' . $h($example) . '"></textarea></label>'
            . '<button>Importuj</button></form></section>';
        $view->render('Import katalogu', $content, $user);
    };

    return [
        'nav' => [
            '/admin/catalog/products' => [
                'label' => 'Produkty',
                'menu_group' => 'Sprzedaż',
                'permission' => 'manage_products',
                'visible_in_client_nav' => true,
                'sort_order' => 100,
            ],
            '/admin/catalog/categories' => [
                'label' => 'Kategorie produktów',
                'menu_group' => 'Sprzedaż',
                'permission' => 'manage_products',
                'visible_in_client_nav' => true,
                'sort_order' => 110,
            ],
        ],
        'routes' => [
            '/admin/catalog' => $dashboard,
            '/admin/catalog/categories' => $categories,
            '/admin/catalog/products' => $products,
            '/admin/catalog/import' => $import,
        ],
    ];
};
