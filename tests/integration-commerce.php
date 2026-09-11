<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Reklamova\Cms\Commerce\Checkout\CheckoutData;
use Reklamova\Cms\Commerce\Checkout\CheckoutService;
use Reklamova\Cms\Commerce\Checkout\CustomerAddress;
use Reklamova\Cms\Commerce\Checkout\PdoCheckoutStore;
use Reklamova\Cms\Commerce\Cart\CartService;
use Reklamova\Cms\Commerce\Analytics\AnalyticsEventRepository;
use Reklamova\Cms\Commerce\Cart\CartViewRepository;
use Reklamova\Cms\Commerce\Cart\PdoCartRepository;
use Reklamova\Cms\Commerce\Catalog\StorefrontRepository;
use Reklamova\Cms\Commerce\Customers\CustomerAccountRepository;
use Reklamova\Cms\Commerce\Customers\CustomerAuthService;
use Reklamova\Cms\Commerce\Payments\PaymentInitiationService;
use Reklamova\Cms\Commerce\Payments\PaymentNotification;
use Reklamova\Cms\Commerce\Payments\PaymentNotificationProcessor;
use Reklamova\Cms\Commerce\Payments\PaymentProviderInterface;
use Reklamova\Cms\Commerce\Payments\PaymentRedirect;
use Reklamova\Cms\Commerce\Payments\PaymentRequest;
use Reklamova\Cms\Commerce\Payments\PdoPaymentInitiationStore;
use Reklamova\Cms\Commerce\Payments\PdoPaymentNotificationStore;
use Reklamova\Cms\Commerce\Shared\Money;
use Reklamova\Cms\Commerce\Import\CommerceImportRepository;
use Reklamova\Cms\Commerce\Import\WordPressImporter;
use Reklamova\Cms\Commerce\Orders\OrderAccessRepository;
use Reklamova\Cms\Commerce\Orders\OrderLifecycleService;
use Reklamova\Cms\Commerce\Orders\OrderStatus;
use Reklamova\Cms\Commerce\Notifications\NotificationOutbox;
use Reklamova\Cms\Commerce\Notifications\NotificationWorker;
use Reklamova\Cms\Commerce\Uploads\OrderFileService;
use Reklamova\Cms\Commerce\Uploads\PdoOrderFileRepository;
use Reklamova\Cms\Database\ConnectionFactory;
use Reklamova\Cms\Database\Migrator;
use Reklamova\Cms\Modules\ModuleManager;
use Reklamova\Cms\Support\EmailSenderInterface;

final class IntegrationPaymentProvider implements PaymentProviderInterface
{
    public function name(): string
    {
        return 'integration_pay';
    }

    public function initiate(PaymentRequest $request): PaymentRedirect
    {
        return new PaymentRedirect('integration-transaction', 'https://pay.example.com/integration', 'new');
    }

    public function parseNotification(string $rawBody, array $headers): PaymentNotification
    {
        throw new BadMethodCallException('Not used in this integration test.');
    }

    public function query(string $providerTransactionId): PaymentNotification
    {
        throw new BadMethodCallException('Not used in this integration test.');
    }

    public function cancel(string $providerTransactionId): void
    {
    }
}

final class IntegrationEmailSender implements EmailSenderInterface
{
    /** @var array<int, array{to: string, subject: string, message: string}> */
    public array $messages = [];

    public function send(string $to, string $subject, string $message): bool
    {
        $this->messages[] = compact('to', 'subject', 'message');

        return true;
    }
}

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

(new Migrator($container))->runCoreMigrations();
$pdo = (new ConnectionFactory($container))->make();
(new ModuleManager($container))->setEnabled($pdo, 'commerce', true);
(new Migrator($container))->runActiveModuleMigrations();
(new Migrator($container))->runCoreMigrations();
(new Migrator($container))->runActiveModuleMigrations();
$adminExtensions = (new ModuleManager($container))->adminExtensions($pdo);
$assert(isset($adminExtensions['routes']['/admin/commerce/orders']), 'Commerce admin order route is missing.');
$assert(
    ($adminExtensions['route_permissions']['/admin/commerce/orders']['POST'] ?? null) === 'manage_orders',
    'Commerce admin order permission is wrong.',
);

