<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Reklamova\Cms\Auth\Csrf;
use Reklamova\Cms\Auth\PermissionManager;
use Reklamova\Cms\Commerce\Orders\OrderStatus;
use Reklamova\Cms\Commerce\Orders\PaymentStatus;
use Reklamova\Cms\Commerce\Orders\StatusTransitionGuard;
use Reklamova\Cms\Commerce\Checkout\CheckoutData;
use Reklamova\Cms\Commerce\Checkout\CheckoutLine;
use Reklamova\Cms\Commerce\Checkout\CheckoutQuote;
use Reklamova\Cms\Commerce\Checkout\CheckoutResult;
use Reklamova\Cms\Commerce\Checkout\CheckoutService;
use Reklamova\Cms\Commerce\Checkout\CheckoutStoreInterface;
use Reklamova\Cms\Commerce\Checkout\CustomerAddress;
use Reklamova\Cms\Commerce\Checkout\OrderNumberGeneratorInterface;
use Reklamova\Cms\Commerce\Cart\CartRepositoryInterface;
use Reklamova\Cms\Commerce\Cart\CartService;
use Reklamova\Cms\Commerce\Import\DecimalMoneyParser;
use Reklamova\Cms\Commerce\Import\CommerceImportRepository;
use Reklamova\Cms\Commerce\Import\ProductMediaMigrator;
use Reklamova\Cms\Commerce\Import\WordPressImporter;
use Reklamova\Cms\Commerce\Payments\PaymentRequest;
use Reklamova\Cms\Commerce\Payments\HttpClientInterface;
use Reklamova\Cms\Commerce\Payments\HttpResponse;
use Reklamova\Cms\Commerce\Payments\IngPayProvider;
use Reklamova\Cms\Commerce\Payments\PaymentAttempt;
use Reklamova\Cms\Commerce\Payments\PaymentNotification;
use Reklamova\Cms\Commerce\Payments\PaymentNotificationProcessor;
use Reklamova\Cms\Commerce\Payments\PaymentNotificationStoreInterface;
use Reklamova\Cms\Commerce\Payments\PaymentInitiationService;
use Reklamova\Cms\Commerce\Payments\PaymentInitiationStoreInterface;
use Reklamova\Cms\Commerce\Payments\PaymentProviderInterface;
use Reklamova\Cms\Commerce\Payments\PaymentRedirect;
use Reklamova\Cms\Commerce\Payments\PaymentReservation;
use Reklamova\Cms\Commerce\Pricing\CartCalculator;
use Reklamova\Cms\Commerce\Pricing\TaxCalculator;
use Reklamova\Cms\Commerce\Shared\Money;
use Reklamova\Cms\Media\MediaUploadPolicy;
use Reklamova\Cms\Support\Config;
use Reklamova\Cms\Support\EmailTemplate;

final class FakePaymentHttpClient implements HttpClientInterface
{
    /** @var array<int, HttpResponse> */
    public array $responses = [];
    /** @var array<int, array<string, mixed>> */
    public array $requests = [];

    public function request(string $method, string $url, array $headers = [], ?string $body = null): HttpResponse
    {
        $this->requests[] = compact('method', 'url', 'headers', 'body');
        if ($this->responses === []) {
            throw new RuntimeException('No fake HTTP response queued.');
        }

        return array_shift($this->responses);
    }
}

final class FakePaymentNotificationStore implements PaymentNotificationStoreInterface
{
    public bool $claimed = false;
    public int $claimCalls = 0;
    /** @var array<int, array<string, mixed>> */
    public array $rejections = [];
    /** @var array<int, array<string, mixed>> */
    public array $applications = [];

    public function __construct(public ?PaymentAttempt $paymentAttempt)
    {
    }

    public function transaction(callable $callback): mixed
    {
        return $callback();
    }

    public function claim(string $provider, PaymentNotification $notification): bool
    {
        $this->claimCalls++;
        if ($this->claimed) {
            return false;
        }
        $this->claimed = true;

        return true;
    }

    public function attempt(string $provider, string $providerTransactionId): ?PaymentAttempt
    {
        if ($this->paymentAttempt?->provider !== $provider
            || $this->paymentAttempt?->providerTransactionId !== $providerTransactionId) {
            return null;
        }

        return $this->paymentAttempt;
    }

