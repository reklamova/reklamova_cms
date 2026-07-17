<?php

declare(strict_types=1);

namespace Reklamova\Cms\Modules\Privacy\Integrations;

final class GoogleMapsIntegration extends AbstractIntegration
{
    public function key(): string { return 'google_maps'; }
    public function name(): string { return 'Google Maps embed control'; }
    public function provider(): string { return 'Google'; }
    public function defaultCategory(): string { return 'functional'; }
    public function defaultPlacement(): string { return 'body_end'; }
    public function cookies(): array { return ['NID', 'AEC']; }
}
