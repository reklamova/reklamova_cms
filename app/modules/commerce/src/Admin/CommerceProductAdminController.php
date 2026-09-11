<?php

declare(strict_types=1);

namespace Reklamova\Cms\Commerce\Admin;

use DomainException;
use PDO;
use Reklamova\Cms\Admin\AdminView;
use Reklamova\Cms\Auth\Csrf;
use Reklamova\Cms\Commerce\Import\DecimalMoneyParser;
use Reklamova\Cms\Support\Url;

final class CommerceProductAdminController
{
    private DecimalMoneyParser $moneyParser;

    /** @param callable(string):string $tabs */
    public function __construct(
        private PDO $pdo,
        private int $storeId,
        private string $currency,
        private $tabs,
    ) {
        $this->moneyParser = new DecimalMoneyParser();
    }

    public function handle(AdminView $view, array $user): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $this->csrf();
            $action = (string) ($_POST['action'] ?? 'save_product');
            match ($action) {
                'create_product' => $this->createProduct(),
                'save_product' => $this->saveProduct(),
                'save_variant' => $this->saveVariant(),
                'save_file_requirement' => $this->saveFileRequirement(),
                default => throw new DomainException('Nieprawidłowa operacja produktu.'),
            };
        }
        if (isset($_GET['id'])) {
            $this->detail($view, $user, (int) $_GET['id']);
            return;
        }
        $this->listing($view, $user);
    }

    private function createProduct(): never
    {
        $name = $this->required($_POST['name'] ?? '', 'Nazwa produktu', 220);
        $slug = $this->slug($_POST['slug'] ?? '', $name);
        $statement = $this->pdo->prepare('INSERT INTO commerce_products (store_id,type,name,slug,status,visibility,currency,stock_status) VALUES (?,"simple",?,?,"draft","catalog_search",?,"in_stock")');
        $statement->execute([$this->storeId, $name, $slug, $this->currency]);
        Url::redirect('/admin/commerce/products?id=' . (int) $this->pdo->lastInsertId());
    }

    private function saveProduct(): never
    {
        $id = (int) ($_POST['id'] ?? 0);
        $this->product($id);
        $name = $this->required($_POST['name'] ?? '', 'Nazwa produktu', 220);
        $slug = $this->slug($_POST['slug'] ?? '', $name);
        $type = $this->oneOf($_POST['type'] ?? '', ['simple','variable','configurable'], 'Nieprawidłowy typ produktu.');
        $status = $this->oneOf($_POST['status'] ?? '', ['draft','published','archived'], 'Nieprawidłowy status produktu.');
        $visibility = $this->oneOf($_POST['visibility'] ?? '', ['catalog_search','catalog','search','hidden'], 'Nieprawidłowa widoczność.');
        $stockStatus = $this->oneOf($_POST['stock_status'] ?? '', ['in_stock','out_of_stock','backorder'], 'Nieprawidłowy status magazynowy.');
        $basePrice = $this->nullableMoney($_POST['base_price'] ?? '');
        $salePrice = $this->nullableMoney($_POST['sale_price'] ?? '');
        if ($salePrice !== null && $basePrice !== null && $salePrice > $basePrice) {
            throw new DomainException('Cena promocyjna nie może być wyższa od bazowej.');
        }
        $stock = $this->nullableInt($_POST['stock_quantity'] ?? '');
        if ($stock !== null && $stock < 0) {
            throw new DomainException('Stan magazynowy nie może być ujemny.');
        }
        $statement = $this->pdo->prepare(
            'UPDATE commerce_products SET type=?,name=?,slug=?,sku=NULLIF(?,""),summary=?,description=?,status=?,visibility=?,base_price_minor=?,sale_price_minor=?,track_stock=?,stock_quantity=?,stock_status=?,backorders_allowed=?,weight_grams=?,width_mm=?,height_mm=?,length_mm=?,featured_image=NULLIF(?,""),meta_title=NULLIF(?,""),meta_description=NULLIF(?,""),canonical_url=NULLIF(?,""),robots=?,sort_order=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND store_id=?'
        );
        $productValues = [
            $type, $name, $slug, trim((string) ($_POST['sku'] ?? '')),
            trim((string) ($_POST['summary'] ?? '')), (string) ($_POST['description'] ?? ''),
            $status, $visibility, $basePrice, $salePrice, !empty($_POST['track_stock']) ? 1 : 0,
            $stock, $stockStatus, !empty($_POST['backorders_allowed']) ? 1 : 0,
            $this->nullableNonNegativeInt($_POST['weight_grams'] ?? ''),
            $this->nullableNonNegativeInt($_POST['width_mm'] ?? ''),
            $this->nullableNonNegativeInt($_POST['height_mm'] ?? ''),
            $this->nullableNonNegativeInt($_POST['length_mm'] ?? ''),
            trim((string) ($_POST['featured_image'] ?? '')),
            trim((string) ($_POST['meta_title'] ?? '')),
            trim((string) ($_POST['meta_description'] ?? '')),
            trim((string) ($_POST['canonical_url'] ?? '')),
            $this->oneOf($_POST['robots'] ?? 'index,follow', ['index,follow','noindex,follow','noindex,nofollow'], 'Nieprawidłowe robots.'),
            (int) ($_POST['sort_order'] ?? 100), $id, $this->storeId,
        ];
        $categoryIds = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['category_ids'] ?? [])), static fn (int $value): bool => $value > 0)));
        if ($categoryIds !== []) {
            $placeholders = implode(',', array_fill(0, count($categoryIds), '?'));
            $allowed = $this->pdo->prepare('SELECT id FROM commerce_categories WHERE store_id=? AND id IN (' . $placeholders . ')');
            $allowed->execute([$this->storeId, ...$categoryIds]);
            $categoryIds = array_map('intval', $allowed->fetchAll(PDO::FETCH_COLUMN));
        }
        $this->pdo->beginTransaction();
        try {
            $statement->execute($productValues);
            $this->pdo->prepare('DELETE FROM commerce_product_categories WHERE product_id=?')->execute([$id]);
            $insert = $this->pdo->prepare('INSERT INTO commerce_product_categories (product_id,category_id,is_primary,sort_order) VALUES (?,?,?,?)');
            foreach ($categoryIds as $index => $categoryId) {
                $insert->execute([$id, $categoryId, $index === 0 ? 1 : 0, ($index + 1) * 10]);
            }
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
        Url::redirect('/admin/commerce/products?id=' . $id . '&saved=1');
    }

    private function saveVariant(): never
    {
        $productId = (int) ($_POST['product_id'] ?? 0);
        $this->product($productId);
        $id = (int) ($_POST['variant_id'] ?? 0);
        if ($id > 0) {
            $statement = $this->pdo->prepare('SELECT id FROM commerce_product_variants WHERE id=? AND product_id=? AND store_id=?');
            $statement->execute([$id, $productId, $this->storeId]);
            if (!$statement->fetchColumn()) {
                throw new DomainException('Nie znaleziono wariantu.');
            }
        }
        $attributes = trim((string) ($_POST['attributes_json'] ?? '{}')) ?: '{}';
        $decoded = json_decode($attributes, true);
        if (!is_array($decoded)) {
            throw new DomainException('Atrybuty wariantu muszą być poprawnym JSON-em.');
        }
        $price = $this->nullableMoney($_POST['price'] ?? '');
        $salePrice = $this->nullableMoney($_POST['sale_price'] ?? '');
        if ($salePrice !== null && $price !== null && $salePrice > $price) {
            throw new DomainException('Cena promocyjna wariantu nie może być wyższa od bazowej.');
        }
        $values = [trim((string) ($_POST['sku'] ?? '')), trim((string) ($_POST['name'] ?? '')), $this->oneOf($_POST['status'] ?? '', ['active','inactive'], 'Nieprawidłowy status wariantu.'), $price, $salePrice, !empty($_POST['track_stock']) ? 1 : 0, $this->nullableNonNegativeInt($_POST['stock_quantity'] ?? ''), $this->oneOf($_POST['stock_status'] ?? '', ['in_stock','out_of_stock','backorder'], 'Nieprawidłowy status magazynowy.'), !empty($_POST['backorders_allowed']) ? 1 : 0, $this->json($decoded), (int) ($_POST['sort_order'] ?? 100)];
        if ($id > 0) {
            $this->pdo->prepare('UPDATE commerce_product_variants SET sku=NULLIF(?,""),name=NULLIF(?,""),status=?,price_minor=?,sale_price_minor=?,track_stock=?,stock_quantity=?,stock_status=?,backorders_allowed=?,attributes_json=?,sort_order=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND product_id=? AND store_id=?')->execute([...$values, $id, $productId, $this->storeId]);
        } else {
            $this->pdo->prepare('INSERT INTO commerce_product_variants (store_id,product_id,sku,name,status,price_minor,sale_price_minor,track_stock,stock_quantity,stock_status,backorders_allowed,attributes_json,sort_order) VALUES (?,?,NULLIF(?,""),NULLIF(?,""),?,?,?,?,?,?,?,?,?)')->execute([$this->storeId, $productId, ...$values]);
        }
        Url::redirect('/admin/commerce/products?id=' . $productId . '&saved=1#variants');
    }

    private function saveFileRequirement(): never
    {
        $productId = (int) ($_POST['product_id'] ?? 0);
        $this->product($productId);
        $id = (int) ($_POST['requirement_id'] ?? 0);
        if ($id > 0) {
            $statement = $this->pdo->prepare('SELECT id FROM commerce_product_file_requirements WHERE id=? AND product_id=?');
            $statement->execute([$id, $productId]);
            if (!$statement->fetchColumn()) {
                throw new DomainException('Nie znaleziono wymagania pliku.');
            }
        }
        $code = strtolower($this->required($_POST['code'] ?? '', 'Kod wymagania', 120));
        if (preg_match('/^[a-z0-9][a-z0-9_-]*$/', $code) !== 1) {
            throw new DomainException('Nieprawidłowy kod wymagania pliku.');
        }
        $extensions = $this->list($_POST['extensions'] ?? '', '/^[a-z0-9]{2,10}$/');
        $mimes = $this->list($_POST['mimes'] ?? '', '~^[a-z0-9.+-]+/[a-z0-9.+-]+$~');
        if ($extensions === [] || $mimes === []) {
            throw new DomainException('Podaj dozwolone rozszerzenia i typy MIME.');
        }
        $maxMb = max(1, min(1024, (int) ($_POST['max_mb'] ?? 25)));
        $values = [$code, $this->required($_POST['label'] ?? '', 'Nazwa pliku', 190), !empty($_POST['required']) ? 1 : 0, 'checkout_or_after', $this->json($extensions), $this->json($mimes), $maxMb * 1048576, max(1, min(20, (int) ($_POST['max_files'] ?? 1))), (int) ($_POST['sort_order'] ?? 100)];
        if ($id > 0) {
            $this->pdo->prepare('UPDATE commerce_product_file_requirements SET code=?,label=?,required=?,upload_timing=?,allowed_extensions_json=?,allowed_mime_types_json=?,max_bytes=?,max_files=?,sort_order=? WHERE id=? AND product_id=?')->execute([...$values, $id, $productId]);
        } else {
            $this->pdo->prepare('INSERT INTO commerce_product_file_requirements (product_id,code,label,required,upload_timing,allowed_extensions_json,allowed_mime_types_json,max_bytes,max_files,sort_order) VALUES (?,?,?,?,?,?,?,?,?,?)')->execute([$productId, ...$values]);
        }
        Url::redirect('/admin/commerce/products?id=' . $productId . '&saved=1#files');
    }

    private function listing(AdminView $view, array $user): void
    {
        $q = trim((string) ($_GET['q'] ?? ''));
        $sql = 'SELECT id,name,sku,status,base_price_minor,stock_quantity,stock_status FROM commerce_products WHERE store_id=?';
        $params = [$this->storeId];
        if ($q !== '') {
            $sql .= ' AND (name LIKE ? OR sku LIKE ? OR slug LIKE ?)';
            array_push($params, '%' . $q . '%', '%' . $q . '%', '%' . $q . '%');
        }
        $sql .= ' ORDER BY name LIMIT 300';
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        $rows = '';
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $product) {
            $rows .= '<tr><td><a href="/admin/commerce/products?id=' . (int) $product['id'] . '"><b>' . $this->h($product['name']) . '</b></a></td><td>' . $this->h($product['sku']) . '</td><td>' . $this->decimal($product['base_price_minor']) . ' ' . $this->h($this->currency) . '</td><td>' . $this->h($product['stock_quantity'] ?? '—') . '</td><td>' . $this->h($product['status']) . '</td></tr>';
        }
        $content = ($this->tabs)('/admin/commerce/products')
            . '<section class="panel list-toolbar"><form class="filters"><label>Szukaj<input name="q" value="' . $this->h($q) . '" placeholder="nazwa, SKU lub URL"></label><button>Filtruj</button></form></section>'
            . '<section class="panel"><h2>Produkty</h2><table><thead><tr><th>Produkt</th><th>SKU</th><th>Cena</th><th>Stan</th><th>Status</th></tr></thead><tbody>' . ($rows ?: '<tr><td colspan="5">Brak produktów.</td></tr>') . '</tbody></table></section>'
            . '<section class="panel"><h2>Dodaj produkt</h2><form method="post" class="filters">' . Csrf::field() . '<input type="hidden" name="action" value="create_product"><label>Nazwa<input name="name" required maxlength="220"></label><label>Slug<input name="slug" maxlength="220"></label><button>Utwórz szkic</button></form></section>';
        $view->render('Produkty', $content, $user);
    }

    private function detail(AdminView $view, array $user, int $id): void
    {
        $product = $this->product($id);
        $selected = $this->pdo->prepare('SELECT category_id FROM commerce_product_categories WHERE product_id=? ORDER BY is_primary DESC,sort_order');
        $selected->execute([$id]);
        $selectedIds = array_map('intval', $selected->fetchAll(PDO::FETCH_COLUMN));
        $categories = $this->pdo->prepare('SELECT id,full_path FROM commerce_categories WHERE store_id=? ORDER BY full_path');
        $categories->execute([$this->storeId]);
        $categoryFields = '';
        foreach ($categories->fetchAll(PDO::FETCH_ASSOC) as $category) {
            $categoryFields .= '<label class="field"><input type="checkbox" name="category_ids[]" value="' . (int) $category['id'] . '"' . (in_array((int) $category['id'], $selectedIds, true) ? ' checked' : '') . '> ' . $this->h($category['full_path']) . '</label>';
        }
        $form = '<section class="panel"><h2>Dane produktu</h2><form method="post" class="privacy-settings-grid">' . Csrf::field() . '<input type="hidden" name="action" value="save_product"><input type="hidden" name="id" value="' . $id . '">'
            . '<label class="field field--half">Nazwa<input name="name" required value="' . $this->h($product['name']) . '"></label><label class="field">Slug<input name="slug" required value="' . $this->h($product['slug']) . '"></label><label class="field">SKU<input name="sku" value="' . $this->h($product['sku']) . '"></label><label class="field">Typ<select name="type">' . $this->options(['simple'=>'Prosty','variable'=>'Wariantowy','configurable'=>'Konfigurowalny'], (string) $product['type']) . '</select></label><label class="field">Status<select name="status">' . $this->options(['draft'=>'Szkic','published'=>'Opublikowany','archived'=>'Archiwum'], (string) $product['status']) . '</select></label><label class="field">Widoczność<select name="visibility">' . $this->options(['catalog_search'=>'Katalog i wyszukiwarka','catalog'=>'Katalog','search'=>'Wyszukiwarka','hidden'=>'Ukryty'], (string) $product['visibility']) . '</select></label>'
            . '<label class="field">Cena bazowa<input name="base_price" inputmode="decimal" value="' . $this->decimal($product['base_price_minor']) . '"></label><label class="field">Cena promocyjna<input name="sale_price" inputmode="decimal" value="' . $this->decimal($product['sale_price_minor']) . '"></label><label class="field">Stan<input type="number" name="stock_quantity" value="' . $this->h($product['stock_quantity']) . '"></label><label class="field">Dostępność<select name="stock_status">' . $this->options(['in_stock'=>'Dostępny','out_of_stock'=>'Brak','backorder'=>'Na zamówienie'], (string) $product['stock_status']) . '</select></label><label class="field"><input type="checkbox" name="track_stock" value="1"' . (!empty($product['track_stock']) ? ' checked' : '') . '> Śledź stan</label><label class="field"><input type="checkbox" name="backorders_allowed" value="1"' . (!empty($product['backorders_allowed']) ? ' checked' : '') . '> Pozwól zamawiać ponad stan</label>'
            . '<label class="field">Waga (g)<input type="number" min="0" name="weight_grams" value="' . $this->h($product['weight_grams']) . '"></label><label class="field">Szerokość (mm)<input type="number" min="0" name="width_mm" value="' . $this->h($product['width_mm']) . '"></label><label class="field">Wysokość (mm)<input type="number" min="0" name="height_mm" value="' . $this->h($product['height_mm']) . '"></label><label class="field">Długość (mm)<input type="number" min="0" name="length_mm" value="' . $this->h($product['length_mm']) . '"></label><label class="field">Kolejność<input type="number" name="sort_order" value="' . (int) $product['sort_order'] . '"></label><label class="field field--half">Zdjęcie główne<input name="featured_image" value="' . $this->h($product['featured_image']) . '"></label>'
            . '<label class="field field--full">Krótki opis<textarea name="summary">' . $this->h($product['summary']) . '</textarea></label><label class="field field--full">Opis HTML<textarea name="description">' . $this->h($product['description']) . '</textarea></label><fieldset class="field field--full"><legend>Kategorie (pierwsza jest główna)</legend><div class="grid">' . $categoryFields . '</div></fieldset>'
            . '<label class="field field--half">Meta title<input name="meta_title" value="' . $this->h($product['meta_title']) . '"></label><label class="field field--full">Meta description<textarea name="meta_description">' . $this->h($product['meta_description']) . '</textarea></label><label class="field field--half">Canonical<input name="canonical_url" value="' . $this->h($product['canonical_url']) . '"></label><label class="field">Robots<select name="robots">' . $this->options(['index,follow'=>'index,follow','noindex,follow'=>'noindex,follow','noindex,nofollow'=>'noindex,nofollow'], (string) $product['robots']) . '</select></label><button>Zapisz produkt</button></form></section>';
        $content = ($this->tabs)('/admin/commerce/products') . $this->saved() . '<section class="panel system-hero"><div><span class="eyebrow">Produkt</span><h2>' . $this->h($product['name']) . '</h2><p>/produkt/' . $this->h($product['slug']) . '</p></div><a class="button secondary" href="/admin/commerce/products">Wróć</a></section>' . $form . $this->variants($id) . $this->requirements($id);
        $view->render('Produkt ' . $product['name'], $content, $user);
    }

    private function variants(int $productId): string
    {
        $statement = $this->pdo->prepare('SELECT * FROM commerce_product_variants WHERE product_id=? AND store_id=? ORDER BY sort_order,id');
        $statement->execute([$productId, $this->storeId]);
        $html = '<section class="panel" id="variants"><h2>Warianty</h2>';
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $variant) {
            $html .= $this->variantForm($productId, $variant);
        }
        return $html . '<h3>Dodaj wariant</h3>' . $this->variantForm($productId, ['id'=>0,'sku'=>'','name'=>'','status'=>'active','price_minor'=>null,'sale_price_minor'=>null,'track_stock'=>0,'stock_quantity'=>null,'stock_status'=>'in_stock','backorders_allowed'=>0,'attributes_json'=>'{}','sort_order'=>100]) . '</section>';
    }

    private function variantForm(int $productId, array $variant): string
    {
        return '<form method="post" class="filters">' . Csrf::field() . '<input type="hidden" name="action" value="save_variant"><input type="hidden" name="product_id" value="' . $productId . '"><input type="hidden" name="variant_id" value="' . (int) $variant['id'] . '"><label>Nazwa<input name="name" value="' . $this->h($variant['name']) . '"></label><label>SKU<input name="sku" value="' . $this->h($variant['sku']) . '"></label><label>Cena<input name="price" value="' . $this->decimal($variant['price_minor']) . '"></label><label>Promocja<input name="sale_price" value="' . $this->decimal($variant['sale_price_minor']) . '"></label><label>Stan<input type="number" min="0" name="stock_quantity" value="' . $this->h($variant['stock_quantity']) . '"></label><label>Status<select name="status">' . $this->options(['active'=>'Aktywny','inactive'=>'Wyłączony'], (string) $variant['status']) . '</select></label><label>Dostępność<select name="stock_status">' . $this->options(['in_stock'=>'Dostępny','out_of_stock'=>'Brak','backorder'=>'Na zamówienie'], (string) $variant['stock_status']) . '</select></label><label>Atrybuty JSON<input name="attributes_json" value="' . $this->h($variant['attributes_json'] ?: '{}') . '"></label><label>Kolejność<input type="number" name="sort_order" value="' . (int) $variant['sort_order'] . '"></label><label><input type="checkbox" name="track_stock" value="1"' . (!empty($variant['track_stock']) ? ' checked' : '') . '> Śledź</label><label><input type="checkbox" name="backorders_allowed" value="1"' . (!empty($variant['backorders_allowed']) ? ' checked' : '') . '> Ponad stan</label><button>Zapisz wariant</button></form>';
    }

    private function requirements(int $productId): string
    {
        $statement = $this->pdo->prepare('SELECT * FROM commerce_product_file_requirements WHERE product_id=? ORDER BY sort_order,id');
        $statement->execute([$productId]);
        $html = '<section class="panel" id="files"><h2>Wymagane pliki do druku</h2>';
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $requirement) {
            $html .= $this->requirementForm($productId, $requirement);
        }
        return $html . '<h3>Dodaj wymaganie</h3>' . $this->requirementForm($productId, ['id'=>0,'code'=>'print_file','label'=>'Plik do druku','required'=>1,'allowed_extensions_json'=>'["pdf","png","jpg","jpeg"]','allowed_mime_types_json'=>'["application/pdf","image/png","image/jpeg"]','max_bytes'=>26214400,'max_files'=>3,'sort_order'=>100]) . '</section>';
    }

    private function requirementForm(int $productId, array $requirement): string
    {
        return '<form method="post" class="filters">' . Csrf::field() . '<input type="hidden" name="action" value="save_file_requirement"><input type="hidden" name="product_id" value="' . $productId . '"><input type="hidden" name="requirement_id" value="' . (int) $requirement['id'] . '"><label>Kod<input name="code" required value="' . $this->h($requirement['code']) . '"></label><label>Nazwa<input name="label" required value="' . $this->h($requirement['label']) . '"></label><label>Rozszerzenia<input name="extensions" value="' . $this->h(implode(', ', json_decode((string) $requirement['allowed_extensions_json'], true) ?: [])) . '"></label><label>MIME<input name="mimes" value="' . $this->h(implode(', ', json_decode((string) $requirement['allowed_mime_types_json'], true) ?: [])) . '"></label><label>Limit MB<input type="number" min="1" max="1024" name="max_mb" value="' . max(1, (int) ceil((int) $requirement['max_bytes'] / 1048576)) . '"></label><label>Liczba plików<input type="number" min="1" max="20" name="max_files" value="' . (int) $requirement['max_files'] . '"></label><label>Kolejność<input type="number" name="sort_order" value="' . (int) $requirement['sort_order'] . '"></label><label><input type="checkbox" name="required" value="1"' . (!empty($requirement['required']) ? ' checked' : '') . '> Wymagany</label><button>Zapisz wymaganie</button></form>';
    }

    /** @return array<string, mixed> */
    private function product(int $id): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM commerce_products WHERE id=? AND store_id=? LIMIT 1');
        $statement->execute([$id, $this->storeId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new DomainException('Nie znaleziono produktu.');
        }
        return $row;
    }

    /** @return array<int, string> */
    private function list(mixed $value, string $pattern): array
    {
        return array_values(array_unique(array_filter(array_map(static function (string $item) use ($pattern): string {
            $item = strtolower(trim($item));
            return preg_match($pattern, $item) === 1 ? $item : '';
        }, preg_split('/[,\s]+/', (string) $value) ?: []))));
    }

    private function csrf(): void { if (!Csrf::verify(isset($_POST['_csrf']) ? (string) $_POST['_csrf'] : null)) { http_response_code(419); throw new DomainException('Sesja formularza wygasła.'); } }
    private function saved(): string { return isset($_GET['saved']) ? '<div class="notice">Zmiany zapisano.</div>' : ''; }
    private function h(mixed $value): string { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
    private function json(array $value): string { return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR); }
    private function decimal(mixed $minor): string { return $minor === null || $minor === '' ? '' : number_format((int) $minor / 100, 2, '.', ''); }
    private function nullableMoney(mixed $value): ?int { $value = trim((string) $value); if ($value === '') { return null; } $minor = $this->moneyParser->parse($value); if ($minor === null || $minor < 0) { throw new DomainException('Kwota jest nieprawidłowa.'); } return $minor; }
    private function nullableInt(mixed $value): ?int { $value = trim((string) $value); return $value === '' ? null : (int) $value; }
    private function nullableNonNegativeInt(mixed $value): ?int { $value = $this->nullableInt($value); if ($value !== null && $value < 0) { throw new DomainException('Wartość nie może być ujemna.'); } return $value; }
    private function required(mixed $value, string $label, int $max): string { $value = trim((string) $value); if ($value === '' || mb_strlen($value) > $max) { throw new DomainException($label . ' jest wymagana i musi mieścić się w limicie.'); } return $value; }
    private function slug(mixed $value, string $fallback): string { $value = trim((string) $value) ?: $fallback; $value = strtr($value, ['ą'=>'a','ć'=>'c','ę'=>'e','ł'=>'l','ń'=>'n','ó'=>'o','ś'=>'s','ż'=>'z','ź'=>'z','Ą'=>'a','Ć'=>'c','Ę'=>'e','Ł'=>'l','Ń'=>'n','Ó'=>'o','Ś'=>'s','Ż'=>'z','Ź'=>'z']); $value = trim((string) preg_replace('/[^a-z0-9]+/i', '-', strtolower($value)), '-'); if ($value === '') { throw new DomainException('Slug jest nieprawidłowy.'); } return $value; }
    private function oneOf(mixed $value, array $allowed, string $message): string { $value = (string) $value; if (!in_array($value, $allowed, true)) { throw new DomainException($message); } return $value; }
    /** @param array<string, string> $values */
    private function options(array $values, string $selected): string { $html = ''; foreach ($values as $value => $label) { $html .= '<option value="' . $this->h($value) . '"' . ($value === $selected ? ' selected' : '') . '>' . $this->h($label) . '</option>'; } return $html; }
}