    public function reject(string $provider, string $eventKey, string $errorCode, ?int $attemptId = null): void
    {
        $this->rejections[] = compact('provider', 'eventKey', 'errorCode', 'attemptId');
    }

    public function apply(
        string $provider,
        PaymentNotification $notification,
        PaymentAttempt $attempt,
        PaymentStatus $newStatus,
    ): void {
        $this->applications[] = compact('provider', 'notification', 'attempt', 'newStatus');
    }
}

final class FakePaymentProvider implements PaymentProviderInterface
{
    public int $initiateCalls = 0;
    public ?Throwable $initiateException = null;

    public function __construct(public PaymentRedirect $redirect)
    {
    }

    public function name(): string
    {
        return 'fake_pay';
    }

    public function initiate(PaymentRequest $request): PaymentRedirect
    {
        $this->initiateCalls++;
        if ($this->initiateException !== null) {
            throw $this->initiateException;
        }

        return $this->redirect;
    }

    public function parseNotification(string $rawBody, array $headers): PaymentNotification
    {
        throw new BadMethodCallException('Not used by this fake.');
    }

    public function query(string $providerTransactionId): PaymentNotification
    {
        throw new BadMethodCallException('Not used by this fake.');
    }

    public function cancel(string $providerTransactionId): void
    {
        throw new BadMethodCallException('Not used by this fake.');
    }
}

final class FakePaymentInitiationStore implements PaymentInitiationStoreInterface
{
    /** @var array<string, mixed>|null */
    public ?array $reserved = null;
    public ?int $completedAttemptId = null;
    public ?int $failedAttemptId = null;
    public ?string $failureCode = null;

    public function __construct(public ?PaymentRedirect $existingRedirect = null)
    {
    }

    public function transaction(callable $callback): mixed
    {
        return $callback();
    }

    public function reserve(
        int $orderId,
        string $provider,
        string $environment,
        string $idempotencyKey,
    ): PaymentReservation {
        $this->reserved = compact('orderId', 'provider', 'environment', 'idempotencyKey');

        return new PaymentReservation(
            17,
            $orderId,
            'DR-2026-42',
            new Money(1299, 'PLN'),
            'buyer@example.com',
            'Jan',
            'Kowalski',
            $idempotencyKey,
            $this->existingRedirect,
        );
    }

    public function complete(int $attemptId, PaymentRedirect $redirect): void
    {
        $this->completedAttemptId = $attemptId;
    }

    public function fail(int $attemptId, string $errorCode): void
    {
        $this->failedAttemptId = $attemptId;
        $this->failureCode = $errorCode;
    }
}

final class FakeCheckoutStore implements CheckoutStoreInterface
{
    public ?CheckoutResult $existingResult = null;
    public int $quoteCalls = 0;
    public ?array $created = null;
    public ?array $completedCart = null;

    public function __construct(public CheckoutQuote $quote)
    {
    }

    public function transaction(callable $callback): mixed
    {
        return $callback();
    }

    public function existing(string $checkoutKey): ?CheckoutResult
    {
        return $this->existingResult;
    }

    public function lockQuote(string $cartToken, CheckoutData $data): CheckoutQuote
    {
        $this->quoteCalls++;

        return $this->quote;
    }

    public function createOrder(
        string $checkoutKey,
        string $orderNumber,
        CheckoutQuote $quote,
        CheckoutData $data,
        array $calculation,
    ): CheckoutResult {
        $this->created = compact('checkoutKey', 'orderNumber', 'quote', 'data', 'calculation');
        $requiresFiles = array_reduce(
            $quote->lines,
            static fn (bool $required, CheckoutLine $line): bool => $required || $line->requiresFiles,
            false,
        );

        return new CheckoutResult(
            42,
            $orderNumber,
            $requiresFiles ? 'awaiting_files' : 'new',
            'unpaid',
            $calculation,
        );
    }

    public function completeCart(int $cartId, int $expectedVersion): void
    {
        $this->completedCart = compact('cartId', 'expectedVersion');
    }
}

