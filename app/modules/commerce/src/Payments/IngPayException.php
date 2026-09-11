<?php

declare(strict_types=1);

namespace Reklamova\Cms\Commerce\Payments;

final class IngPayException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?string $apiCode = null,
        public readonly ?int $httpStatus = null,
    ) {
        parent::__construct($message);
    }
}