$columns = $pdo->query('SHOW COLUMNS FROM commerce_orders')->fetchAll(PDO::FETCH_COLUMN);
$assert(in_array('checkout_key', $columns, true), 'Checkout idempotency migration did not run.');
$assert(in_array('consents_json', $columns, true), 'Checkout consent migration did not run.');
$paymentTable = $pdo->query("SHOW TABLES LIKE 'commerce_payment_methods'")->fetchColumn();
$assert($paymentTable === 'commerce_payment_methods', 'Payment methods migration did not run.');
$analyticsTable = $pdo->query("SHOW TABLES LIKE 'commerce_analytics_events'")->fetchColumn();
$assert($analyticsTable === 'commerce_analytics_events', 'Analytics events migration did not run.');
$customerTokensTable = $pdo->query("SHOW TABLES LIKE 'commerce_customer_tokens'")->fetchColumn();
$assert($customerTokensTable === 'commerce_customer_tokens', 'Customer authentication migration did not run.');

$pdo->exec("INSERT INTO commerce_stores (code, name, currency) VALUES ('drukarnia', 'Drukarnia', 'PLN')");
$storeId = (int) $pdo->lastInsertId();
$customerInsert = $pdo->prepare(
    'INSERT INTO commerce_customers
        (store_id, email, first_name, last_name, password_reset_required)
     VALUES (?, "buyer@example.com", "Jan", "Kowalski", 1)'
);
$customerInsert->execute([$storeId]);
$customerId = (int) $pdo->lastInsertId();
$customerAuth = new CustomerAuthService($pdo, 'drukarnia', 'integration-auth-pepper-42');
$passwordToken = $customerAuth->issuePasswordToken('buyer@example.com', '127.0.0.1');
$assert($passwordToken !== null && $passwordToken['purpose'] === 'activate', 'Migrated account activation token was not issued.');
$notificationOutbox = new NotificationOutbox($pdo);
$notificationOutbox->enqueueCustomerPasswordLink(
    $customerId,
    (string) $passwordToken['purpose'],
    (string) $passwordToken['token'],
);
$emailSender = new IntegrationEmailSender();
$notificationWorker = new NotificationWorker($pdo, $emailSender, 'https://shop.example.com', 'Drukarnia');
$assert($notificationWorker->processNext() === 'sent', 'Customer activation notification was not sent.');
$assert(str_contains($emailSender->messages[0]['message'], '/moje-konto/haslo?token='), 'Activation email lacks its one-time link.');
$storedTokenPayload = (string) $pdo->query('SELECT payload_json FROM commerce_outbox WHERE event_type = "commerce.customer.activate_requested"')->fetchColumn();
$assert($storedTokenPayload === '{}', 'Delivered activation token was not removed from the outbox.');
$activatedCustomer = $customerAuth->completePasswordToken($passwordToken['token'], 'BezpieczneHaslo2026');
$assert($activatedCustomer['id'] === $customerId, 'Activation token did not activate the expected customer.');
try {
    $customerAuth->completePasswordToken($passwordToken['token'], 'InneBezpieczneHaslo2026');
    throw new RuntimeException('Customer activation token was reusable.');
} catch (DomainException) {
}
try {
    $customerAuth->authenticate('buyer@example.com', 'zle-haslo', '127.0.0.2');
    throw new RuntimeException('Wrong customer password was accepted.');
} catch (DomainException) {
}
$authenticatedCustomer = $customerAuth->authenticate('BUYER@example.com', 'BezpieczneHaslo2026', '127.0.0.2');
$assert($authenticatedCustomer['id'] === $customerId, 'Customer login failed after activation.');
$pdo->exec(
    "INSERT INTO commerce_tax_classes (store_id, code, name, rate_bps)
     VALUES ({$storeId}, 'standard', '23%', 2300)"
);
$taxId = (int) $pdo->lastInsertId();
$pdo->exec(
    "INSERT INTO commerce_products
        (store_id, tax_class_id, type, name, slug, sku, status, base_price_minor, currency,
         track_stock, stock_quantity, stock_status)
     VALUES ({$storeId}, {$taxId}, 'simple', 'Baner', 'baner', 'BAN-1', 'published', 12300, 'PLN', 1, 10, 'in_stock')"
);
$productId = (int) $pdo->lastInsertId();
$category = $pdo->prepare(
    'INSERT INTO commerce_categories
        (store_id, name, slug, full_path, status, sort_order)
     VALUES (?, "Banery", "banery", "banery", "published", 10)'
);
$category->execute([$storeId]);
$categoryId = (int) $pdo->lastInsertId();
$pdo->exec(
    "INSERT INTO commerce_product_categories (product_id, category_id, is_primary, sort_order)
     VALUES ({$productId}, {$categoryId}, 1, 10)"
);
$variant = $pdo->prepare(
    'INSERT INTO commerce_product_variants
        (store_id, product_id, sku, name, price_minor, stock_status, attributes_json, sort_order)
     VALUES (?, ?, "BAN-1-100", "100 × 100 cm", 12300, "in_stock", ?, 10)'
);
$variant->execute([$storeId, $productId, '{"format":"100x100"}']);
$option = $pdo->prepare(
    'INSERT INTO commerce_product_options
        (product_id, code, name, input_type, required, affects_price, sort_order)
     VALUES (?, "finish", "Wykończenie", "select", 0, 1, 10)'
);
$option->execute([$productId]);
$optionId = (int) $pdo->lastInsertId();
$pdo->exec(
    "INSERT INTO commerce_product_option_values (option_id, code, label, price_delta_minor, sort_order)
     VALUES ({$optionId}, 'eyelets', 'Oczkowanie', 500, 10)"
);
$fileRequirement = $pdo->prepare(
    'INSERT INTO commerce_product_file_requirements
        (product_id, label, allowed_extensions_json, allowed_mime_types_json, max_bytes)
     VALUES (?, "Plik do druku", ?, ?, 10485760)'
);
$fileRequirement->execute([$productId, '["pdf"]', '["application/pdf"]']);