final class FixedOrderNumberGenerator implements OrderNumberGeneratorInterface
{
    public function generate(string $storeCode): string
    {
        return strtoupper($storeCode) . '-FIXED-42';
    }
}

final class FakeCartRepository implements CartRepositoryInterface
{
    /** @var array<int, array<string, mixed>> */
    public array $added = [];

    public function transaction(callable $callback): mixed
    {
        return $callback();
    }

    public function create(int $storeId, ?int $customerId, string $tokenHash, string $expiresAt): array
    {
        return [
            'id' => 1,
            'store_id' => $storeId,
            'customer_id' => $customerId,
            'currency' => 'PLN',
            'status' => 'active',
            'version' => 1,
            'expires_at' => $expiresAt,
            'items' => [],
            'token_hash' => $tokenHash,
        ];
    }

    public function addItem(
        string $tokenHash,
        int $productId,
        ?int $variantId,
        int $quantity,
        array $options,
        string $configurationHash,
    ): array {
        $this->added[] = compact(
            'tokenHash',
            'productId',
            'variantId',
            'quantity',
            'options',
            'configurationHash',
        );

        return ['version' => count($this->added) + 1, 'items' => $this->added];
    }

    public function setQuantity(string $tokenHash, int $itemId, int $quantity): array
    {
        return compact('tokenHash', 'itemId', 'quantity');
    }

    public function removeItem(string $tokenHash, int $itemId): array
    {
        return compact('tokenHash', 'itemId');
    }

    public function snapshot(string $tokenHash): array
    {
        return ['token_hash' => $tokenHash, 'items' => $this->added];
    }
}

$passed = 0;
$failed = 0;

$test = static function (string $name, callable $callback) use (&$passed, &$failed): void {
    try {
        $callback();
        $passed++;
        echo "PASS {$name}\n";
    } catch (Throwable $exception) {
        $failed++;
        fwrite(STDERR, "FAIL {$name}: {$exception->getMessage()}\n");
    }
};

$assert = static function (bool $condition, string $message = 'Assertion failed'): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$test('admin session cookies use secure defaults', static function () use ($assert): void {
    $_SERVER['HTTPS'] = 'on';
    Csrf::startSession();
    $params = session_get_cookie_params();
    $assert($params['secure'] === true);
    $assert($params['httponly'] === true);
    $assert(($params['samesite'] ?? '') === 'Lax');
    $assert(ini_get('session.use_strict_mode') === '1');
});

$test('responsive email template escapes content and validates action URL', static function () use ($assert): void {
    $html = (new EmailTemplate())->render(
        'Drukarnia',
        'Krótki podgląd',
        'Zamówienie <123>',
        ['Treść & szczegóły'],
        ['Status' => 'Opłacone'],
        ['label' => 'Otwórz', 'url' => 'javascript:alert(1)'],
    );
    $assert(str_starts_with($html, '<!doctype html>'));
    $assert(str_contains($html, 'Zamówienie &lt;123&gt;'));
    $assert(str_contains($html, 'Treść &amp; szczegóły'));
    $assert(!str_contains($html, 'javascript:alert'));
    $assert(str_contains($html, '@media(max-width:620px)'));
});

$test('environment overrides typed configuration', static function () use ($assert): void {
    putenv('REKLAMOVA_APP_DEBUG=true');
    putenv('REKLAMOVA_APP_MEDIA_MAX_UPLOAD_BYTES=4096');
    try {
        $config = new Config(['config_path' => sys_get_temp_dir()]);
        $assert($config->get('app', 'debug', false) === true);
        $assert($config->get('app', 'media_max_upload_bytes', 1) === 4096);
    } finally {
        putenv('REKLAMOVA_APP_DEBUG');
        putenv('REKLAMOVA_APP_MEDIA_MAX_UPLOAD_BYTES');
    }
});

$test('internal access depends on role, not hostname', static function () use ($assert): void {
    $_SERVER['HTTP_HOST'] = 'cms.reklamova.pl';
    $manager = (new ReflectionClass(PermissionManager::class))->newInstanceWithoutConstructor();
    $assert(!$manager->isInternalUser(['role' => 'admin']));
    $assert($manager->isInternalUser(['role' => 'developer']));
});

