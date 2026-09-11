<?php

declare(strict_types=1);

namespace Reklamova\Cms\Commerce\Payments;

use Reklamova\Cms\Commerce\Shared\Money;

final class IngPayProvider implements PaymentProviderInterface
{
    private const BASE_URLS = [
        'sandbox' => 'https://api.sandbox.pay.ing.pl/v1/merchant',
        'production' => 'https://api.pay.ing.pl/v1/merchant',
    ];
    private const SIGNATURE_ALGORITHMS = ['sha224', 'sha256', 'sha384', 'sha512'];

    public function __construct(
        private HttpClientInterface $http,
        private string $merchantId,
        private string $serviceId,
        private string $serviceKey,
        private string $bearerToken,
        private string $environment = 'sandbox',
    ) {
        if (!isset(self::BASE_URLS[$environment])) {
            throw new \InvalidArgumentException('ING Pay environment must be sandbox or production.');
        }
        foreach ([$merchantId, $serviceId, $serviceKey, $bearerToken] as $credential) {
            if (trim($credential) === '') {
                throw new \InvalidArgumentException('ING Pay credentials are incomplete.');
            }
        }
    }

    public function name(): string
    {
        return 'ing_pay';
    }

    public function initiate(PaymentRequest $request): PaymentRedirect
    {
        $payload = [
            'serviceId' => $this->serviceId,
            'amount' => $request->amount->amountMinor,
            'currency' => $request->amount->currency,
            'orderId' => $request->orderNumber,
            'title' => $request->title !== '' ? $request->title : 'Zamówienie ' . $request->orderNumber,
            'returnUrl' => $request->returnUrl,
            'successReturnUrl' => $request->returnUrl,
            'failureReturnUrl' => $request->returnUrl,
            'notificationUrl' => $request->notificationUrl,
            'customer' => [
                'firstName' => $request->customerFirstName,
                'lastName' => $request->customerLastName,
                'email' => $request->customerEmail,
                'locale' => 'pl',
            ],
        ];
        $response = $this->send('POST', '/' . rawurlencode($this->merchantId) . '/payment', $payload);
        $payment = $response['payment'] ?? null;
        if (!is_array($payment) || empty($payment['id']) || empty($payment['url'])) {
            throw new IngPayException('ING Pay response is missing payment redirect data.');
        }

        return new PaymentRedirect((string) $payment['id'], (string) $payment['url'], (string) ($payment['status'] ?? 'pending'));
    }

    public function parseNotification(string $rawBody, array $headers): PaymentNotification
    {
        $signatureHeader = $this->header($headers, 'x-imoje-signature');
        $signature = $this->parseSignatureHeader($signatureHeader);
        $algorithm = strtolower((string) ($signature['alg'] ?? ''));
        $incoming = strtolower((string) ($signature['signature'] ?? ''));
        $expected = in_array($algorithm, self::SIGNATURE_ALGORITHMS, true)
            ? hash($algorithm, $rawBody . $this->serviceKey)
            : '';
        $signatureValid = $incoming !== ''
            && hash_equals($this->merchantId, (string) ($signature['merchantid'] ?? ''))
            && hash_equals($this->serviceId, (string) ($signature['serviceid'] ?? ''))
            && $expected !== ''
            && hash_equals($expected, $incoming);

        $payload = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($payload)) {
            throw new IngPayException('ING Pay notification body is invalid.');
        }
        $transaction = is_array($payload['transaction'] ?? null) ? $payload['transaction'] : [];
        $payment = is_array($payload['payment'] ?? null) ? $payload['payment'] : [];
        $source = $transaction !== [] ? $transaction : $payment;
        $paymentId = (string) (
            $payment['id']
            ?? ($transaction['payment']['id'] ?? null)
            ?? ($transaction['id'] ?? '')
        );
        $orderId = (string) ($source['orderId'] ?? '');
        $amount = $source['amount'] ?? null;
        $currency = strtoupper((string) ($source['currency'] ?? ''));
        $status = (string) ($source['status'] ?? '');
        if ($paymentId === '' || $orderId === '' || !is_int($amount) || preg_match('/^[A-Z]{3}$/', $currency) !== 1 || $status === '') {
            throw new IngPayException('ING Pay notification is missing required transaction fields.');
        }
        $modified = (string) ($source['modified'] ?? $source['created'] ?? '');
        $eventKey = hash('sha256', implode('|', [$paymentId, $orderId, $status, $modified, hash('sha256', $rawBody)]));

        return new PaymentNotification(
            $eventKey,
            $paymentId,
            $orderId,
            new Money($amount, $currency),
            $status,
            $signatureValid,
            hash('sha256', $rawBody),
        );
    }

    public function query(string $providerTransactionId): PaymentNotification
    {
        $response = $this->send(
            'GET',
            '/' . rawurlencode($this->merchantId) . '/payment/' . rawurlencode($providerTransactionId)
        );
        $id = (string) ($response['id'] ?? '');
        $orderId = (string) ($response['orderId'] ?? '');
        $amount = $response['amount'] ?? null;
        $currency = strtoupper((string) ($response['currency'] ?? ''));
        $status = (string) ($response['status'] ?? '');
        if ($id === '' || $orderId === '' || !is_numeric($amount) || $currency === '' || $status === '') {
            throw new IngPayException('ING Pay query response is incomplete.');
        }
        $raw = json_encode($response, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return new PaymentNotification(
            hash('sha256', 'query|' . $id . '|' . $status . '|' . ($response['modified'] ?? '')),
            $id,
            $orderId,
            new Money((int) $amount, $currency),
            $status,
            true,
            hash('sha256', $raw),
        );
    }

    public function cancel(string $providerTransactionId): void
    {
        $response = $this->send('POST', '/' . rawurlencode($this->merchantId) . '/payment/cancel', [
            'serviceId' => $this->serviceId,
            'paymentId' => $providerTransactionId,
        ]);
        if (($response['status'] ?? null) !== 'cancelled') {
            throw new IngPayException('ING Pay did not confirm payment cancellation.');
        }
    }

    /**
     * @param array<string, mixed>|null $payload
     * @return array<string, mixed>
     */
    private function send(string $method, string $path, ?array $payload = null): array
    {
        $body = $payload === null ? null : json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $response = $this->http->request($method, self::BASE_URLS[$this->environment] . $path, [
            'Authorization' => 'Bearer ' . $this->bearerToken,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ], $body);
        if ($response->statusCode < 200 || $response->statusCode >= 300) {
            $apiCode = null;
            try {
                $decoded = $response->json();
                $apiCode = (string) ($decoded['apiErrorResponse']['code'] ?? '');
            } catch (\Throwable) {
            }
            throw new IngPayException('ING Pay API request failed.', $apiCode ?: null, $response->statusCode);
        }

        try {
            return $response->json();
        } catch (\Throwable $exception) {
            throw new IngPayException('ING Pay API returned invalid JSON.', null, $response->statusCode);
        }
    }

    /**
     * @param array<string, string> $headers
     */
    private function header(array $headers, string $name): string
    {
        foreach ($headers as $headerName => $value) {
            if (strtolower($headerName) === strtolower($name)) {
                return trim($value, " \t\n\r\0\x0B\"");
            }
        }

        return '';
    }

    /**
     * @return array<string, string>
     */
    private function parseSignatureHeader(string $header): array
    {
        $result = [];
        foreach (explode(';', $header) as $part) {
            if (!str_contains($part, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $part, 2);
            $result[strtolower(trim($key))] = trim($value);
        }

        return $result;
    }
}