$storefront = new StorefrontRepository($pdo, 'drukarnia');
$assert($storefront->store()['currency'] === 'PLN', 'Storefront did not load the active store.');
$assert($storefront->categories()[0]['id'] === $categoryId, 'Storefront category tree root is wrong.');
$assert($storefront->categoryByPath('/banery/')['id'] === $categoryId, 'Storefront category path lookup failed.');
$assert(count($storefront->products($categoryId)) === 1, 'Storefront category listing is wrong.');
$assert($storefront->search('Ban')[0]['effective_price_minor'] === 12300, 'Storefront search price is wrong.');
$catalogProduct = $storefront->productBySlug('baner');
$assert($catalogProduct !== null, 'Storefront product detail was not found.');
$assert($catalogProduct['variants'][0]['attributes']['format'] === '100x100', 'Storefront variant attributes are wrong.');
$assert($catalogProduct['options'][0]['values'][0]['price_delta_minor'] === 500, 'Storefront product options are wrong.');
$assert($catalogProduct['categories'][0]['is_primary'] === true, 'Storefront primary category is wrong.');
$assert($catalogProduct['file_requirements'][0]['allowed_extensions'] === ['pdf'], 'Storefront file requirements are wrong.');
$shipping = $pdo->prepare(
    'INSERT INTO commerce_shipping_methods
        (store_id, code, name, type, price_minor, tax_rate_bps, countries_json, rules_json)
     VALUES (?, "courier", "Kurier", "courier", 1219, 2300, ?, ?)'
);
$shipping->execute([$storeId, '["PL"]', '{"free_from_minor":30000}']);
$payment = $pdo->prepare(
    'INSERT INTO commerce_payment_methods (store_id, code, name, provider, type)
     VALUES (?, "integration_pay", "Płatność testowa", "integration_pay", "online")'
);
$payment->execute([$storeId]);
$coupon = $pdo->prepare(
    'INSERT INTO commerce_coupons
        (store_id, code, type, value_bps, usage_limit, usage_limit_per_customer)
     VALUES (?, "START10", "percentage", 1000, 10, 1)'
);
$coupon->execute([$storeId]);