$test('media policy accepts a real PNG', static function () use ($assert): void {
    $path = tempnam(sys_get_temp_dir(), 'reklamova-test-');
    if ($path === false) {
        throw new RuntimeException('Cannot create temporary file.');
    }
    try {
        file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true));
        $result = (new MediaUploadPolicy(1024))->validate([
            'error' => UPLOAD_ERR_OK,
            'tmp_name' => $path,
            'name' => 'pixel.png',
        ]);
        $assert($result['mime_type'] === 'image/png');
        $assert($result['size'] > 0);
    } finally {
        @unlink($path);
    }
});

$test('media policy rejects mismatched extension', static function (): void {
    $path = tempnam(sys_get_temp_dir(), 'reklamova-test-');
    if ($path === false) {
        throw new RuntimeException('Cannot create temporary file.');
    }
    try {
        file_put_contents($path, '<?php echo "unsafe";');
        try {
            (new MediaUploadPolicy(1024))->validate([
                'error' => UPLOAD_ERR_OK,
                'tmp_name' => $path,
                'name' => 'fake.png',
            ]);
        } catch (RuntimeException) {
            return;
        }
        throw new RuntimeException('Mismatched content was accepted.');
    } finally {
        @unlink($path);
    }
});

$test('money rejects mixed currencies', static function (): void {
    try {
        (new Money(100, 'PLN'))->add(new Money(100, 'EUR'));
    } catch (InvalidArgumentException) {
        return;
    }
    throw new RuntimeException('Mixed currencies were accepted.');
});

$test('decimal money parsing never uses floats', static function () use ($assert): void {
    $parser = new DecimalMoneyParser();
    $assert($parser->parse('12,19') === 1219);
    $assert($parser->parse('12177') === 1217700);
    $assert($parser->parse('1.235') === 124);
    $assert($parser->parse(null) === null);
});

$test('WordPress importer dry-run reports without writing', static function () use ($assert): void {
    $repository = (new ReflectionClass(CommerceImportRepository::class))->newInstanceWithoutConstructor();
    $snapshot = [
        'source_system' => 'woocommerce',
        'source_version' => 'test',
        'captured_at' => '2026-09-11T00:00:00Z',
        'counts' => ['categories' => 1, 'attributes' => 0, 'products' => 1, 'variants' => 0, 'images' => 0],
        'tax_rates' => [],
        'categories' => [['external_id' => '1', 'parent_external_id' => null, 'slug' => 'test', 'name' => 'Test']],
        'attributes' => [],
        'products' => [['external_id' => '2', 'slug' => 'product', 'sku' => null]],
        'variants' => [],
    ];
    $report = (new WordPressImporter($repository))->run($snapshot, [], true);
    $assert($report['status'] === 'dry_run_ok');
    $assert($report['planned']['products'] === 1);
});

$test('WordPress importer blocks duplicate source SKU', static function () use ($assert): void {
    $repository = (new ReflectionClass(CommerceImportRepository::class))->newInstanceWithoutConstructor();
    $product = ['slug' => 'one', 'sku' => 'SAME'];
    $snapshot = [
        'counts' => [],
        'categories' => [],
        'attributes' => [],
        'products' => [
            ['external_id' => '1'] + $product,
            ['external_id' => '2', 'slug' => 'two', 'sku' => 'SAME'],
        ],
        'variants' => [],
    ];
    $report = (new WordPressImporter($repository))->run($snapshot, [], true);
    $assert($report['status'] === 'blocked');
    $assert($report['conflicts'][0]['code'] === 'duplicate_products_sku');
});

$test('product media migration verifies and reuses checksum', static function () use ($assert): void {
    $root = sys_get_temp_dir() . '/reklamova-media-' . bin2hex(random_bytes(4));
    $source = $root . '/source/2026/09';
    $public = $root . '/public';
    mkdir($source, 0775, true);
    mkdir($public, 0775, true);
    $path = $source . '/pixel.png';
    file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true));
    try {
        $migrator = new ProductMediaMigrator($root . '/source', $public);
        $first = $migrator->migrate('2026/09/pixel.png');
        $second = $migrator->migrate('2026/09/pixel.png');
        $assert($first['copied'] === true);
        $assert($second['copied'] === false);
        $assert($first['checksum'] === $second['checksum']);
    } finally {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        @rmdir($root);
    }
});

