<?php

declare(strict_types=1);

namespace Reklamova\Cms\Modules\Privacy\Integrations;

final class LinkedInInsightIntegration extends AbstractIntegration
{
    public function key(): string { return 'linkedin'; }
    public function name(): string { return 'LinkedIn Insight Tag'; }
    public function provider(): string { return 'LinkedIn'; }
    public function defaultCategory(): string { return 'marketing'; }
    public function fields(): array { return ['partner_id' => 'Partner ID']; }
    public function cookies(): array { return ['bcookie', 'li_gc', 'lidc']; }
    public function generate(array $settings): string
    {
        $id = $this->cleanId((string) ($settings['partner_id'] ?? ''));
        return $id === '' ? '' : "_linkedin_partner_id='{$id}';window._linkedin_data_partner_ids=window._linkedin_data_partner_ids||[];window._linkedin_data_partner_ids.push(_linkedin_partner_id);(function(l){if(!l){window.lintrk=function(a,b){window.lintrk.q.push([a,b])};window.lintrk.q=[]}var s=document.getElementsByTagName('script')[0];var b=document.createElement('script');b.type='text/javascript';b.async=true;b.src='https://snap.licdn.com/li.lms-analytics/insight.min.js';s.parentNode.insertBefore(b,s)})(window.lintrk);";
    }
}
