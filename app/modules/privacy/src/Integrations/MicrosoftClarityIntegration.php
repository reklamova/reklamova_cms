<?php

declare(strict_types=1);

namespace Reklamova\Cms\Modules\Privacy\Integrations;

final class MicrosoftClarityIntegration extends AbstractIntegration
{
    public function key(): string { return 'clarity'; }
    public function name(): string { return 'Microsoft Clarity'; }
    public function provider(): string { return 'Microsoft'; }
    public function defaultCategory(): string { return 'analytics'; }
    public function fields(): array { return ['project_id' => 'Project ID']; }
    public function cookies(): array { return ['_clck', '_clsk']; }
    public function generate(array $settings): string
    {
        $id = $this->cleanId((string) ($settings['project_id'] ?? ''));
        return $id === '' ? '' : "(function(c,l,a,r,i,t,y){c[a]=c[a]||function(){(c[a].q=c[a].q||[]).push(arguments)};t=l.createElement(r);t.async=1;t.src='https://www.clarity.ms/tag/'+i;y=l.getElementsByTagName(r)[0];y.parentNode.insertBefore(t,y);})(window,document,'clarity','script','{$id}');";
    }
}