$cartService = new CartService(new PdoCartRepository($pdo));
$createdCart = $cartService->create($storeId);
$cartToken = $createdCart['token'];
$cartId = (int) $createdCart['cart']['id'];
$cartSnapshot = $cartService->add($cartToken, $productId, null, 2);
$assert($cartSnapshot['version'] === 2, 'Cart version was not incremented.');
$assert($cartSnapshot['items'][0]['quantity'] === 2, 'Cart item was not persisted.');
$cartView = (new CartViewRepository($pdo))->find($cartToken, 'drukarnia');
$assert($cartView !== null && $cartView['subtotal_minor'] === 24600, 'Storefront cart total is wrong.');
$assert($cartView['items'][0]['product_slug'] === 'baner', 'Storefront cart product is wrong.');
$assert(count($storefront->shippingMethods()) === 1, 'Storefront shipping methods are wrong.');
$assert($storefront->paymentMethods()[0]['code'] === 'integration_pay', 'Storefront payment methods are wrong.');
$seoEntries = $storefront->seoEntries();
$assert(count($seoEntries) === 2, 'Storefront SEO entries are incomplete.');
$assert(in_array('/produkt/baner', array_column($seoEntries, 'path'), true), 'Product is missing from SEO entries.');
$assert(in_array('/kategoria-produktu/banery', array_column($seoEntries, 'path'), true), 'Category is missing from SEO entries.');

$checkoutData = new CheckoutData(
    'buyer@example.com',
    new CustomerAddress('Jan', 'Kowalski', 'Testowa 1', '00-001', 'Warszawa'),
    'courier',
    'integration_pay',
    true,
    true,
    phone: '+48123123123',
    customerId: $customerId,
    couponCode: 'START10',
);
$checkout = new CheckoutService(new PdoCheckoutStore($pdo));
$result = $checkout->place($cartToken, $checkoutData, 'integration-checkout-token-42');
$assert($result->orderStatus === 'awaiting_files', 'File-requiring order has a wrong status.');
$assert($result->totals['subtotal_minor'] === 24600, 'Checkout subtotal is wrong.');
$assert($result->totals['discount_minor'] === 2460, 'Checkout percentage discount is wrong.');
$assert($result->totals['shipping']['total_minor'] === 1219, 'Checkout shipping total is wrong.');
$assert($result->totals['total_minor'] === 23359, 'Checkout total is wrong.');
$assert((int) $pdo->query("SELECT stock_quantity FROM commerce_products WHERE id = {$productId}")->fetchColumn() === 8, 'Stock was not decremented.');

