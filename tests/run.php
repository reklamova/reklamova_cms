<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Reklamova\Cms\Auth\Csrf;
use Reklamova\Cms\Auth\PermissionManager;
use Reklamova\Cms\Commerce\Orders\OrderStatus;
use Reklamova\Cms\Commerce\Orders\PaymentStatus;
use Reklamova\Cms\Commerce\Orders\StatusTransitionGuard;
use Reklamova\Cms\Commerce\Import\DecimalMoneyParser;
use Reklamova\Cms\Commerce\Import\CommerceImportRepository;
use Reklamova\Cms\Commerce\Import\ProductMediaMigrator;
use Reklamova\Cms\Commerce\Import\WordPressImporter;
use Reklamova\Cms\Commerce\Payments\PaymentRequest;
use Reklamova\Cms\Commerce\Payments\HttpClientInterface;
use Reklamova\Cms\Commerce\Payments\HttpResponse;
use Reklamova\Cms\Commerce\Payments\IngPayProvider;
use Reklamova\Cms\Commerce\Pricing\CartCalculator;
use Reklamova\Cms\Commerce\Pricing\TaxCalculator;
use Reklamova\Cms\Commerce\Shared\Money;
use Reklamova\Cms\Media\MediaUploadPolicy;
use Reklamova\Cms\Support\Config;

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

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
