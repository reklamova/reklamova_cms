<?php

declare(strict_types=1);

use Reklamova\Cms\Database\ConnectionFactory;
use Reklamova\Cms\Modules\Trust\TrustRepository;

if (!function_exists('trust_repository')) {
    function trust_repository(): ?TrustRepository
    {
        global $container;
        require_once __DIR__ . '/src/TrustRepository.php';
        try {
            return new TrustRepository((new ConnectionFactory($container))->make());
        } catch (Throwable) {
            return null;
        }
    }
}

if (!function_exists('trust_items')) {
    function trust_items(?string $type = null, bool $publishedOnly = true): array
    {
        return trust_repository()?->all($publishedOnly, $type) ?? [];
    }
}
