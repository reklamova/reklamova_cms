<?php

declare(strict_types=1);

namespace Reklamova\Cms\Commerce\Admin;

use DomainException;
use PDO;
use Reklamova\Cms\Admin\AdminView;
use Reklamova\Cms\Auth\Csrf;
use Reklamova\Cms\Commerce\Import\DecimalMoneyParser;
use Reklamova\Cms\Support\Url;

final class CommerceConfigurationController
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

    public function categories(AdminView $view, array $user): void
    {
        if ($this->isPost()) {
            $this->csrf();
            $id = (int) ($_POST['id'] ?? 0);
            $name = $this->required($_POST['name'] ?? '', 'Nazwa kategorii', 190);
            $slug = $this->slug($_POST['slug'] ?? '', $name);
            $parentId = (int) ($_POST['parent_id'] ?? 0) ?: null;
            $parentPath = '';
            if ($parentId !== null) {
                $parent = $this->category($parentId);
                if ($parent === null || $parentId === $id) {
                    throw new DomainException('Nieprawidłowa kategoria nadrzędna.');
                }
                $parentPath = trim((string) $parent['full_path'], '/');
            }
            $fullPath = trim($parentPath . '/' . $slug, '/');
            $status = $this->oneOf($_POST['status'] ?? '', ['draft', 'published', 'archived'], 'Nieprawidłowy status kategorii.');
            $payload = [
                $parentId, $name, $slug, $fullPath,
                trim((string) ($_POST['summary'] ?? '')),
                (string) ($_POST['description'] ?? ''),
                $status, (int) ($_POST['sort_order'] ?? 100),
                trim((string) ($_POST['meta_title'] ?? '')),
                trim((string) ($_POST['meta_description'] ?? '')),
                trim((string) ($_POST['canonical_url'] ?? '')),
                $this->oneOf($_POST['robots'] ?? 'index,follow', ['index,follow', 'noindex,follow', 'noindex,nofollow'], 'Nieprawidłowe robots.'),
            ];
            $this->pdo->beginTransaction();
            try {
                if ($id > 0) {
                    $existing = $this->category($id);
                    if ($existing === null) {
                        throw new DomainException('Nie znaleziono kategorii.');
                    }
                    $oldPath = (string) $existing['full_path'];
                    if ($parentPath !== '' && ($parentPath === $oldPath || str_starts_with($parentPath, $oldPath . '/'))) {
                        throw new DomainException('Kategoria nie może zostać przeniesiona do własnego poddrzewa.');
                    }
                    $statement = $this->pdo->prepare('UPDATE commerce_categories SET parent_id=?, name=?, slug=?, full_path=?, summary=?, description=?, status=?, sort_order=?, meta_title=?, meta_description=?, canonical_url=?, robots=?, updated_at=CURRENT_TIMESTAMP WHERE id=? AND store_id=?');
                    $statement->execute([...$payload, $id, $this->storeId]);
                    if ($oldPath !== $fullPath) {
                        $descendants = $this->pdo->prepare('UPDATE commerce_categories SET full_path = CONCAT(?, SUBSTRING(full_path, ?)), updated_at=CURRENT_TIMESTAMP WHERE store_id=? AND full_path LIKE ?');
                        $descendants->execute([$fullPath, strlen($oldPath) + 1, $this->storeId, $oldPath . '/%']);
                    }
                } else {
                    $statement = $this->pdo->prepare('INSERT INTO commerce_categories (store_id,parent_id,name,slug,full_path,summary,description,status,sort_order,meta_title,meta_description,canonical_url,robots) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
                    $statement->execute([$this->storeId, ...$payload]);
                    $id = (int) $this->pdo->lastInsertId();
                }
                $this->pdo->commit();
            } catch (\Throwable $exception) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                throw $exception;
            }
            Url::redirect('/admin/commerce/categories?id=' . $id . '&saved=1');
        }

        $edit = isset($_GET['id']) ? $this->category((int) $_GET['id']) : null;
        $rows = '';
        foreach ($this->categoryRows() as $category) {
            $rows .= '<tr><td><a href="/admin/commerce/categories?id=' . (int) $category['id'] . '"><b>' . $this->h($category['name']) . '</b></a><br><small>/' . $this->h($category['full_path']) . '</small></td><td>' . $this->h($category['status']) . '</td><td>' . (int) $category['products_count'] . '</td></tr>';
        }
        $form = $edit ?? ['id' => 0, 'parent_id' => '', 'name' => '', 'slug' => '', 'summary' => '', 'description' => '', 'status' => 'draft', 'sort_order' => 100, 'meta_title' => '', 'meta_description' => '', 'canonical_url' => '', 'robots' => 'index,follow'];
        $parents = '<option value="">— główna —</option>';
        foreach ($this->categoryRows() as $category) {
            if ((int) $category['id'] === (int) $form['id']) {
                continue;
            }
            $parents .= '<option value="' . (int) $category['id'] . '"' . ((int) $form['parent_id'] === (int) $category['id'] ? ' selected' : '') . '>' . $this->h($category['full_path']) . '</option>';
        }
        $content = ($this->tabs)('/admin/commerce/categories') . $this->saved()
            . '<section class="panel"><h2>Kategorie</h2><table><thead><tr><th>Kategoria</th><th>Status</th><th>Produkty</th></tr></thead><tbody>' . ($rows ?: '<tr><td colspan="3">Brak kategorii.</td></tr>') . '</tbody></table></section>'
            . '<section class="panel"><h2>' . ((int) $form['id'] > 0 ? 'Edytuj kategorię' : 'Dodaj kategorię') . '</h2><form method="post" class="privacy-settings-grid">' . Csrf::field() . '<input type="hidden" name="id" value="' . (int) $form['id'] . '">'
            . '<label class="field field--half">Nazwa<input name="name" required maxlength="190" value="' . $this->h($form['name']) . '"></label><label class="field">Slug<input name="slug" maxlength="190" value="' . $this->h($form['slug']) . '"></label><label class="field">Nadrzędna<select name="parent_id">' . $parents . '</select></label>'
            . '<label class="field">Status<select name="status">' . $this->options(['draft' => 'Szkic', 'published' => 'Opublikowana', 'archived' => 'Archiwum'], (string) $form['status']) . '</select></label><label class="field">Kolejność<input type="number" name="sort_order" value="' . (int) $form['sort_order'] . '"></label>'
            . '<label class="field field--full">Opis skrócony<textarea name="summary">' . $this->h($form['summary']) . '</textarea></label><label class="field field--full">Opis HTML<textarea name="description">' . $this->h($form['description']) . '</textarea></label>'
            . '<label class="field field--half">Meta title<input name="meta_title" maxlength="190" value="' . $this->h($form['meta_title']) . '"></label><label class="field field--full">Meta description<textarea name="meta_description">' . $this->h($form['meta_description']) . '</textarea></label><label class="field field--half">Canonical<input name="canonical_url" value="' . $this->h($form['canonical_url']) . '"></label><label class="field">Robots<select name="robots">' . $this->options(['index,follow' => 'index,follow', 'noindex,follow' => 'noindex,follow', 'noindex,nofollow' => 'noindex,nofollow'], (string) $form['robots']) . '</select></label><button>Zapisz kategorię</button></form></section>';
        $view->render('Kategorie sklepu', $content, $user);
    }

    public function shipping(AdminView $view, array $user): void
    {
        if ($this->isPost()) {
            $this->csrf();
            $id = (int) ($_POST['id'] ?? 0);
            $code = $this->code($_POST['code'] ?? '');
            $name = $this->required($_POST['name'] ?? '', 'Nazwa dostawy', 190);
            $type = $this->oneOf($_POST['type'] ?? '', ['courier', 'pickup_point', 'parcel_locker', 'local_pickup'], 'Nieprawidłowy typ dostawy.');
            $price = $this->requiredMoney($_POST['price'] ?? '', 'Cena dostawy');
            $freeFrom = $this->nullableMoney($_POST['free_from'] ?? '');
            $vat = max(0, min(10000, (int) ($_POST['tax_rate_bps'] ?? 2300)));
            $countries = array_values(array_unique(array_filter(array_map(static function (string $country): string {
                $country = strtoupper(trim($country));
                return preg_match('/^[A-Z]{2}$/', $country) === 1 ? $country : '';
            }, preg_split('/[,\s]+/', (string) ($_POST['countries'] ?? 'PL')) ?: []))));
            if ($countries === []) {
                throw new DomainException('Podaj co najmniej jeden poprawny kod kraju.');
            }
            $values = [$code, $name, trim((string) ($_POST['provider'] ?? '')), trim((string) ($_POST['service_code'] ?? '')), $type, $price, $vat, !empty($_POST['cod_allowed']) ? 1 : 0, !empty($_POST['active']) ? 1 : 0, $this->json($countries), $this->json(array_filter(['free_from_minor' => $freeFrom], static fn (mixed $value): bool => $value !== null)), (int) ($_POST['sort_order'] ?? 100)];
            $id = $this->upsert('commerce_shipping_methods', $id, ['code','name','provider','service_code','type','price_minor','tax_rate_bps','cod_allowed','active','countries_json','rules_json','sort_order'], $values);
            Url::redirect('/admin/commerce/shipping?id=' . $id . '&saved=1');
        }
        $edit = $this->record('commerce_shipping_methods', (int) ($_GET['id'] ?? 0));
        $form = $edit ?? ['id'=>0,'code'=>'','name'=>'','provider'=>'','service_code'=>'','type'=>'courier','price_minor'=>0,'tax_rate_bps'=>2300,'cod_allowed'=>0,'active'=>1,'countries_json'=>'["PL"]','rules_json'=>'{}','sort_order'=>100];
        $countries = implode(', ', json_decode((string) $form['countries_json'], true) ?: []);
        $rules = json_decode((string) $form['rules_json'], true) ?: [];
        $content = ($this->tabs)('/admin/commerce/shipping') . $this->saved() . $this->shippingTable()
            . '<section class="panel"><h2>' . ((int) $form['id'] > 0 ? 'Edytuj dostawę' : 'Dodaj dostawę') . '</h2><form method="post" class="privacy-settings-grid">' . Csrf::field() . '<input type="hidden" name="id" value="' . (int) $form['id'] . '">'
            . '<label class="field">Kod<input name="code" required value="' . $this->h($form['code']) . '"></label><label class="field field--half">Nazwa<input name="name" required value="' . $this->h($form['name']) . '"></label><label class="field">Typ<select name="type">' . $this->options(['courier'=>'Kurier','pickup_point'=>'Punkt odbioru','parcel_locker'=>'Paczkomat','local_pickup'=>'Odbiór osobisty'], (string) $form['type']) . '</select></label>'
            . '<label class="field">Cena brutto<input name="price" inputmode="decimal" value="' . $this->decimal($form['price_minor']) . '"></label><label class="field">Darmowa od<input name="free_from" inputmode="decimal" value="' . $this->decimal($rules['free_from_minor'] ?? null) . '"></label><label class="field">VAT (punkty bazowe)<input type="number" min="0" max="10000" name="tax_rate_bps" value="' . (int) $form['tax_rate_bps'] . '"></label>'
            . '<label class="field">Operator<input name="provider" value="' . $this->h($form['provider']) . '"></label><label class="field">Kod usługi<input name="service_code" value="' . $this->h($form['service_code']) . '"></label><label class="field field--half">Kraje (ISO, po przecinku)<input name="countries" value="' . $this->h($countries) . '"></label><label class="field">Kolejność<input type="number" name="sort_order" value="' . (int) $form['sort_order'] . '"></label>'
            . '<label class="field"><input type="checkbox" name="cod_allowed" value="1"' . (!empty($form['cod_allowed']) ? ' checked' : '') . '> Obsługuje pobranie</label><label class="field"><input type="checkbox" name="active" value="1"' . (!empty($form['active']) ? ' checked' : '') . '> Aktywna</label><button>Zapisz dostawę</button></form></section>';
        $view->render('Dostawy', $content, $user);
    }

    public function payments(AdminView $view, array $user): void
    {
        if ($this->isPost()) {
            $this->csrf();
            $id = (int) ($_POST['id'] ?? 0);
            $values = [
                $this->code($_POST['code'] ?? ''),
                $this->required($_POST['name'] ?? '', 'Nazwa płatności', 190),
                trim((string) ($_POST['provider'] ?? '')),
                $this->oneOf($_POST['type'] ?? '', ['online','bank_transfer','cod','offline'], 'Nieprawidłowy typ płatności.'),
                !empty($_POST['active']) ? 1 : 0,
                '{}',
                (int) ($_POST['sort_order'] ?? 100),
            ];
            $id = $this->upsert('commerce_payment_methods', $id, ['code','name','provider','type','active','rules_json','sort_order'], $values);
            Url::redirect('/admin/commerce/payments?id=' . $id . '&saved=1');
        }
        $edit = $this->record('commerce_payment_methods', (int) ($_GET['id'] ?? 0));
        $form = $edit ?? ['id'=>0,'code'=>'','name'=>'','provider'=>'','type'=>'online','active'=>1,'sort_order'=>100];
        $content = ($this->tabs)('/admin/commerce/payments') . $this->saved() . $this->paymentTable()
            . '<section class="panel"><h2>' . ((int) $form['id'] > 0 ? 'Edytuj płatność' : 'Dodaj płatność') . '</h2><form method="post" class="privacy-settings-grid">' . Csrf::field() . '<input type="hidden" name="id" value="' . (int) $form['id'] . '"><label class="field">Kod<input name="code" required value="' . $this->h($form['code']) . '"></label><label class="field field--half">Nazwa<input name="name" required value="' . $this->h($form['name']) . '"></label><label class="field">Operator<input name="provider" value="' . $this->h($form['provider']) . '"></label><label class="field">Typ<select name="type">' . $this->options(['online'=>'Online','bank_transfer'=>'Przelew','cod'=>'Pobranie','offline'=>'Offline'], (string) $form['type']) . '</select></label><label class="field">Kolejność<input type="number" name="sort_order" value="' . (int) $form['sort_order'] . '"></label><label class="field"><input type="checkbox" name="active" value="1"' . (!empty($form['active']) ? ' checked' : '') . '> Aktywna</label><button>Zapisz płatność</button></form></section>';
        $view->render('Płatności', $content, $user);
    }

    public function coupons(AdminView $view, array $user): void
    {
        if ($this->isPost()) {
            $this->csrf();
            $id = (int) ($_POST['id'] ?? 0);
            $type = $this->oneOf($_POST['type'] ?? '', ['percentage','fixed_cart'], 'Nieprawidłowy typ rabatu.');
            $value = $this->requiredMoney($_POST['value'] ?? '', 'Wartość rabatu');
            $values = [strtoupper($this->required($_POST['code'] ?? '', 'Kod rabatowy', 120)), $type, $type === 'fixed_cart' ? $value : null, $type === 'percentage' ? $value : null, $this->nullableMoney($_POST['minimum'] ?? ''), $this->nullableMoney($_POST['maximum'] ?? ''), $this->nullablePositiveInt($_POST['usage_limit'] ?? ''), $this->nullablePositiveInt($_POST['usage_per_customer'] ?? ''), $this->date($_POST['starts_at'] ?? ''), $this->date($_POST['ends_at'] ?? ''), !empty($_POST['active']) ? 1 : 0, '{}'];
            $id = $this->upsert('commerce_coupons', $id, ['code','type','value_minor','value_bps','minimum_minor','maximum_discount_minor','usage_limit','usage_limit_per_customer','starts_at','ends_at','active','rules_json'], $values);
            Url::redirect('/admin/commerce/coupons?id=' . $id . '&saved=1');
        }
        $edit = $this->record('commerce_coupons', (int) ($_GET['id'] ?? 0));
        $form = $edit ?? ['id'=>0,'code'=>'','type'=>'percentage','value_minor'=>null,'value_bps'=>1000,'minimum_minor'=>null,'maximum_discount_minor'=>null,'usage_limit'=>null,'usage_limit_per_customer'=>null,'starts_at'=>null,'ends_at'=>null,'active'=>1];
        $value = $form['type'] === 'fixed_cart' ? $form['value_minor'] : $form['value_bps'];
        $content = ($this->tabs)('/admin/commerce/coupons') . $this->saved() . $this->couponTable()
            . '<section class="panel"><h2>' . ((int) $form['id'] > 0 ? 'Edytuj rabat' : 'Dodaj rabat') . '</h2><form method="post" class="privacy-settings-grid">' . Csrf::field() . '<input type="hidden" name="id" value="' . (int) $form['id'] . '"><label class="field">Kod<input name="code" required value="' . $this->h($form['code']) . '"></label><label class="field">Typ<select name="type">' . $this->options(['percentage'=>'Procent','fixed_cart'=>'Kwota'], (string) $form['type']) . '</select></label><label class="field">Wartość<input name="value" inputmode="decimal" required value="' . $this->decimal($value) . '"></label><label class="field">Minimum koszyka<input name="minimum" inputmode="decimal" value="' . $this->decimal($form['minimum_minor']) . '"></label><label class="field">Maksymalny rabat<input name="maximum" inputmode="decimal" value="' . $this->decimal($form['maximum_discount_minor']) . '"></label><label class="field">Limit użyć<input type="number" min="1" name="usage_limit" value="' . $this->h($form['usage_limit']) . '"></label><label class="field">Limit / klient<input type="number" min="1" name="usage_per_customer" value="' . $this->h($form['usage_limit_per_customer']) . '"></label><label class="field">Od (YYYY-MM-DD)<input name="starts_at" value="' . $this->h($this->dateOnly($form['starts_at'])) . '"></label><label class="field">Do (YYYY-MM-DD)<input name="ends_at" value="' . $this->h($this->dateOnly($form['ends_at'])) . '"></label><label class="field"><input type="checkbox" name="active" value="1"' . (!empty($form['active']) ? ' checked' : '') . '> Aktywny</label><button>Zapisz rabat</button></form></section>';
        $view->render('Rabaty', $content, $user);
    }

    private function shippingTable(): string
    {
        $rows = '';
        foreach ($this->all('commerce_shipping_methods') as $row) {
            $rules = json_decode((string) $row['rules_json'], true) ?: [];
            $rows .= '<tr><td><a href="/admin/commerce/shipping?id=' . (int) $row['id'] . '"><b>' . $this->h($row['name']) . '</b></a><br><small>' . $this->h($row['code']) . '</small></td><td>' . $this->decimal($row['price_minor']) . ' ' . $this->h($this->currency) . '</td><td>' . $this->decimal($rules['free_from_minor'] ?? null) . '</td><td>' . (!empty($row['active']) ? 'Aktywna' : 'Wyłączona') . '</td></tr>';
        }
        return '<section class="panel"><h2>Metody dostawy</h2><table><thead><tr><th>Metoda</th><th>Cena</th><th>Darmowa od</th><th>Status</th></tr></thead><tbody>' . ($rows ?: '<tr><td colspan="4">Brak metod.</td></tr>') . '</tbody></table></section>';
    }

    private function paymentTable(): string
    {
        $rows = '';
        foreach ($this->all('commerce_payment_methods') as $row) {
            $rows .= '<tr><td><a href="/admin/commerce/payments?id=' . (int) $row['id'] . '"><b>' . $this->h($row['name']) . '</b></a><br><small>' . $this->h($row['code']) . '</small></td><td>' . $this->h($row['provider']) . '</td><td>' . $this->h($row['type']) . '</td><td>' . (!empty($row['active']) ? 'Aktywna' : 'Wyłączona') . '</td></tr>';
        }
        return '<section class="panel"><h2>Metody płatności</h2><table><thead><tr><th>Metoda</th><th>Operator</th><th>Typ</th><th>Status</th></tr></thead><tbody>' . ($rows ?: '<tr><td colspan="4">Brak metod.</td></tr>') . '</tbody></table></section>';
    }

    private function couponTable(): string
    {
        $rows = '';
        foreach ($this->all('commerce_coupons') as $row) {
            $value = $row['type'] === 'percentage' ? $this->decimal($row['value_bps']) . '%' : $this->decimal($row['value_minor']) . ' ' . $this->h($this->currency);
            $rows .= '<tr><td><a href="/admin/commerce/coupons?id=' . (int) $row['id'] . '"><b>' . $this->h($row['code']) . '</b></a></td><td>' . $value . '</td><td>' . $this->h($this->dateOnly($row['ends_at'])) . '</td><td>' . (!empty($row['active']) ? 'Aktywny' : 'Wyłączony') . '</td></tr>';
        }
        return '<section class="panel"><h2>Kody rabatowe</h2><table><thead><tr><th>Kod</th><th>Wartość</th><th>Ważny do</th><th>Status</th></tr></thead><tbody>' . ($rows ?: '<tr><td colspan="4">Brak kodów.</td></tr>') . '</tbody></table></section>';
    }

    /** @return array<int, array<string, mixed>> */
    private function categoryRows(): array
    {
        $statement = $this->pdo->prepare('SELECT c.*, COUNT(pc.product_id) AS products_count FROM commerce_categories c LEFT JOIN commerce_product_categories pc ON pc.category_id=c.id WHERE c.store_id=? GROUP BY c.id ORDER BY c.full_path');
        $statement->execute([$this->storeId]);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string, mixed>|null */
    private function category(int $id): ?array
    {
        return $this->record('commerce_categories', $id);
    }

    /** @return array<int, array<string, mixed>> */
    private function all(string $table): array
    {
        $allowed = ['commerce_shipping_methods','commerce_payment_methods','commerce_coupons'];
        if (!in_array($table, $allowed, true)) {
            throw new DomainException('Nieprawidłowa tabela konfiguracji.');
        }
        $sql = $table === 'commerce_coupons'
            ? 'SELECT * FROM commerce_coupons WHERE store_id=? ORDER BY active DESC, code'
            : 'SELECT * FROM ' . $table . ' WHERE store_id=? ORDER BY sort_order, id';
        $statement = $this->pdo->prepare($sql);
        $statement->execute([$this->storeId]);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string, mixed>|null */
    private function record(string $table, int $id): ?array
    {
        if ($id <= 0 || !in_array($table, ['commerce_categories','commerce_shipping_methods','commerce_payment_methods','commerce_coupons'], true)) {
            return null;
        }
        $statement = $this->pdo->prepare('SELECT * FROM ' . $table . ' WHERE id=? AND store_id=? LIMIT 1');
        $statement->execute([$id, $this->storeId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** @param array<int, string> $columns @param array<int, mixed> $values */
    private function upsert(string $table, int $id, array $columns, array $values): int
    {
        if (!in_array($table, ['commerce_shipping_methods','commerce_payment_methods','commerce_coupons'], true)) {
            throw new DomainException('Nieprawidłowa tabela konfiguracji.');
        }
        if ($id > 0) {
            $assignments = implode(',', array_map(static fn (string $column): string => $column . '=?', $columns));
            $statement = $this->pdo->prepare('UPDATE ' . $table . ' SET ' . $assignments . ', updated_at=CURRENT_TIMESTAMP WHERE id=? AND store_id=?');
            $statement->execute([...$values, $id, $this->storeId]);
            if ($statement->rowCount() === 0 && $this->record($table, $id) === null) {
                throw new DomainException('Nie znaleziono rekordu do edycji.');
            }
            return $id;
        }
        $columnList = implode(',', ['store_id', ...$columns]);
        $placeholders = implode(',', array_fill(0, count($columns) + 1, '?'));
        $statement = $this->pdo->prepare('INSERT INTO ' . $table . ' (' . $columnList . ') VALUES (' . $placeholders . ')');
        $statement->execute([$this->storeId, ...$values]);
        return (int) $this->pdo->lastInsertId();
    }

    private function isPost(): bool { return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'; }
    private function csrf(): void { if (!Csrf::verify(isset($_POST['_csrf']) ? (string) $_POST['_csrf'] : null)) { http_response_code(419); throw new DomainException('Sesja formularza wygasła.'); } }
    private function h(mixed $value): string { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
    private function saved(): string { return isset($_GET['saved']) ? '<div class="notice">Zmiany zapisano.</div>' : ''; }
    private function json(array $value): string { return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR); }
    private function decimal(mixed $minor): string { return $minor === null || $minor === '' ? '' : number_format((int) $minor / 100, 2, '.', ''); }
    private function dateOnly(mixed $value): string { return trim((string) $value) === '' ? '' : substr((string) $value, 0, 10); }
    private function nullablePositiveInt(mixed $value): ?int { $value = trim((string) $value); return $value === '' ? null : max(1, (int) $value); }
    private function nullableMoney(mixed $value): ?int { $value = trim((string) $value); return $value === '' ? null : $this->requiredMoney($value, 'Kwota'); }
    private function requiredMoney(mixed $value, string $label): int { $money = $this->moneyParser->parse((string) $value); if ($money === null || $money < 0) { throw new DomainException($label . ' jest nieprawidłowa.'); } return $money; }
    private function required(mixed $value, string $label, int $max): string { $value = trim((string) $value); if ($value === '' || mb_strlen($value) > $max) { throw new DomainException($label . ' jest wymagana i musi mieścić się w limicie.'); } return $value; }
    private function code(mixed $value): string { $value = strtolower(trim((string) $value)); if (preg_match('/^[a-z0-9][a-z0-9_-]{1,119}$/', $value) !== 1) { throw new DomainException('Kod może zawierać małe litery, cyfry, myślnik i podkreślenie.'); } return $value; }
    private function slug(mixed $value, string $fallback): string { $value = trim((string) $value) ?: $fallback; $value = strtr($value, ['ą'=>'a','ć'=>'c','ę'=>'e','ł'=>'l','ń'=>'n','ó'=>'o','ś'=>'s','ż'=>'z','ź'=>'z','Ą'=>'a','Ć'=>'c','Ę'=>'e','Ł'=>'l','Ń'=>'n','Ó'=>'o','Ś'=>'s','Ż'=>'z','Ź'=>'z']); $value = trim((string) preg_replace('/[^a-z0-9]+/i', '-', strtolower($value)), '-'); if ($value === '') { throw new DomainException('Slug jest nieprawidłowy.'); } return $value; }
    private function oneOf(mixed $value, array $allowed, string $message): string { $value = (string) $value; if (!in_array($value, $allowed, true)) { throw new DomainException($message); } return $value; }
    private function date(mixed $value): ?string { $value = trim((string) $value); if ($value === '') { return null; } $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value); if (!$date || $date->format('Y-m-d') !== $value) { throw new DomainException('Data musi mieć format YYYY-MM-DD.'); } return $date->format('Y-m-d H:i:s'); }
    /** @param array<string, string> $values */
    private function options(array $values, string $selected): string { $html = ''; foreach ($values as $value => $label) { $html .= '<option value="' . $this->h($value) . '"' . ($value === $selected ? ' selected' : '') . '>' . $this->h($label) . '</option>'; } return $html; }
}