$test('inclusive VAT calculation balances exactly', static function () use ($assert): void {
    $result = (new TaxCalculator())->fromGross(new Money(12300, 'PLN'), 2300);
    $assert($result->net->amountMinor === 10000);
    $assert($result->tax->amountMinor === 2300);
    $assert($result->gross->amountMinor === 12300);
});

$test('cart calculation covers discount VAT and shipping', static function () use ($assert): void {
    $result = (new CartCalculator())->calculate([
        [
            'id' => 'line-1',
            'unit_price_minor' => 1000,
            'quantity' => 2,
            'discount_minor' => 100,
            'tax_rate_bps' => 2300,
        ],
    ], [
        'method' => 'inpost',
        'amount_minor' => 1219,
        'tax_rate_bps' => 2300,
    ]);
    $assert($result['subtotal_minor'] === 2000);
    $assert($result['discount_minor'] === 100);
    $assert($result['net_minor'] === 2536);
    $assert($result['tax_minor'] === 583);
    $assert($result['total_minor'] === 3119);
});

$test('cart rejects discount greater than line value', static function (): void {
    try {
        (new CartCalculator())->calculate([[
            'unit_price_minor' => 1000,
            'quantity' => 1,
            'discount_minor' => 1001,
            'tax_rate_bps' => 2300,
        ]]);
    } catch (InvalidArgumentException) {
        return;
    }
    throw new RuntimeException('Invalid discount was accepted.');
});

$test('cart issues an opaque token and stores only its hash', static function () use ($assert): void {
    $repository = new FakeCartRepository();
    $created = (new CartService($repository))->create(4);
    $assert(strlen($created['token']) >= 43);
    $assert($created['cart']['token_hash'] === hash('sha256', $created['token']));
    $assert($created['cart']['token_hash'] !== $created['token']);
});

$test('cart configuration hash is independent of option key order', static function () use ($assert): void {
    $repository = new FakeCartRepository();
    $service = new CartService($repository);
    $token = str_repeat('t', 43);
    $service->add($token, 5, 7, 1, ['finish' => 'matte', 'size' => ['width' => 10, 'height' => 20]]);
    $service->add($token, 5, 7, 1, ['size' => ['height' => 20, 'width' => 10], 'finish' => 'matte']);
    $assert($repository->added[0]['configurationHash'] === $repository->added[1]['configurationHash']);
    $assert(array_keys($repository->added[0]['options']) === ['finish', 'size']);
});

$checkoutQuote = static function (): CheckoutQuote {
    return new CheckoutQuote(
        1,
        'drukarnia',
        9,
        3,
        'PLN',
        true,
        [
            new CheckoutLine(
                5,
                15,
                'Baner',
                '100 x 200 cm',
                'BAN-100-200',
                2,
                1000,
                100,
                2300,
                ['finish' => 'eyelets'],
                ['configuration_hash' => str_repeat('a', 64)],
                true,
            ),
        ],
        'courier',
        'Kurier',
        1219,
        2300,
        2,
        'START10',
    );
};

$checkoutData = static function (): CheckoutData {
    return new CheckoutData(
        'buyer@example.com',
        new CustomerAddress('Jan', 'Kowalski', 'Testowa 1', '00-001', 'Warszawa'),
        'courier',
        'ing_pay',
        true,
        true,
        phone: '+48123123123',
        couponCode: 'START10',
    );
};

$test('checkout atomically calculates and creates an awaiting-files order', static function () use ($assert, $checkoutQuote, $checkoutData): void {
    $store = new FakeCheckoutStore($checkoutQuote());
    $result = (new CheckoutService($store, null, new FixedOrderNumberGenerator()))->place(
        'cart-token-long-enough-123456',
        $checkoutData(),
        'checkout-submit-token-42',
    );
    $assert($result->orderNumber === 'DRUKARNIA-FIXED-42');
    $assert($result->orderStatus === 'awaiting_files');
    $assert($result->totals['subtotal_minor'] === 2000);
    $assert($result->totals['discount_minor'] === 100);
    $assert($result->totals['shipping']['total_minor'] === 1219);
    $assert($result->totals['total_minor'] === 3119);
    $assert($store->created['checkoutKey'] === hash('sha256', 'checkout-submit-token-42'));
    $assert($store->completedCart === ['cartId' => 9, 'expectedVersion' => 3]);
});

