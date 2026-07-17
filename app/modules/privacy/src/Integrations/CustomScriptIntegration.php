<?php

declare(strict_types=1);

namespace Reklamova\Cms\Modules\Privacy\Integrations;

final class CustomScriptIntegration extends AbstractIntegration
{
    public function key(): string { return 'custom'; }
    public function name(): string { return 'Custom script'; }
    public function provider(): string { return ''; }
    public function defaultCategory(): string { return 'marketing'; }
    public function defaultPlacement(): string { return 'body_end'; }
    public function fields(): array { return ['code' => 'Kod HTML/JS albo URL']; }
}