$duplicate = $checkout->place($cartToken, $checkoutData, 'integration-checkout-token-42');
$assert($duplicate->alreadyExisted, 'Duplicate checkout was not recognized.');
$assert((int) $pdo->query('SELECT COUNT(*) FROM commerce_orders')->fetchColumn() === 1, 'Duplicate checkout created another order.');
$assert((int) $pdo->query("SELECT stock_quantity FROM commerce_products WHERE id = {$productId}")->fetchColumn() === 8, 'Duplicate checkout decremented stock again.');
$assert((int) $pdo->query('SELECT COUNT(*) FROM commerce_coupon_redemptions')->fetchColumn() === 1, 'Coupon redemption was duplicated.');
$assert((int) $pdo->query('SELECT COUNT(*) FROM commerce_outbox WHERE event_type = "commerce.order.created"')->fetchColumn() === 1, 'Order outbox event was duplicated.');
$orderAccess = (new OrderAccessRepository($pdo))->findByCheckoutToken(
    $result->orderId,
    'integration-checkout-token-42',
    'drukarnia',
);
$assert($orderAccess !== null && $orderAccess['order_number'] === $result->orderNumber, 'Order access token lookup failed.');
$assert(
    (new OrderAccessRepository($pdo))->findByCheckoutToken(
        $result->orderId,
        'wrong-token-value-42',
        'drukarnia',
    ) === null,
    'Wrong order access token was accepted.',
);
$assert($notificationWorker->processNext() === 'sent', 'Order confirmation notification was not sent.');
$assert($notificationWorker->processNext() === 'empty', 'Notification queue did not drain.');
$assert(count($emailSender->messages) === 2, 'Unexpected number of queued email messages was sent.');
$assert((int) $pdo->query('SELECT COUNT(*) FROM commerce_notification_deliveries WHERE status = "sent"')->fetchColumn() === 2, 'Notification delivery audit is incomplete.');
$account = new CustomerAccountRepository($pdo, 'drukarnia');
$assert($account->orders($customerId)[0]['id'] === $result->orderId, 'Customer order history is missing the order.');
$assert($account->order($customerId, $result->orderId)['order_number'] === $result->orderNumber, 'Customer order detail access failed.');
$otherCustomer = $pdo->prepare(
    'INSERT INTO commerce_customers (store_id, email, status) VALUES (?, "other@example.com", "active")'
);
$otherCustomer->execute([$storeId]);
$otherCustomerId = (int) $pdo->lastInsertId();
$assert($account->order($otherCustomerId, $result->orderId) === null, 'Customer accessed another customer order.');
$orderItemId = (int) $pdo->query("SELECT id FROM commerce_order_items WHERE order_id = {$result->orderId} LIMIT 1")->fetchColumn();
$uploadRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'reklamova-order-files-' . bin2hex(random_bytes(6));
$sourceFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'reklamova-upload-' . bin2hex(random_bytes(6)) . '.pdf';
file_put_contents($sourceFile, "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n%%EOF");
$fileService = new OrderFileService(
    new PdoOrderFileRepository($pdo),
    $uploadRoot,
    static fn (string $source, string $target): bool => rename($source, $target),
);
$uploaded = $fileService->upload(
    $result->orderId,
    $orderItemId,
    (int) $pdo->query("SELECT id FROM commerce_product_file_requirements WHERE product_id = {$productId}")->fetchColumn(),
    'integration-checkout-token-42',
    'drukarnia',
    $sourceFile,
    'projekt.pdf',
);
$assert($uploaded['id'] > 0, 'Order file was not persisted.');
$orderWithFile = (new OrderAccessRepository($pdo))->findByCheckoutToken(
    $result->orderId,
    'integration-checkout-token-42',
    'drukarnia',
);
$assert($orderWithFile['items'][0]['file_requirements'][0]['id'] > 0, 'Order file requirement is missing from order view.');
$assert($orderWithFile['items'][0]['files'][0]['id'] === $uploaded['id'], 'Uploaded file is missing from order view.');
$download = $fileService->download($uploaded['id'], 'integration-checkout-token-42', 'drukarnia');
$assert($download !== null && is_file($download['path']), 'Authorized order file download failed.');
$assert($fileService->download($uploaded['id'], 'wrong-token-value-42', 'drukarnia') === null, 'Wrong token accessed an order file.');
$assert($fileService->download($uploaded['id'], '', 'drukarnia', $customerId) !== null, 'Owning customer could not access an order file.');
$assert($fileService->download($uploaded['id'], '', 'drukarnia', $otherCustomerId) === null, 'Another customer accessed an order file.');
$secondSource = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'reklamova-upload-' . bin2hex(random_bytes(6)) . '.pdf';
file_put_contents($secondSource, "%PDF-1.4\n%%EOF");
try {
    $fileService->upload(
        $result->orderId,
        $orderItemId,
        (int) $pdo->query("SELECT id FROM commerce_product_file_requirements WHERE product_id = {$productId}")->fetchColumn(),
        'integration-checkout-token-42',
        'drukarnia',
        $secondSource,
        'drugi.pdf',
    );
    throw new RuntimeException('Order file limit was not enforced.');
} catch (DomainException) {
    @unlink($secondSource);
}
$storedPath = $download['path'];
@unlink($storedPath);
@rmdir(dirname($storedPath));
@rmdir($uploadRoot);