$test('checkout duplicate returns existing order without converting cart again', static function () use ($assert, $checkoutQuote, $checkoutData): void {
    $store = new FakeCheckoutStore($checkoutQuote());
    $store->existingResult = new CheckoutResult(42, 'DRUKARNIA-OLD', 'new', 'unpaid', ['total_minor' => 1000]);
    $result = (new CheckoutService($store, null, new FixedOrderNumberGenerator()))->place(
        'cart-token-long-enough-123456',
        $checkoutData(),
        'checkout-submit-token-42',
    );
    $assert($result->alreadyExisted);
    $assert($result->orderNumber === 'DRUKARNIA-OLD');
    $assert($store->quoteCalls === 0);
    $assert($store->created === null);
    $assert($store->completedCart === null);
});

$test('checkout requires legal consents', static function (): void {
    try {
        new CheckoutData(
            'buyer@example.com',
            new CustomerAddress('Jan', 'Kowalski', 'Testowa 1', '00-001', 'Warszawa'),
            'courier',
            'ing_pay',
            false,
            true,
        );
    } catch (DomainException) {
        return;
    }
    throw new RuntimeException('Checkout without terms consent was accepted.');
});

$test('checkout validates Polish company tax ID', static function (): void {
    new CustomerAddress(
        'Jan',
        'Kowalski',
        'Testowa 1',
        '00-001',
        'Warszawa',
        company: 'Reklamova',
        taxId: '8567346215',
    );
    try {
        new CustomerAddress(
            'Jan',
            'Kowalski',
            'Testowa 1',
            '00-001',
            'Warszawa',
            company: 'Reklamova',
            taxId: '1234567890',
        );
    } catch (InvalidArgumentException) {
        return;
    }
    throw new RuntimeException('Invalid Polish tax ID was accepted.');
});

$test('order and payment transitions are explicit', static function () use ($assert): void {
    $guard = new StatusTransitionGuard();
    $assert($guard->canChangePayment(PaymentStatus::Pending, PaymentStatus::Paid));
    $assert(!$guard->canChangePayment(PaymentStatus::Paid, PaymentStatus::Pending));
    $assert($guard->canChangeOrder(OrderStatus::AwaitingFiles, OrderStatus::FilesReceived));
    $assert(!$guard->canChangeOrder(OrderStatus::Completed, OrderStatus::InProduction));
});

$test('payment requests require HTTPS callbacks', static function (): void {
    try {
        new PaymentRequest(
            '1',
            'DR-1',
            new Money(1000, 'PLN'),
            'buyer@example.com',
            'http://example.com/return',
            'https://example.com/notify',
            'payment-1',
        );
    } catch (InvalidArgumentException) {
        return;
    }
    throw new RuntimeException('Insecure payment callback was accepted.');
});

$test('ING Pay initiation uses sandbox API and minor units', static function () use ($assert): void {
    $http = new FakePaymentHttpClient();
    $http->responses[] = new HttpResponse(200, json_encode([
        'payment' => [
            'id' => '0f0cc3d0-aae8-410b-bf5c-358955c348e3',
            'url' => 'https://paywall.pay.ing.pl/s/test',
            'status' => 'new',
            'futureField' => true,
        ],
    ], JSON_THROW_ON_ERROR));
    $provider = new IngPayProvider($http, 'merchant', 'service', 'service-secret', 'bearer-token', 'sandbox');
    $result = $provider->initiate(new PaymentRequest(
        '42',
        'DR-42',
        new Money(1219, 'PLN'),
        'buyer@example.com',
        'https://shop.example.com/payment/return',
        'https://shop.example.com/payment/notify',
        'attempt-42',
        'Jan',
        'Kowalski',
    ));
    $request = $http->requests[0];
    $payload = json_decode((string) $request['body'], true, 512, JSON_THROW_ON_ERROR);
    $assert(str_starts_with($request['url'], 'https://api.sandbox.pay.ing.pl/'));
    $assert($request['headers']['Authorization'] === 'Bearer bearer-token');
    $assert($payload['amount'] === 1219);
    $assert($payload['orderId'] === 'DR-42');
    $assert($result->providerTransactionId === '0f0cc3d0-aae8-410b-bf5c-358955c348e3');
});

