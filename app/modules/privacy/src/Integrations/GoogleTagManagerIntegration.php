<?php

declare(strict_types=1);

namespace Reklamova\Cms\Modules\Privacy\Integrations;

final class GoogleTagManagerIntegration extends AbstractIntegration
{
    public function key(): string { return 'gtm'; }
    public function name(): string { return 'Google Tag Manager'; }
    public function provider(): string { return 'Google'; }
    public function defaultCategory(): string { return 'analytics'; }
    public function description(): string { return 'Kontener GTM ładowany po zgodzie przypisanej kategorii. Consent Mode default musi byc ustawiony przed nim.'; }
    public function fields(): array { return ['container_id' => 'GTM Container ID']; }
    public function requiresConsentMode(): bool { return true; }
    public function cookies(): array { return ['_ga', '_gid', '_gcl_au']; }
    public function generate(array $settings): string
    {
        $id = $this->cleanId((string) ($settings['container_id'] ?? ''));
        return $id === '' ? '' : "(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);})(window,document,'script','dataLayer','{$id}');";
    }
}
