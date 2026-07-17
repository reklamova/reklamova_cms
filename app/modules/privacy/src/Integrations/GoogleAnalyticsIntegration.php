<?php

declare(strict_types=1);

namespace Reklamova\Cms\Modules\Privacy\Integrations;

final class GoogleAnalyticsIntegration extends AbstractIntegration
{
    public function key(): string { return 'ga4'; }
    public function name(): string { return 'Google Analytics 4'; }
    public function provider(): string { return 'Google'; }
    public function defaultCategory(): string { return 'analytics'; }
    public function fields(): array { return ['measurement_id' => 'Measurement ID']; }
    public function requiresConsentMode(): bool { return true; }
    public function cookies(): array { return ['_ga', '_ga_*']; }
    public function generate(array $settings): string
    {
        $id = $this->cleanId((string) ($settings['measurement_id'] ?? ''));
        return $id === '' ? '' : "var s=document.createElement('script');s.async=true;s.src='https://www.googletagmanager.com/gtag/js?id={$id}';document.head.appendChild(s);window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config','{$id}');";
    }
}