$test('payment initiation reserves server order and stores provider redirect', static function () use ($assert): void {
    $redirect = new PaymentRedirect('provider-payment', 'https://pay.example.com/redirect');
    $provider = new FakePaymentProvider($redirect);
    $store = new FakePaymentInitiationStore();
    $result = (new PaymentInitiationService($store, $provider))->start(
        42,
        'https://shop.example.com/payment/return',
        'https://shop.example.com/payment/notify',
        'checkout-attempt-token-42',
    );
    $assert($result === $redirect);
    $assert($provider->initiateCalls === 1);
    $assert($store->reserved['provider'] === 'fake_pay');
    $assert($store->reserved['idempotencyKey'] === hash('sha256', 'checkout-attempt-token-42'));
    $assert($store->completedAttemptId === 17);
    $assert($store->failedAttemptId === null);
});

$test('payment initiation returns an existing redirect idempotently', static function () use ($assert): void {
    $redirect = new PaymentRedirect('provider-payment', 'https://pay.example.com/redirect');
    $provider = new FakePaymentProvider($redirect);
    $store = new FakePaymentInitiationStore($redirect);
    $result = (new PaymentInitiationService($store, $provider))->start(
        42,
        'https://shop.example.com/payment/return',
        'https://shop.example.com/payment/notify',
        'checkout-attempt-token-42',
    );
    $assert($result === $redirect);
    $assert($provider->initiateCalls === 0);
    $assert($store->completedAttemptId === null);
});

$test('payment initiation failure marks the reserved attempt', static function () use ($assert): void {
    $provider = new FakePaymentProvider(new PaymentRedirect('unused', 'https://pay.example.com/unused'));
    $provider->initiateException = new RuntimeException('Network unavailable.');
    $store = new FakePaymentInitiationStore();
    try {
        (new PaymentInitiationService($store, $provider))->start(
            42,
            'https://shop.example.com/payment/return',
            'https://shop.example.com/payment/notify',
            'checkout-attempt-token-42',
        );
    } catch (RuntimeException) {
        $assert($store->failedAttemptId === 17);
        $assert($store->failureCode === 'provider_request_failed');
        $assert($store->completedAttemptId === null);

        return;
    }
    throw new RuntimeException('Provider exception was swallowed.');
});

$test('payment redirect requires HTTPS', static function (): void {
    try {
        new PaymentRedirect('provider-payment', 'http://pay.example.com/redirect');
    } catch (InvalidArgumentException) {
        return;
    }
    throw new RuntimeException('Insecure provider redirect was accepted.');
});

