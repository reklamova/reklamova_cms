<?php

declare(strict_types=1);

namespace Reklamova\Cms\Modules\Privacy\Integrations;

final class TikTokPixelIntegration extends AbstractIntegration
{
    public function key(): string { return 'tiktok'; }
    public function name(): string { return 'TikTok Pixel'; }
    public function provider(): string { return 'TikTok'; }
    public function defaultCategory(): string { return 'marketing'; }
    public function fields(): array { return ['pixel_id' => 'Pixel ID']; }
    public function cookies(): array { return ['_ttp', 'ttclid']; }
    public function generate(array $settings): string
    {
        $id = $this->cleanId((string) ($settings['pixel_id'] ?? ''));
        return $id === '' ? '' : "!function(w,d,t){w.TiktokAnalyticsObject=t;var ttq=w[t]=w[t]||[];ttq.methods=['page','track','identify','instances','debug','on','off','once','ready','alias','group','enableCookie','disableCookie'];ttq.setAndDefer=function(t,e){t[e]=function(){t.push([e].concat(Array.prototype.slice.call(arguments,0)))}};for(var i=0;i<ttq.methods.length;i++)ttq.setAndDefer(ttq,ttq.methods[i]);ttq.load=function(e){var i='https://analytics.tiktok.com/i18n/pixel/events.js';ttq._i=ttq._i||{};ttq._i[e]=[];ttq._t=ttq._t||{};ttq._t[e]=+new Date;ttq._o=ttq._o||{};ttq._o[e]={};var n=document.createElement('script');n.type='text/javascript';n.async=!0;n.src=i+'?sdkid='+e+'&lib='+t;var a=document.getElementsByTagName('script')[0];a.parentNode.insertBefore(n,a)};ttq.load('{$id}');ttq.page();}(window,document,'ttq');";
    }
}
