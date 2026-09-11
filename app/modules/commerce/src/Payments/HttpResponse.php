<?php

declare(strict_types=1);

namespace Reklamova\Cms\Commerce\Payments;

final readonly class HttpResponse
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public int $statusCode,
        public string $body,
        public array $headers = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function json(): array
    {
        $decoded = json_decode($this->body, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Payment API returned a non-object JSON response.');
        }

        return $decoded;
    }
}
