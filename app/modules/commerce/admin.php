<?php

declare(strict_types=1);

use Reklamova\Cms\Admin\AdminView;
use Reklamova\Cms\Auth\Csrf;
use Reklamova\Cms\Commerce\Admin\CommerceConfigurationController;
use Reklamova\Cms\Commerce\Admin\CommerceProductAdminController;
use Reklamova\Cms\Commerce\Orders\OrderStatus;
use Reklamova\Cms\Commerce\Orders\OrderLifecycleService;
use Reklamova\Cms\Commerce\Uploads\OrderFileService;
use Reklamova\Cms\Commerce\Uploads\PdoOrderFileRepository;
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
        $items = ['/admin/commerce' => 'Pulpit', '/admin/commerce/orders' => 'Zamówienia', '/admin/commerce/products' => 'Produkty', '/admin/commerce/categories' => 'Kategorie', '/admin/commerce/customers' => 'Klienci', '/admin/commerce/shipping' => 'Dostawy', '/admin/commerce/payments' => 'Płatności', '/admin/commerce/coupons' => 'Rabaty'];
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
            $fileStatement = $pdo->prepare('SELECT f.id, f.original_name, f.mime_type, f.size_bytes, f.status, f.scan_status, f.created_at FROM commerce_order_files f WHERE f.order_id = ? AND f.status <> "deleted" ORDER BY f.created_at, f.id');
            $fileStatement->execute([$id]);
            $fileRows = '';
            foreach ($fileStatement->fetchAll(PDO::FETCH_ASSOC) as $file) {
                $fileStatusOptions = '';
                foreach (['uploaded' => 'Przesłany', 'accepted' => 'Zaakceptowany', 'rejected' => 'Odrzucony'] as $value => $label) {
                    $fileStatusOptions .= '<option value="' . $value . '"' . ($value === $file['status'] ? ' selected' : '') . '>' . $label . '</option>';
                }
                $fileRows .= '<tr><td><a href="/admin/commerce/order-file?id=' . (int) $file['id'] . '"><b>' . $h($file['original_name']) . '</b></a><br><small>' . $h($file['mime_type']) . '</small></td><td>' . number_format((int) $file['size_bytes'] / 1048576, 2, ',', ' ') . ' MB</td><td><form method="post" action="/admin/commerce/order-file-status" class="filters">' . Csrf::field() . '<input type="hidden" name="file_id" value="' . (int) $file['id'] . '"><input type="hidden" name="order_id" value="' . $id . '"><select name="status">' . $fileStatusOptions . '</select><button>Zapisz</button></form></td><td>' . $pill((string) $file['scan_status']) . '</td><td>' . $h($file['created_at']) . '</td></tr>';
            }
            $attemptStatement = $pdo->prepare('SELECT provider, provider_transaction_id, status, amount_minor, currency, created_at, updated_at FROM commerce_payment_attempts WHERE order_id = ? ORDER BY id DESC');
            $attemptStatement->execute([$id]);
            $attemptRows = '';
            foreach ($attemptStatement->fetchAll(PDO::FETCH_ASSOC) as $attempt) {
                $attemptRows .= '<tr><td>' . $h($attempt['provider']) . '</td><td>' . $h($attempt['provider_transaction_id'] ?: '—') . '</td><td>' . $pill((string) $attempt['status']) . '</td><td>' . $money((int) $attempt['amount_minor'], (string) $attempt['currency']) . '</td><td>' . $h($attempt['updated_at'] ?: $attempt['created_at']) . '</td></tr>';
            }
            $shipmentStatement = $pdo->prepare('SELECT id, provider, status, tracking_number, tracking_url, shipped_at, delivered_at, created_at FROM commerce_order_shipments WHERE order_id=? ORDER BY id DESC');
            $shipmentStatement->execute([$id]);
            $shipments = $shipmentStatement->fetchAll(PDO::FETCH_ASSOC);
            $shipmentRows = '';
            foreach ($shipments as $shipment) {
                $trackingUrl = (string) ($shipment['tracking_url'] ?? '');
                $safeTrackingUrl = filter_var($trackingUrl, FILTER_VALIDATE_URL) !== false
                    && in_array(strtolower((string) parse_url($trackingUrl, PHP_URL_SCHEME)), ['http', 'https'], true);
                $tracking = $safeTrackingUrl
                    ? '<a href="' . $h($shipment['tracking_url']) . '" target="_blank" rel="noopener">' . $h($shipment['tracking_number'] ?: 'Śledź') . '</a>'
                    : $h($shipment['tracking_number'] ?: '—');
                $shipmentRows .= '<tr><td>' . $h($shipment['provider'] ?: '—') . '</td><td>' . $tracking . '</td><td>' . $pill((string) $shipment['status']) . '</td><td>' . $h($shipment['shipped_at'] ?: $shipment['created_at']) . '</td><td><a href="/admin/commerce/orders?id=' . $id . '&shipment=' . (int) $shipment['id'] . '">Edytuj</a></td></tr>';
            }
            $shipmentEdit = null;
            $shipmentId = (int) ($_GET['shipment'] ?? 0);
            foreach ($shipments as $shipment) {
                if ((int) $shipment['id'] === $shipmentId) {
                    $shipmentEdit = $shipment;
                    break;
                }
            }
            $shipmentEdit ??= ['id' => 0, 'provider' => '', 'status' => 'pending', 'tracking_number' => '', 'tracking_url' => ''];
            $shipmentStatusOptions = '';
            foreach (['pending' => 'Oczekuje', 'ready' => 'Gotowa', 'shipped' => 'Wysłana', 'delivered' => 'Doręczona', 'failed' => 'Problem'] as $value => $label) {
                $shipmentStatusOptions .= '<option value="' . $value . '"' . ($value === $shipmentEdit['status'] ? ' selected' : '') . '>' . $label . '</option>';
            }
            $options = '';
            foreach (OrderStatus::cases() as $status) $options .= '<option value="' . $h($status->value) . '"' . ($status->value === $order['order_status'] ? ' selected' : '') . '>' . $h(str_replace('_', ' ', $status->value)) . '</option>';
            $notice = isset($_GET['saved']) ? '<div class="notice">Status zapisano.</div>' : '';
            $notice .= isset($_GET['shipment_saved']) ? '<div class="notice">Dane wysyłki zapisano.</div>' : '';
            $notice .= isset($_GET['file_saved']) ? '<div class="notice">Status pliku zapisano.</div>' : '';
            $content = $tabs('/admin/commerce/orders') . $notice . '<section class="panel"><div class="page-editor__head"><div><span class="eyebrow">Zamówienie</span><h2>' . $h($order['order_number']) . '</h2><p>' . $h($order['customer_email']) . ' · ' . $money((int) $order['total_minor'], (string) $order['currency']) . '</p></div><div>' . $pill((string) $order['payment_status']) . ' ' . $pill((string) $order['order_status']) . '</div></div><form method="post" class="filters">' . Csrf::field() . '<input type="hidden" name="id" value="' . $id . '"><label>Status realizacji<select name="order_status">' . $options . '</select></label><button>Zapisz status</button></form><h3>Pozycje</h3><table><thead><tr><th>Produkt</th><th>Ilość</th><th>Razem</th><th>Pliki</th></tr></thead><tbody>' . $rows . '</tbody></table></section>'
                . '<section class="panel"><h2>Pliki do druku</h2><table><thead><tr><th>Plik</th><th>Rozmiar</th><th>Status</th><th>Skan</th><th>Dodano</th></tr></thead><tbody>' . ($fileRows ?: '<tr><td colspan="5">Brak przesłanych plików.</td></tr>') . '</tbody></table></section>'
                . '<section class="panel"><h2>Wysyłki</h2><table><thead><tr><th>Operator</th><th>Tracking</th><th>Status</th><th>Data</th><th></th></tr></thead><tbody>' . ($shipmentRows ?: '<tr><td colspan="5">Brak zapisanej wysyłki.</td></tr>') . '</tbody></table><form method="post" action="/admin/commerce/order-shipment" class="privacy-settings-grid">' . Csrf::field() . '<input type="hidden" name="order_id" value="' . $id . '"><input type="hidden" name="shipment_id" value="' . (int) $shipmentEdit['id'] . '"><label class="field">Operator<input name="provider" maxlength="120" value="' . $h($shipmentEdit['provider']) . '" placeholder="np. DPD"></label><label class="field">Numer przesyłki<input name="tracking_number" maxlength="190" value="' . $h($shipmentEdit['tracking_number']) . '"></label><label class="field field--half">Link śledzenia<input type="url" name="tracking_url" maxlength="1000" value="' . $h($shipmentEdit['tracking_url']) . '"></label><label class="field">Status<select name="status">' . $shipmentStatusOptions . '</select></label><button>Zapisz wysyłkę</button></form></section>'
                . '<section class="panel"><h2>Próby płatności</h2><table><thead><tr><th>Operator</th><th>Identyfikator</th><th>Status</th><th>Kwota</th><th>Aktualizacja</th></tr></thead><tbody>' . ($attemptRows ?: '<tr><td colspan="5">Brak prób płatności.</td></tr>') . '</tbody></table></section>';
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

    $orderShipment = static function () use ($pdo, $storeId): void {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || !Csrf::verify(isset($_POST['_csrf']) ? (string) $_POST['_csrf'] : null)) {
            http_response_code(419);
            echo 'Sesja formularza wygasła.';
            return;
        }
        $orderId = (int) ($_POST['order_id'] ?? 0);
        $order = $pdo->prepare('SELECT id FROM commerce_orders WHERE id=? AND store_id=? LIMIT 1');
        $order->execute([$orderId, $storeId]);
        if (!$order->fetchColumn()) {
            http_response_code(404);
            echo 'Nie znaleziono zamówienia.';
            return;
        }
        $provider = trim((string) ($_POST['provider'] ?? ''));
        $trackingNumber = trim((string) ($_POST['tracking_number'] ?? ''));
        $trackingUrl = trim((string) ($_POST['tracking_url'] ?? ''));
        $status = (string) ($_POST['status'] ?? 'pending');
        if (mb_strlen($provider) > 120 || mb_strlen($trackingNumber) > 190 || mb_strlen($trackingUrl) > 1000) {
            throw new DomainException('Dane wysyłki przekraczają dozwolony limit.');
        }
        if (!in_array($status, ['pending', 'ready', 'shipped', 'delivered', 'failed'], true)) {
            throw new DomainException('Nieprawidłowy status wysyłki.');
        }
        if ($trackingUrl !== '' && (filter_var($trackingUrl, FILTER_VALIDATE_URL) === false || !in_array(strtolower((string) parse_url($trackingUrl, PHP_URL_SCHEME)), ['http', 'https'], true))) {
            throw new DomainException('Link śledzenia musi używać HTTP lub HTTPS.');
        }
        $shipmentId = (int) ($_POST['shipment_id'] ?? 0);
        $timestamps = [
            $status === 'shipped' ? date('Y-m-d H:i:s') : null,
            $status === 'delivered' ? date('Y-m-d H:i:s') : null,
        ];
        if ($shipmentId > 0) {
            $statement = $pdo->prepare(
                'UPDATE commerce_order_shipments s
                 INNER JOIN commerce_orders o ON o.id=s.order_id
                 SET s.provider=NULLIF(?,""), s.tracking_number=NULLIF(?,""), s.tracking_url=NULLIF(?,""), s.status=?,
                     s.shipped_at=IF(? IS NOT NULL, COALESCE(s.shipped_at, ?), s.shipped_at),
                     s.delivered_at=IF(? IS NOT NULL, COALESCE(s.delivered_at, ?), s.delivered_at),
                     s.updated_at=CURRENT_TIMESTAMP
                 WHERE s.id=? AND s.order_id=? AND o.store_id=?'
            );
            $statement->execute([$provider, $trackingNumber, $trackingUrl, $status, $timestamps[0], $timestamps[0], $timestamps[1], $timestamps[1], $shipmentId, $orderId, $storeId]);
            if ($statement->rowCount() === 0) {
                $exists = $pdo->prepare('SELECT 1 FROM commerce_order_shipments s INNER JOIN commerce_orders o ON o.id=s.order_id WHERE s.id=? AND s.order_id=? AND o.store_id=?');
                $exists->execute([$shipmentId, $orderId, $storeId]);
                if (!$exists->fetchColumn()) {
                    throw new DomainException('Nie znaleziono wysyłki dla tego zamówienia.');
                }
            }
        } else {
            $statement = $pdo->prepare('INSERT INTO commerce_order_shipments (order_id,provider,status,tracking_number,tracking_url,shipped_at,delivered_at) VALUES (?,NULLIF(?,""),?,NULLIF(?,""),NULLIF(?,""),?,?)');
            $statement->execute([$orderId, $provider, $status, $trackingNumber, $trackingUrl, $timestamps[0], $timestamps[1]]);
        }
        Url::redirect('/admin/commerce/orders?id=' . $orderId . '&shipment_saved=1');
    };

    $orderFileStatus = static function () use ($pdo, $storeId): void {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || !Csrf::verify(isset($_POST['_csrf']) ? (string) $_POST['_csrf'] : null)) {
            http_response_code(419);
            echo 'Sesja formularza wygasła.';
            return;
        }
        $fileId = (int) ($_POST['file_id'] ?? 0);
        $orderId = (int) ($_POST['order_id'] ?? 0);
        $status = (string) ($_POST['status'] ?? '');
        if (!in_array($status, ['uploaded', 'accepted', 'rejected'], true)) {
            throw new DomainException('Nieprawidłowy status pliku.');
        }
        $statement = $pdo->prepare(
            'UPDATE commerce_order_files f
             INNER JOIN commerce_orders o ON o.id=f.order_id
             SET f.status=?, f.updated_at=CURRENT_TIMESTAMP
             WHERE f.id=? AND f.order_id=? AND o.store_id=? AND f.status<>"deleted"'
        );
        $statement->execute([$status, $fileId, $orderId, $storeId]);
        if ($statement->rowCount() === 0) {
            $exists = $pdo->prepare('SELECT 1 FROM commerce_order_files f INNER JOIN commerce_orders o ON o.id=f.order_id WHERE f.id=? AND f.order_id=? AND o.store_id=? AND f.status<>"deleted"');
            $exists->execute([$fileId, $orderId, $storeId]);
            if (!$exists->fetchColumn()) {
                throw new DomainException('Nie znaleziono pliku dla tego zamówienia.');
            }
        }
        Url::redirect('/admin/commerce/orders?id=' . $orderId . '&file_saved=1');
    };

    $customers = static function (AdminView $view, array $user) use ($pdo, $storeId, $tabs, $h, $money, $pill): void {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            if (!Csrf::verify(isset($_POST['_csrf']) ? (string) $_POST['_csrf'] : null)) {
                http_response_code(419);
                $view->render('Klienci', $tabs('/admin/commerce/customers') . '<div class="error">Sesja formularza wygasła.</div>', $user);
                return;
            }
            $id = (int) ($_POST['id'] ?? 0);
            $email = mb_strtolower(trim((string) ($_POST['email'] ?? '')), 'UTF-8');
            $status = (string) ($_POST['status'] ?? 'active');
            $fields = [
                'first_name' => [120, trim((string) ($_POST['first_name'] ?? ''))],
                'last_name' => [120, trim((string) ($_POST['last_name'] ?? ''))],
                'phone' => [80, trim((string) ($_POST['phone'] ?? ''))],
                'company' => [190, trim((string) ($_POST['company'] ?? ''))],
                'tax_id' => [40, trim((string) ($_POST['tax_id'] ?? ''))],
            ];
            if ($id <= 0 || filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($email) > 190 || !in_array($status, ['active', 'disabled'], true)) {
                throw new DomainException('Nieprawidłowe dane klienta.');
            }
            foreach ($fields as [$max, $value]) {
                if (mb_strlen($value) > $max) {
                    throw new DomainException('Dane klienta przekraczają dozwolony limit.');
                }
            }
            $duplicate = $pdo->prepare('SELECT 1 FROM commerce_customers WHERE store_id=? AND email=? AND id<>? LIMIT 1');
            $duplicate->execute([$storeId, $email, $id]);
            if ($duplicate->fetchColumn()) {
                throw new DomainException('Ten adres e-mail jest już przypisany do innego klienta.');
            }
            $statement = $pdo->prepare('UPDATE commerce_customers SET email=?,first_name=NULLIF(?,""),last_name=NULLIF(?,""),phone=NULLIF(?,""),company=NULLIF(?,""),tax_id=NULLIF(?,""),status=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND store_id=?');
            $statement->execute([$email, $fields['first_name'][1], $fields['last_name'][1], $fields['phone'][1], $fields['company'][1], $fields['tax_id'][1], $status, $id, $storeId]);
            if ($statement->rowCount() === 0) {
                $exists = $pdo->prepare('SELECT 1 FROM commerce_customers WHERE id=? AND store_id=?');
                $exists->execute([$id, $storeId]);
                if (!$exists->fetchColumn()) {
                    throw new DomainException('Nie znaleziono klienta.');
                }
            }
            Url::redirect('/admin/commerce/customers?id=' . $id . '&saved=1');
        }
        if (isset($_GET['id'])) {
            $id = (int) $_GET['id'];
            $statement = $pdo->prepare('SELECT * FROM commerce_customers WHERE id=? AND store_id=? LIMIT 1');
            $statement->execute([$id, $storeId]);
            $customer = $statement->fetch(PDO::FETCH_ASSOC);
            if (!$customer) {
                http_response_code(404);
                $view->render('Klient', $tabs('/admin/commerce/customers') . '<div class="error">Nie znaleziono klienta.</div>', $user);
                return;
            }
            $addresses = $pdo->prepare('SELECT type,label,first_name,last_name,company,tax_id,address_line1,address_line2,postal_code,city,country_code,phone,is_default FROM commerce_customer_addresses WHERE customer_id=? ORDER BY type,is_default DESC,id');
            $addresses->execute([$id]);
            $addressCards = '';
            foreach ($addresses->fetchAll(PDO::FETCH_ASSOC) as $address) {
                $addressCards .= '<article class="metric"><span>' . $h(($address['label'] ?: $address['type']) . (!empty($address['is_default']) ? ' · domyślny' : '')) . '</span><b>' . $h(trim((string) $address['first_name'] . ' ' . (string) $address['last_name'])) . '</b><small>' . $h($address['company']) . '<br>' . $h($address['address_line1']) . ($address['address_line2'] ? '<br>' . $h($address['address_line2']) : '') . '<br>' . $h($address['postal_code'] . ' ' . $address['city'] . ', ' . $address['country_code']) . '<br>' . $h($address['phone']) . '</small></article>';
            }
            $orders = $pdo->prepare('SELECT id,order_number,created_at,total_minor,currency,payment_status,order_status FROM commerce_orders WHERE customer_id=? AND store_id=? ORDER BY created_at DESC,id DESC LIMIT 100');
            $orders->execute([$id, $storeId]);
            $orderRows = '';
            foreach ($orders->fetchAll(PDO::FETCH_ASSOC) as $order) {
                $orderRows .= '<tr><td><a href="/admin/commerce/orders?id=' . (int) $order['id'] . '"><b>' . $h($order['order_number']) . '</b></a><br><small>' . $h($order['created_at']) . '</small></td><td>' . $money((int) $order['total_minor'], (string) $order['currency']) . '</td><td>' . $pill((string) $order['payment_status']) . '</td><td>' . $pill((string) $order['order_status']) . '</td></tr>';
            }
            $statusOptions = '';
            foreach (['active' => 'Aktywny', 'disabled' => 'Wyłączony'] as $value => $label) {
                $statusOptions .= '<option value="' . $value . '"' . ($value === $customer['status'] ? ' selected' : '') . '>' . $label . '</option>';
            }
            $content = $tabs('/admin/commerce/customers') . (isset($_GET['saved']) ? '<div class="notice">Dane klienta zapisano.</div>' : '')
                . '<section class="panel"><div class="page-editor__head"><div><span class="eyebrow">Klient</span><h2>' . $h(trim((string) $customer['first_name'] . ' ' . (string) $customer['last_name']) ?: $customer['email']) . '</h2><p>Utworzono ' . $h($customer['created_at']) . ' · ostatnie logowanie ' . $h($customer['last_login_at'] ?: '—') . '</p></div>' . $pill((string) $customer['status']) . '</div><form method="post" class="privacy-settings-grid">' . Csrf::field() . '<input type="hidden" name="id" value="' . $id . '"><label class="field field--half">E-mail<input type="email" name="email" maxlength="190" required value="' . $h($customer['email']) . '"></label><label class="field">Imię<input name="first_name" maxlength="120" value="' . $h($customer['first_name']) . '"></label><label class="field">Nazwisko<input name="last_name" maxlength="120" value="' . $h($customer['last_name']) . '"></label><label class="field">Telefon<input name="phone" maxlength="80" value="' . $h($customer['phone']) . '"></label><label class="field">Firma<input name="company" maxlength="190" value="' . $h($customer['company']) . '"></label><label class="field">NIP<input name="tax_id" maxlength="40" value="' . $h($customer['tax_id']) . '"></label><label class="field">Status<select name="status">' . $statusOptions . '</select></label><button>Zapisz klienta</button></form></section>'
                . '<section class="panel"><h2>Adresy</h2><div class="grid">' . ($addressCards ?: '<p>Brak zapisanych adresów.</p>') . '</div></section>'
                . '<section class="panel"><h2>Zamówienia klienta</h2><table><thead><tr><th>Numer / data</th><th>Kwota</th><th>Płatność</th><th>Realizacja</th></tr></thead><tbody>' . ($orderRows ?: '<tr><td colspan="4">Brak zamówień.</td></tr>') . '</tbody></table></section>';
            $view->render('Klient ' . $customer['email'], $content, $user);
            return;
        }
        $statement = $pdo->prepare('SELECT c.id, c.email, c.first_name, c.last_name, c.phone, c.company, c.status, COUNT(o.id) AS orders_count FROM commerce_customers c LEFT JOIN commerce_orders o ON o.customer_id = c.id WHERE c.store_id = ? GROUP BY c.id ORDER BY c.created_at DESC LIMIT 200');
        $statement->execute([$storeId]);
        $rows = '';
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $customer) {
            $rows .= '<tr><td><a href="/admin/commerce/customers?id=' . (int) $customer['id'] . '"><b>' . $h(trim((string) $customer['first_name'] . ' ' . (string) $customer['last_name']) ?: $customer['email']) . '</b></a><br><small>' . $h($customer['company']) . '</small></td><td>' . $h($customer['email']) . '</td><td>' . $h($customer['phone']) . '</td><td>' . (int) $customer['orders_count'] . '</td><td>' . $pill((string) $customer['status']) . '</td></tr>';
        }
        $view->render('Klienci', $tabs('/admin/commerce/customers') . '<section class="panel"><table><thead><tr><th>Klient</th><th>E-mail</th><th>Telefon</th><th>Zamówienia</th><th>Status</th></tr></thead><tbody>' . ($rows ?: '<tr><td colspan="5">Brak klientów.</td></tr>') . '</tbody></table></section>', $user);
    };

    $orderFile = static function () use ($pdo, $storeId, $container): void {
        $service = new OrderFileService(
            new PdoOrderFileRepository($pdo),
            rtrim((string) $container['storage_path'], '/\\') . '/order-files',
        );
        $file = $service->downloadForAdmin((int) ($_GET['id'] ?? 0), $storeId);
        if ($file === null) {
            http_response_code(404);
            echo 'Nie znaleziono pliku.';
            return;
        }
        header('Content-Type: ' . $file['mime']);
        header('Content-Length: ' . $file['size']);
        header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode(str_replace(["\r", "\n"], '', $file['name'])));
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        readfile($file['path']);
    };

    $configuration = new CommerceConfigurationController($pdo, $storeId, $currency, $tabs);
    $productAdmin = new CommerceProductAdminController($pdo, $storeId, $currency, $tabs);

    return [
        'nav' => ['/admin/commerce' => 'Sklep'],
        'routes' => ['/admin/commerce' => $dashboard, '/admin/commerce/orders' => $orders, '/admin/commerce/order-shipment' => $orderShipment, '/admin/commerce/products' => [$productAdmin, 'handle'], '/admin/commerce/categories' => [$configuration, 'categories'], '/admin/commerce/customers' => $customers, '/admin/commerce/shipping' => [$configuration, 'shipping'], '/admin/commerce/payments' => [$configuration, 'payments'], '/admin/commerce/coupons' => [$configuration, 'coupons'], '/admin/commerce/order-file' => $orderFile, '/admin/commerce/order-file-status' => $orderFileStatus],
        'route_permissions' => [
            '/admin/commerce' => ['GET' => 'manage_orders'],
            '/admin/commerce/orders' => ['GET' => 'manage_orders', 'POST' => 'manage_orders'],
            '/admin/commerce/order-shipment' => ['POST' => 'manage_shipping'],
            '/admin/commerce/products' => ['GET' => 'manage_products', 'POST' => 'manage_products'],
            '/admin/commerce/categories' => ['GET' => 'manage_product_categories', 'POST' => 'manage_product_categories'],
            '/admin/commerce/customers' => ['GET' => 'manage_customers', 'POST' => 'manage_customers'],
            '/admin/commerce/shipping' => ['GET' => 'manage_shipping', 'POST' => 'manage_shipping'],
            '/admin/commerce/payments' => ['GET' => 'manage_payments', 'POST' => 'manage_payments'],
            '/admin/commerce/coupons' => ['GET' => 'manage_discounts', 'POST' => 'manage_discounts'],
            '/admin/commerce/order-file' => ['GET' => 'manage_order_files'],
            '/admin/commerce/order-file-status' => ['POST' => 'manage_order_files'],
        ],
    ];
};