$test('ING Pay notification validates raw-body signature', static function () use ($assert): void {
    $provider = new IngPayProvider(
        new FakePaymentHttpClient(),
        'merchant',
        'service',
        'service-secret',
        'bearer-token',
        'sandbox'
    );
    $body = json_encode([
        'transaction' => [
            'id' => 'transaction',
            'status' => 'settled',
            'modified' => 123,
            'serviceId' => 'service',
            'amount' => 4999,
            'currency' => 'PLN',
            'orderId' => 'DR-99',
        ],
        'payment' => ['id' => 'payment-link'],
        'additionalFutureObject' => ['safe' => true],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $signature = hash('sha256', $body . 'service-secret');
    $header = "merchantid=merchant;serviceid=service;signature={$signature};alg=sha256";
    $notification = $provider->parseNotification($body, ['X-Imoje-Signature' => $header]);
    $assert($notification->signatureValid);
    $assert($notification->providerTransactionId === 'payment-link');
    $assert($notification->orderId === 'DR-99');
    $assert($notification->amount->amountMinor === 4999);

    $tampered = str_replace('4999', '5000', $body);
    $invalid = $provider->parseNotification($tampered, ['X-Imoje-Signature' => $header]);
    $assert(!$invalid->signatureValid);
});

$paymentAttempt = static function (PaymentStatus $status = PaymentStatus::Pending): PaymentAttempt {
    return new PaymentAttempt(
        7,
        42,
        'DR-42',
        'ing_pay',
        'payment-42',
        new Money(4999, 'PLN'),
        $status,
    );
};

$paymentNotification = static function (
    bool $signatureValid = true,
    string $status = 'settled',
    string $orderId = 'DR-42',
    int $amountMinor = 4999,
    string $currency = 'PLN',
): PaymentNotification {
    return new PaymentNotification(
        hash('sha256', implode('|', [$status, $orderId, $amountMinor, $currency])),
        'payment-42',
        $orderId,
        new Money($amountMinor, $currency),
        $status,
        $signatureValid,
        hash('sha256', 'payload'),
    );
};

$test('payment notification settles the matching pending order', static function () use ($assert, $paymentAttempt, $paymentNotification): void {
    $store = new FakePaymentNotificationStore($paymentAttempt());
    $result = (new PaymentNotificationProcessor($store))->process('ing_pay', $paymentNotification());
    $assert($result->status === 'processed');
    $assert($result->orderId === 42);
    $assert(count($store->applications) === 1);
    $assert($store->applications[0]['newStatus'] === PaymentStatus::Paid);
    $assert($store->rejections === []);
});

$test('payment notification duplicate is acknowledged without a second update', static function () use ($assert, $paymentAttempt, $paymentNotification): void {
    $store = new FakePaymentNotificationStore($paymentAttempt());
    $processor = new PaymentNotificationProcessor($store);
    $first = $processor->process('ing_pay', $paymentNotification());
    $second = $processor->process('ing_pay', $paymentNotification());
    $assert($first->status === 'processed');
    $assert($second->status === 'duplicate');
    $assert(count($store->applications) === 1);
});

$test('invalid payment signature cannot poison duplicate handling', static function () use ($assert, $paymentAttempt, $paymentNotification): void {
    $store = new FakePaymentNotificationStore($paymentAttempt());
    $result = (new PaymentNotificationProcessor($store))->process('ing_pay', $paymentNotification(false));
    $assert($result->status === 'rejected');
    $assert($result->reason === 'invalid_signature');
    $assert($store->claimCalls === 0);
    $assert($store->applications === []);
});

$test('payment notification rejects amount mismatch', static function () use ($assert, $paymentAttempt, $paymentNotification): void {
    $store = new FakePaymentNotificationStore($paymentAttempt());
    $result = (new PaymentNotificationProcessor($store))->process('ing_pay', $paymentNotification(amountMinor: 5000));
    $assert($result->status === 'rejected');
    $assert($result->reason === 'amount_mismatch');
    $assert($store->applications === []);
    $assert($store->rejections[0]['attemptId'] === 7);
});

$test('payment notification rejects order and currency mismatch', static function () use ($assert, $paymentAttempt, $paymentNotification): void {
    $orderStore = new FakePaymentNotificationStore($paymentAttempt());
    $orderResult = (new PaymentNotificationProcessor($orderStore))->process(
        'ing_pay',
        $paymentNotification(orderId: 'DR-99'),
    );
    $assert($orderResult->reason === 'order_mismatch');

    $currencyStore = new FakePaymentNotificationStore($paymentAttempt());
    $currencyResult = (new PaymentNotificationProcessor($currencyStore))->process(
        'ing_pay',
        $paymentNotification(currency: 'EUR'),
    );
    $assert($currencyResult->reason === 'currency_mismatch');
});

$test('payment notification rejects backwards paid transition', static function () use ($assert, $paymentAttempt, $paymentNotification): void {
    $store = new FakePaymentNotificationStore($paymentAttempt(PaymentStatus::Paid));
    $result = (new PaymentNotificationProcessor($store))->process(
        'ing_pay',
        $paymentNotification(status: 'pending'),
    );
    $assert($result->status === 'rejected');
    $assert($result->reason === 'invalid_status_transition');
    $assert($store->applications === []);
});

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
