<?php

declare(strict_types=1);

namespace Reklamova\Cms\Modules\Privacy\Integrations;

final class MetaPixelIntegration extends AbstractIntegration
{
    public function key(): string { return 'meta_pixel'; }
    public function name(): string { return 'Meta Pixel'; }
    public function provider(): string { return 'Meta'; }
    public function defaultCategory(): string { return 'marketing'; }
    public function fields(): array { return ['pixel_id' => 'Pixel ID']; }
    public function cookies(): array { return ['_fbp', '_fbc']; }
    public function generate(array $settings): string
    {
        $id = $this->cleanId((string) ($settings['pixel_id'] ?? ''));
        return $id === '' ? '' : "!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');fbq('init','{$id}');fbq('track','PageView');";
    }
}
