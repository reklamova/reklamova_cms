<?php

declare(strict_types=1);

use Reklamova\Cms\Database\ConnectionFactory;
use Reklamova\Cms\Modules\Landing\LandingRepository;

if (!function_exists('landing_repository')) {
    function landing_repository(): ?LandingRepository
    {
        global $container;
        require_once __DIR__ . '/src/LandingRepository.php';
        try {
            return new LandingRepository((new ConnectionFactory($container))->make());
        } catch (Throwable) {
            return null;
        }
    }
}

if (!function_exists('landing_pages')) {
    function landing_pages(bool $publishedOnly = true): array
    {
        return landing_repository()?->all($publishedOnly) ?? [];
    }
}
