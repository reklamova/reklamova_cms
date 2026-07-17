<?php

declare(strict_types=1);

namespace Reklamova\Cms\Modules\Privacy\Integrations;

final class HotjarIntegration extends AbstractIntegration
{
    public function key(): string { return 'hotjar'; }
    public function name(): string { return 'Hotjar'; }
    public function provider(): string { return 'Hotjar'; }
    public function defaultCategory(): string { return 'analytics'; }
    public function fields(): array { return ['site_id' => 'Site ID']; }
    public function cookies(): array { return ['_hjSessionUser_*', '_hjSession_*']; }
    public function generate(array $settings): string
    {
        $id = $this->cleanId((string) ($settings['site_id'] ?? ''));
        return $id === '' ? '' : "(function(h,o,t,j,a,r){h.hj=h.hj||function(){(h.hj.q=h.hj.q||[]).push(arguments)};h._hjSettings={hjid:{$id},hjsv:6};a=o.getElementsByTagName('head')[0];r=o.createElement('script');r.async=1;r.src=t+h._hjSettings.hjid+j+h._hjSettings.hjsv;a.appendChild(r);})(window,document,'https://static.hotjar.com/c/hotjar-','.js?sv=');";
    }
}
