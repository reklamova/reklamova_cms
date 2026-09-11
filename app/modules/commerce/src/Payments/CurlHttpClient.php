<?php

declare(strict_types=1);

namespace Reklamova\Cms\Commerce\Payments;

final class CurlHttpClient implements HttpClientInterface
{
    public function __construct(
        private int $connectTimeoutSeconds = 5,
        private int $timeoutSeconds = 20,
    ) {
    }

    public function request(string $method, string $url, array $headers = [], ?string $body = null): HttpResponse
    {
        if (!filter_var($url, FILTER_VALIDATE_URL) || strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https') {
            throw new \InvalidArgumentException('Payment API URL must use HTTPS.');
        }

        $responseHeaders = [];
        $handle = curl_init($url);
        if ($handle === false) {
            throw new \RuntimeException('Cannot initialize payment HTTP client.');
        }
        $options = [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeoutSeconds,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => array_map(
                static fn (string $name, string $value): string => $name . ': ' . $value,
                array_keys($headers),
                array_values($headers)
            ),
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
                $length = strlen($line);
                if (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $responseHeaders[strtolower(trim($name))] = trim($value);
                }

                return $length;
            },
        ];
        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($handle, $options);
        $responseBody = curl_exec($handle);
        if ($responseBody === false) {
            $errorCode = curl_errno($handle);
            curl_close($handle);
            throw new \RuntimeException("Payment HTTP transport failed with code {$errorCode}.");
        }
        $statusCode = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        return new HttpResponse($statusCode, (string) $responseBody, $responseHeaders);
    }
}
