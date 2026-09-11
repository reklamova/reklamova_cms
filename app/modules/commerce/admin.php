<?php

declare(strict_types=1);

use Reklamova\Cms\Admin\AdminView;
use Reklamova\Cms\Auth\Csrf;
use Reklamova\Cms\Commerce\Import\DecimalMoneyParser;
use Reklamova\Cms\Commerce\Orders\OrderStatus;
use Reklamova\Cms\Commerce\Orders\OrderLifecycleService;
use Reklamova\Cms\Support\Config;
use Reklamova\Cms\Support\Url;

return static function (array $container, PDO $pdo, array $module): array {
    $h = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    $money = static fn (int $minor, string $currency = 'PLN'): string => number_format($minor / 100, 2, ',', ' ') . ' ' . $h($currency);
    $storeCode = (string) (new Config($container))->get('commerce', 'store_code', 'default');
    $storeStatement = $pdo->prepare('SELECT id, currency FROM commerce_stores WHERE code = ? LIMIT 1');
    $storeStatement->execute([$storeCode]);
    $store = $storeStatement->fetch(PDO::FETCH_ASSOC) ?: ['id' => 0, 'currency' => 'PLN'];
    $storeId = (int) $store['id'];
    $currency = (string) $store['currency'];
    $tabs = static function (string $active) use ($h): string {
        $items = ['/admin/commerce' => 'Pulpit', '/admin/commerce/orders' => 'Zamówienia', '/admin/commerce/products' => 'Produkty', '/admin/commerce/customers' => 'Klienci'];
        $html = '<div class="privacy-tabs commerce-tabs">';
        foreach ($items as $url => $label) {
            $html .= '<a class="button ' . ($active === $url ? '' : 'secondary') . '" href="' . $h($url) . '">' . $h($label) . '</a>';
        }
        return $html . '</div>';
    };
    $pill = static fn (string $value): string => '<span class="status-pill status-pill--' . $h(preg_replace('/[^a-z_]/', '', $value)) . '">' . $h(str_replace('_', ' ', $value)) . '</span>';

    $dashboard = static function (AdminView $view, array $user) use ($pdo, $storeId, $tabs, $money, $currency): void {
        $metric = static function (PDO $pdo, string $sql, array $params): int {
            $statement = $pdo->prepare($sql);
            $statement->execute($params);
            return (int) $statement->fetchColumn();
        };
        $orders = $metric($pdo, 'SELECT COUNT(*) FROM commerce_orders WHERE store_id = ?', [$storeId]);
        $new = $metric($pdo, 'SELECT COUNT(*) FROM commerce_orders WHERE store_id = ? AND order_status IN ("new","awaiting_files","files_received")', [$storeId]);
        $unpaid = $metric($pdo, 'SELECT COUNT(*) FROM commerce_orders WHERE store_id = ? AND payment_status IN ("unpaid","failed")', [$storeId]);
        $sales = $metric($pdo, 'SELECT COALESCE(SUM(total_minor),0) FROM commerce_orders WHERE store_id = ? AND payment_status = "paid"', [$storeId]);
        $content = $tabs('/admin/commerce') . '<section class="panel system-hero"><div><span class="eyebrow">Commerce</span><h2>Sprzedaż bez szukania po wtyczkach</h2><p>Status płatności i realizacji pozostają rozdzielone. Najpierw obsłuż zamówienia wymagające działania.</p></div><a class="button" href="/admin/commerce/orders">Otwórz zamówienia</a></section><div class="grid"><div class="metric"><span>Wszystkie zamówienia</span><b>' . $orders . '</b></div><div class="metric"><span>Do obsługi</span><b>' . $new . '</b></div><div class="metric"><span>Nieopłacone / błąd</span><b>' . $unpaid . '</b></div><div class="metric"><span>Opłacona sprzedaż</span><b>' . $money($sales, $currency) . '</b></div></div>';
        $view->render('Sklep', $content, $user);
    };

    $orders = static function (AdminView $view, array $user) use ($pdo, $storeId, $tabs, $h, $money, $pill): void {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            if (!Csrf::verify(isset($_POST['_csrf']) ? (string) $_POST['_csrf'] : null)) {
                http_response_code(419);
                $view->render('Zamówienia', $tabs('/admin/commerce/orders') . '<div class="error">Sesja formularza wygasła.</div>', $user);
                return;
            }
            $id = (int) ($_POST['id'] ?? 0);
            $target = OrderStatus::tryFrom((string) ($_POST['order_status'] ?? ''));
            if (!$target) {
                throw new DomainException('Niedozwolona zmiana statusu zamówienia.');
            }
            (new OrderLifecycleService($pdo))->changeOrderStatus(
                $id,
                $storeId,
                $target,
                'admin',
                (int) ($user['id'] ?? 0),
                'manual_admin_change',
            );
            Url::redirect('/admin/commerce/orders?id=' . $id . '&saved=1');
        }
        if (isset($_GET['id'])) {
            $id = (int) $_GET['id'];
            $statement = $pdo->prepare('SELECT * FROM commerce_orders WHERE id = ? AND store_id = ? LIMIT 1');
            $statement->execute([$id, $storeId]);
            $order = $statement->fetch(PDO::FETCH_ASSOC);
            if (!$order) { http_response_code(404); $view->render('Zamówienie', $tabs('/admin/commerce/orders') . '<div class="error">Nie znaleziono zamówienia.</div>', $user); return; }
            $items = $pdo->prepare('SELECT product_name, variant_name, sku, quantity, total_minor, requires_files FROM commerce_order_items WHERE order_id = ? ORDER BY id');
            $items->execute([$id]);
            $rows = '';
            foreach ($items->fetchAll(PDO::FETCH_ASSOC) as $item) {
                $rows .= '<tr><td><b>' . $h($item['product_name']) . '</b><br><small>' . $h($item['variant_name'] ?? '') . ' ' . $h($item['sku'] ?? '') . '</small></td><td>' . (int) $item['quantity'] . '</td><td>' . $money((int) $item['total_minor'], (string) $order['currency']) . '</td><td>' . (!empty($item['requires_files']) ? 'Wymaga pliku' : '—') . '</td></tr>';
            }
            $options = '';
            foreach (OrderStatus::cases() as $status) $options .= '<option value="' . $h($status->value) . '"' . ($status->value === $order['order_status'] ? ' selected' : '') . '>' . $h(str_replace('_', ' ', $status->value)) . '</option>';
            $content = $tabs('/admin/commerce/orders') . (isset($_GET['saved']) ? '<div class="notice">Status zapisano.</div>' : '') . '<section class="panel"><div class="page-editor__head"><div><span class="eyebrow">Zamówienie</span><h2>' . $h($order['order_number']) . '</h2><p>' . $h($order['customer_email']) . ' · ' . $money((int) $order['total_minor'], (string) $order['currency']) . '</p></div><div>' . $pill((string) $order['payment_status']) . ' ' . $pill((string) $order['order_status']) . '</div></div><form method="post" class="filters">' . Csrf::field() . '<input type="hidden" name="id" value="' . $id . '"><label>Status realizacji<select name="order_status">' . $options . '</select></label><button>Zapisz status</button></form><table><thead><tr><th>Produkt</th><th>Ilość</th><th>Razem</th><th>Pliki</th></tr></thead><tbody>' . $rows . '</tbody></table></section>';
            $view->render('Zamówienie ' . $order['order_number'], $content, $user); return;
        }
        $q = trim((string) ($_GET['q'] ?? ''));
        $orderStatus = trim((string) ($_GET['order_status'] ?? ''));
        $paymentStatus = trim((string) ($_GET['payment_status'] ?? ''));
        $sql = 'SELECT id, order_number, created_at, customer_email, total_minor, currency, order_status, payment_status, shipping_method_name FROM commerce_orders WHERE store_id = ?';
        $params = [$storeId];
        if ($q !== '') { $sql .= ' AND (order_number LIKE ? OR customer_email LIKE ?)'; $params[] = '%' . $q . '%'; $params[] = '%' . $q . '%'; }
        if ($orderStatus !== '') { $sql .= ' AND order_status = ?'; $params[] = $orderStatus; }
        if ($paymentStatus !== '') { $sql .= ' AND payment_status = ?'; $params[] = $paymentStatus; }
        $sql .= ' ORDER BY created_at DESC, id DESC LIMIT 100';
        $statement = $pdo->prepare($sql); $statement->execute($params); $rows = '';
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $order) {
            $rows .= '<tr><td><a href="/admin/commerce/orders?id=' . (int) $order['id'] . '"><b>' . $h($order['order_number']) . '</b></a><br><small>' . $h($order['created_at']) . '</small></td><td>' . $h($order['customer_email']) . '</td><td>' . $money((int) $order['total_minor'], (string) $order['currency']) . '</td><td>' . $pill((string) $order['payment_status']) . '</td><td>' . $pill((string) $order['order_status']) . '</td><td>' . $h($order['shipping_method_name'] ?? '') . '</td></tr>';
        }
        $content = $tabs('/admin/commerce/orders') . '<section class="panel list-toolbar"><form class="filters"><label>Szukaj<input name="q" value="' . $h($q) . '" placeholder="numer lub e-mail"></label><label>Realizacja<input name="order_status" value="' . $h($orderStatus) . '"></label><label>Płatność<input name="payment_status" value="' . $h($paymentStatus) . '"></label><button>Filtruj</button></form></section><section class="panel"><table><thead><tr><th>Numer / data</th><th>Klient</th><th>Kwota</th><th>Płatność</th><th>Realizacja</th><th>Dostawa</th></tr></thead><tbody>' . ($rows ?: '<tr><td colspan="6">Brak zamówień.</td></tr>') . '</tbody></table></section>';
        $view->render('Zamówienia', $content, $user);
    };

    $products = static function (AdminView $view, array $user) use ($pdo, $storeId, $currency, $tabs, $h, $money, $pill): void {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            if (!Csrf::verify(isset($_POST['_csrf']) ? (string) $_POST['_csrf'] : null)) { http_response_code(419); return; }
            $id = (int) ($_POST['id'] ?? 0); $status = (string) ($_POST['status'] ?? 'draft');
            if (!in_array($status, ['draft', 'published', 'archived'], true)) throw new DomainException('Nieprawidłowy status produktu.');
            $price = (new DecimalMoneyParser())->parse((string) ($_POST['price'] ?? ''));
            $stock = trim((string) ($_POST['stock_quantity'] ?? ''));
            $pdo->prepare('UPDATE commerce_products SET name = ?, sku = NULLIF(?, ""), status = ?, base_price_minor = ?, stock_quantity = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND store_id = ?')->execute([trim((string) $_POST['name']), trim((string) ($_POST['sku'] ?? '')), $status, $price, $stock === '' ? null : (int) $stock, $id, $storeId]);
            Url::redirect('/admin/commerce/products?id=' . $id . '&saved=1');
        }
        if (isset($_GET['id'])) {
            $statement = $pdo->prepare('SELECT id, name, sku, status, base_price_minor, stock_quantity FROM commerce_products WHERE id = ? AND store_id = ? LIMIT 1'); $statement->execute([(int) $_GET['id'], $storeId]); $product = $statement->fetch(PDO::FETCH_ASSOC);
            if (!$product) { http_response_code(404); return; }
            $statuses = ''; foreach (['draft', 'published', 'archived'] as $status) $statuses .= '<option' . ($product['status'] === $status ? ' selected' : '') . '>' . $h($status) . '</option>';
            $content = $tabs('/admin/commerce/products') . (isset($_GET['saved']) ? '<div class="notice">Produkt zapisano.</div>' : '') . '<section class="panel"><h2>Edytuj produkt</h2><form method="post" class="privacy-settings-grid">' . Csrf::field() . '<input type="hidden" name="id" value="' . (int) $product['id'] . '"><label class="field field--half">Nazwa<input name="name" required value="' . $h($product['name']) . '"></label><label class="field">SKU<input name="sku" value="' . $h($product['sku']) . '"></label><label class="field">Cena brutto<input name="price" inputmode="decimal" value="' . number_format((int) $product['base_price_minor'] / 100, 2, '.', '') . '"></label><label class="field">Stan<input type="number" name="stock_quantity" value="' . $h($product['stock_quantity']) . '"></label><label class="field">Status<select name="status">' . $statuses . '</select></label><button>Zapisz</button></form></section>';
            $view->render('Produkt', $content, $user); return;
        }
        $statement = $pdo->prepare('SELECT id, name, sku, status, base_price_minor, stock_quantity, stock_status FROM commerce_products WHERE store_id = ? ORDER BY name LIMIT 200'); $statement->execute([$storeId]); $rows = '';
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $product) $rows .= '<tr><td><a href="/admin/commerce/products?id=' . (int) $product['id'] . '"><b>' . $h($product['name']) . '</b></a></td><td>' . $h($product['sku']) . '</td><td>' . $money((int) $product['base_price_minor'], $currency) . '</td><td>' . $h($product['stock_quantity'] ?? '—') . '</td><td>' . $pill((string) $product['status']) . '</td></tr>';
        $view->render('Produkty', $tabs('/admin/commerce/products') . '<section class="panel"><table><thead><tr><th>Produkt</th><th>SKU</th><th>Cena</th><th>Stan</th><th>Status</th></tr></thead><tbody>' . $rows . '</tbody></table></section>', $user);
    };

    $customers = static function (AdminView $view, array $user) use ($pdo, $storeId, $tabs, $h): void {
        $statement = $pdo->prepare('SELECT c.id, c.email, c.first_name, c.last_name, c.phone, c.company, c.status, COUNT(o.id) AS orders_count FROM commerce_customers c LEFT JOIN commerce_orders o ON o.customer_id = c.id WHERE c.store_id = ? GROUP BY c.id ORDER BY c.created_at DESC LIMIT 200'); $statement->execute([$storeId]); $rows = '';
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $customer) $rows .= '<tr><td><b>' . $h(trim((string) $customer['first_name'] . ' ' . (string) $customer['last_name'])) . '</b><br><small>' . $h($customer['company']) . '</small></td><td>' . $h($customer['email']) . '</td><td>' . $h($customer['phone']) . '</td><td>' . (int) $customer['orders_count'] . '</td><td>' . $h($customer['status']) . '</td></tr>';
        $view->render('Klienci', $tabs('/admin/commerce/customers') . '<section class="panel"><table><thead><tr><th>Klient</th><th>E-mail</th><th>Telefon</th><th>Zamówienia</th><th>Status</th></tr></thead><tbody>' . ($rows ?: '<tr><td colspan="5">Brak klientów.</td></tr>') . '</tbody></table></section>', $user);
    };

    return [
        'nav' => ['/admin/commerce' => 'Sklep'],
        'routes' => ['/admin/commerce' => $dashboard, '/admin/commerce/orders' => $orders, '/admin/commerce/products' => $products, '/admin/commerce/customers' => $customers],
        'route_permissions' => [
            '/admin/commerce' => ['GET' => 'manage_orders'],
            '/admin/commerce/orders' => ['GET' => 'manage_orders', 'POST' => 'manage_orders'],
            '/admin/commerce/products' => ['GET' => 'manage_products', 'POST' => 'manage_products'],
            '/admin/commerce/customers' => ['GET' => 'manage_customers'],
        ],
    ];
};