$provider = new IntegrationPaymentProvider();
$redirect = (new PaymentInitiationService(new PdoPaymentInitiationStore($pdo), $provider))->start(
    $result->orderId,
    'https://shop.example.com/payment/return',
    'https://shop.example.com/payment/notify',
    'integration-payment-token-42',
);
$assert($redirect->providerTransactionId === 'integration-transaction', 'Payment attempt redirect was not persisted.');
$attemptCount = (int) $pdo->query('SELECT COUNT(*) FROM commerce_payment_attempts')->fetchColumn();
$assert($attemptCount === 1, 'Payment attempt count is wrong.');

$notification = new PaymentNotification(
    hash('sha256', 'integration-event'),
    'integration-transaction',
    $result->orderNumber,
    new Money(23359, 'PLN'),
    'settled',
    true,
    hash('sha256', 'integration-payload'),
);
$processor = new PaymentNotificationProcessor(new PdoPaymentNotificationStore($pdo));
$processed = $processor->process('integration_pay', $notification);
$assert($processed->status === 'processed', 'Valid payment notification was not processed.');
$assert($processor->process('integration_pay', $notification)->status === 'duplicate', 'Payment notification duplicate was not ignored.');
$paymentStatus = $pdo->query("SELECT payment_status FROM commerce_orders WHERE id = {$result->orderId}")->fetchColumn();
$assert($paymentStatus === 'paid', 'Order was not marked paid.');
$assert((int) $pdo->query('SELECT COUNT(*) FROM commerce_payment_events')->fetchColumn() === 1, 'Payment event was duplicated.');
$assert($notificationWorker->processNext() === 'sent', 'Paid order notification was not sent.');
$assert(str_contains($emailSender->messages[2]['subject'], 'Płatność'), 'Paid order email has the wrong template.');
$analytics = new AnalyticsEventRepository($pdo);
$assert($analytics->claimOnce('purchase', 'order', (string) $result->orderId, ['value' => 233.59]), 'Purchase event was not claimed.');
$assert(!$analytics->claimOnce('purchase', 'order', (string) $result->orderId, ['value' => 233.59]), 'Purchase event was claimed twice.');

