<?php

declare(strict_types=1);

use Reklamova\Cms\Database\ConnectionFactory;
use Reklamova\Cms\Modules\Business\BusinessRepository;

if (!function_exists('business_repository')) {
    function business_repository(): ?BusinessRepository
    {
        global $container;
        require_once __DIR__ . '/src/BusinessRepository.php';

        try {
            return new BusinessRepository((new ConnectionFactory($container))->make());
        } catch (Throwable) {
            return null;
        }
    }
}

if (!function_exists('business_services')) {
    function business_services(bool $publishedOnly = true): array
    {
        return business_repository()?->all('services', $publishedOnly) ?? [];
    }
}

if (!function_exists('business_service_areas')) {
    function business_service_areas(bool $publishedOnly = true): array
    {
        return business_repository()?->all('areas', $publishedOnly) ?? [];
    }
}

if (!function_exists('business_case_studies')) {
    function business_case_studies(bool $publishedOnly = true): array
    {
        return business_repository()?->all('cases', $publishedOnly) ?? [];
    }
}

if (!function_exists('business_testimonials')) {
    function business_testimonials(bool $publishedOnly = true): array
    {
        return business_repository()?->all('testimonials', $publishedOnly) ?? [];
    }
}

if (!function_exists('business_faqs')) {
    function business_faqs(string $scopeType = 'global', ?string $scopeSlug = null): array
    {
        return business_repository()?->publishedFaqs($scopeType, $scopeSlug) ?? [];
    }
}

if (!function_exists('business_cta')) {
    function business_cta(string $placement = 'global'): ?array
    {
        return business_repository()?->publishedCta($placement);
    }
}
