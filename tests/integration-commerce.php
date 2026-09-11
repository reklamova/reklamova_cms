<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Reklamova\Cms\Commerce\Checkout\CheckoutData;
use Reklamova\Cms\Commerce\Checkout\CheckoutService;
use Reklamova\Cms\Commerce\Checkout\CustomerAddress;
use Reklamova\Cms\Commerce\Checkout\PdoCheckoutStore;
use Reklamova\Cms\Commerce\Cart\CartService;
use Reklamova\Cms\Commerce\Cart\PdoCartRepository;
use Reklamova\Cms\Commerce\Payments\PaymentInitiationService;
use Reklamova\Cms\Commerce\Payments\PaymentNotification;
use Reklamova\Cms\Commerce\Payments\PaymentNotificationProcessor;
use Reklamova\Cms\Commerce\Payments\PaymentProviderInterface;
use Reklamova\Cms\Commerce\Payments\PaymentRedirect;
use Reklamova\Cms\Commerce\Payments\PaymentRequest;
use Reklamova\Cms\Commerce\Payments\PdoPaymentInitiationStore;
use Reklamova\Cms\Commerce\Payments\PdoPaymentNotificationStore;
use Reklamova\Cms\Commerce\Shared\Money;
use Reklamova\Cms\Database\ConnectionFactory;
use Reklamova\Cms\Database\Migrator;
use Reklamova\Cms\Modules\ModuleManager;

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

$columns = $pdo->query('SHOW COLUMNS FROM commerce_orders')->fetchAll(PDO::FETCH_COLUMN);
$assert(in_array('checkout_key', $columns, true), 'Checkout idempotency migration did not run.');
$assert(in_array('consents_json', $columns, true), 'Checkout consent migration did not run.');

$pdo->exec("INSERT INTO commerce_stores (code, name, currency) VALUES ('drukarnia', 'Drukarnia', 'PLN')");
$storeId = (int) $pdo->lastInsertId();
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
$fileRequirement = $pdo->prepare(
    'INSERT INTO commerce_product_file_requirements
        (product_id, label, allowed_extensions_json, allowed_mime_types_json, max_bytes)
     VALUES (?, "Plik do druku", ?, ?, 10485760)'
);
$fileRequirement->execute([$productId, '["pdf"]', '["application/pdf"]']);
$shipping = $pdo->prepare(
    'INSERT INTO commerce_shipping_methods
        (store_id, code, name, type, price_minor, tax_rate_bps, countries_json)
     VALUES (?, "courier", "Kurier", "courier", 1219, 2300, ?)'
);
$shipping->execute([$storeId, '["PL"]']);
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

$checkoutData = new CheckoutData(
    'buyer@example.com',
    new CustomerAddress('Jan', 'Kowalski', 'Testowa 1', '00-001', 'Warszawa'),
    'courier',
    'integration_pay',
    true,
    true,
    phone: '+48123123123',
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
$assert((int) $pdo->query('SELECT COUNT(*) FROM commerce_outbox')->fetchColumn() === 1, 'Order outbox event was duplicated.');

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

echo "PASS commerce migrations and checkout/payment database integration\n";