$freeShippingCart = $cartService->create($storeId);
$cartService->add($freeShippingCart['token'], $productId, null, 3);
$freeShippingData = new CheckoutData(
    'shipping@example.com',
    new CustomerAddress('Ewa', 'Testowa', 'Próbna 2', '00-003', 'Warszawa'),
    'courier',
    'integration_pay',
    true,
    true,
);
$freeShippingOrder = $checkout->place(
    $freeShippingCart['token'],
    $freeShippingData,
    'free-shipping-checkout-token-42',
);
$assert($freeShippingOrder->totals['subtotal_minor'] === 36900, 'Free-shipping subtotal is wrong.');
$assert($freeShippingOrder->totals['shipping']['total_minor'] === 0, 'Free-shipping threshold was not applied.');
$assert((int) $pdo->query("SELECT stock_quantity FROM commerce_products WHERE id = {$productId}")->fetchColumn() === 5, 'Second checkout stock was not decremented.');
$lifecycle = new OrderLifecycleService($pdo);
$assert($lifecycle->changeOrderStatus($freeShippingOrder->orderId, $storeId, OrderStatus::Cancelled, 'admin', 1, 'integration_cancel'), 'Cancellation status was not changed.');
$assert((int) $pdo->query("SELECT stock_quantity FROM commerce_products WHERE id = {$productId}")->fetchColumn() === 8, 'Cancelled order stock was not released.');
$assert(!$lifecycle->changeOrderStatus($freeShippingOrder->orderId, $storeId, OrderStatus::Cancelled, 'admin', 1, 'duplicate_cancel'), 'Duplicate cancellation was not idempotent.');
$assert((int) $pdo->query("SELECT stock_quantity FROM commerce_products WHERE id = {$productId}")->fetchColumn() === 8, 'Duplicate cancellation released stock twice.');
$assert($notificationWorker->processNext() === 'sent', 'Second order confirmation notification was not sent.');
$assert($notificationWorker->processNext() === 'sent', 'Cancellation notification was not sent.');
$assert(str_contains($emailSender->messages[4]['subject'], 'anulowane'), 'Cancellation email has the wrong template.');

