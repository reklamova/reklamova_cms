<?php

declare(strict_types=1);

namespace Reklamova\Cms\Modules\Privacy\Integrations;

final class YouTubeEmbedIntegration extends AbstractIntegration
{
    public function key(): string { return 'youtube'; }
    public function name(): string { return 'YouTube embed control'; }
    public function provider(): string { return 'Google'; }
    public function defaultCategory(): string { return 'functional'; }
    public function defaultPlacement(): string { return 'body_end'; }
    public function cookies(): array { return ['VISITOR_INFO1_LIVE', 'YSC']; }
}
