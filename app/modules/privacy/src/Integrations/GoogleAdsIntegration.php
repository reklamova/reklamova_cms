<?php

declare(strict_types=1);

namespace Reklamova\Cms\Modules\Privacy\Integrations;

final class GoogleAdsIntegration extends AbstractIntegration
{
    public function key(): string { return 'google_ads'; }
    public function name(): string { return 'Google Ads'; }
    public function provider(): string { return 'Google'; }
    public function defaultCategory(): string { return 'marketing'; }
    public function fields(): array { return ['conversion_id' => 'Conversion ID', 'conversion_label' => 'Conversion Label']; }
    public function requiresConsentMode(): bool { return true; }
    public function cookies(): array { return ['_gcl_au', 'IDE', 'test_cookie']; }
    public function generate(array $settings): string
    {
        $id = $this->cleanId((string) ($settings['conversion_id'] ?? ''));
        return $id === '' ? '' : "var s=document.createElement('script');s.async=true;s.src='https://www.googletagmanager.com/gtag/js?id={$id}';document.head.appendChild(s);window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config','{$id}');";
    }
}