$importSnapshot = [
    'source_system' => 'woocommerce',
    'source_version' => 'integration',
    'captured_at' => '2026-09-11T12:00:00Z',
    'counts' => [
        'categories' => 0,
        'attributes' => 0,
        'tax_rates' => 1,
        'products' => 1,
        'variants' => 0,
        'images' => 0,
        'customers' => 1,
        'coupons' => 1,
        'orders' => 1,
        'order_items' => 1,
    ],
    'tax_rates' => [[
        'external_id' => '1',
        'class' => 'standard',
        'name' => 'VAT 23%',
        'rate_bps' => 2300,
        'shipping' => true,
    ]],
    'categories' => [],
    'attributes' => [],
    'products' => [[
        'external_id' => '100',
        'status' => 'publish',
        'type' => 'simple',
        'slug' => 'imported-product',
        'name' => 'Imported product',
        'sku' => 'IMP-100',
        'summary' => '',
        'description' => '',
        'regular_price_minor' => 12300,
        'sale_price_minor' => null,
        'current_price_minor' => 12300,
        'stock_status' => 'instock',
        'track_stock' => false,
        'stock_quantity' => null,
        'weight_grams' => null,
        'width_mm' => null,
        'height_mm' => null,
        'length_mm' => null,
        'featured_image_relative' => null,
        'gallery_relative' => [],
        'category_external_ids' => [],
        'attributes' => [],
        'tax_class' => '',
        'sort_order' => 0,
    ]],
    'variants' => [],
    'customers' => [[
        'external_id' => '7',
        'email' => 'legacy@example.com',
        'first_name' => 'Anna',
        'last_name' => 'Nowak',
        'phone' => '+48111222333',
        'company' => null,
        'tax_id' => null,
        'registered_at' => '2025-01-01 12:00:00',
        'password_reset_required' => true,
        'addresses' => [[
            'type' => 'billing',
            'first_name' => 'Anna',
            'last_name' => 'Nowak',
            'company' => null,
            'tax_id' => null,
            'address_line1' => 'Stara 1',
            'address_line2' => null,
            'postal_code' => '00-002',
            'city' => 'Warszawa',
            'country_code' => 'PL',
            'phone' => '+48111222333',
        ]],
    ]],
    'coupons' => [[
        'external_id' => '8',
        'code' => 'LEGACY10',
        'status' => 'publish',
        'type' => 'percent',
        'value_minor' => null,
        'value_bps' => 1000,
        'minimum_minor' => null,
        'maximum_discount_minor' => null,
        'usage_limit' => null,
        'usage_limit_per_customer' => 1,
        'starts_at' => '2025-01-01 12:00:00',
        'ends_at' => null,
        'individual_use' => false,
        'exclude_sale_items' => false,
        'free_shipping' => false,
    ]],
    'orders' => [[
        'external_id' => '900',
        'order_number' => '900',
        'source_status' => 'wc-completed',
        'currency' => 'PLN',
        'customer_external_id' => '7',
        'customer_email' => 'legacy@example.com',
        'customer_phone' => '+48111222333',
        'billing_address' => ['first_name' => 'Anna', 'last_name' => 'Nowak'],
        'shipping_address' => ['first_name' => 'Anna', 'last_name' => 'Nowak'],
        'subtotal_minor' => 12300,
        'discount_minor' => 0,
        'shipping_minor' => 0,
        'net_minor' => 10000,
        'tax_minor' => 2300,
        'total_minor' => 12300,
        'shipping_method_code' => 'legacy_shipping',
        'shipping_method_name' => 'Legacy shipping',
        'pickup_point' => null,
        'payment_method_code' => 'imoje',
        'provider_transaction_id' => 'legacy-provider-transaction',
        'coupon_codes' => [],
        'customer_note' => null,
        'created_at' => '2025-02-01 12:00:00',
        'updated_at' => '2025-02-02 12:00:00',
        'paid_at' => '2025-02-01 12:05:00',
        'completed_at' => '2025-02-02 12:00:00',
        'items' => [[
            'external_id' => '901',
            'name' => 'Imported product',
            'product_external_id' => '100',
            'variant_external_id' => null,
            'quantity' => 1,
            'unit_price_minor' => 12300,
            'subtotal_minor' => 10000,
            'subtotal_tax_minor' => 2300,
            'discount_minor' => 0,
            'net_minor' => 10000,
            'tax_minor' => 2300,
            'total_minor' => 12300,
            'attributes' => [],
        ]],
    ]],
];
$importer = new WordPressImporter(new CommerceImportRepository($pdo));
$importStore = [
    'code' => 'import-test',
    'name' => 'Import test',
    'currency' => 'PLN',
    'country_code' => 'PL',
    'prices_include_tax' => true,
];
$firstImport = $importer->run($importSnapshot, $importStore, false);
$assert($firstImport['status'] === 'imported', 'WooCommerce history import failed.');
$secondImport = $importer->run($importSnapshot, $importStore, false);
$assert($secondImport['status'] === 'imported', 'WooCommerce idempotent rerun failed.');
$importStoreId = (int) $pdo->query("SELECT id FROM commerce_stores WHERE code = 'import-test'")->fetchColumn();
$assert((int) $pdo->query("SELECT COUNT(*) FROM commerce_customers WHERE store_id = {$importStoreId}")->fetchColumn() === 1, 'Imported customer was duplicated.');
$assert((int) $pdo->query("SELECT COUNT(*) FROM commerce_coupons WHERE store_id = {$importStoreId}")->fetchColumn() === 1, 'Imported coupon was duplicated.');
$assert((int) $pdo->query("SELECT COUNT(*) FROM commerce_orders WHERE store_id = {$importStoreId}")->fetchColumn() === 1, 'Imported order was duplicated.');
$importedOrderId = (int) $pdo->query("SELECT id FROM commerce_orders WHERE store_id = {$importStoreId}")->fetchColumn();
$assert((int) $pdo->query("SELECT COUNT(*) FROM commerce_order_items WHERE order_id = {$importedOrderId}")->fetchColumn() === 1, 'Imported order item was duplicated.');
$assert((int) $pdo->query("SELECT COUNT(*) FROM commerce_payment_attempts WHERE order_id = {$importedOrderId}")->fetchColumn() === 1, 'Imported payment was duplicated.');
$resetRequired = (int) $pdo->query("SELECT password_reset_required FROM commerce_customers WHERE store_id = {$importStoreId}")->fetchColumn();
$assert($resetRequired === 1, 'Imported customer was not forced through password reset.');

echo "PASS commerce migrations, checkout/payment and WooCommerce history integration\n";
