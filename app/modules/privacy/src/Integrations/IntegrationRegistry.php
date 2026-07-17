<?php

declare(strict_types=1);

namespace Reklamova\Cms\Modules\Privacy\Integrations;

require_once __DIR__ . '/AbstractIntegration.php';
require_once __DIR__ . '/GoogleTagManagerIntegration.php';
require_once __DIR__ . '/GoogleAnalyticsIntegration.php';
require_once __DIR__ . '/GoogleAdsIntegration.php';
require_once __DIR__ . '/MetaPixelIntegration.php';
require_once __DIR__ . '/MicrosoftClarityIntegration.php';
require_once __DIR__ . '/HotjarIntegration.php';
require_once __DIR__ . '/TikTokPixelIntegration.php';
require_once __DIR__ . '/LinkedInInsightIntegration.php';
require_once __DIR__ . '/GoogleMapsIntegration.php';
require_once __DIR__ . '/YouTubeEmbedIntegration.php';
require_once __DIR__ . '/CustomScriptIntegration.php';

final class IntegrationRegistry
{
    /**
     * @return array<string, AbstractIntegration>
     */
    public function all(): array
    {
        $items = [
            new GoogleTagManagerIntegration(),
            new GoogleAnalyticsIntegration(),
            new GoogleAdsIntegration(),
            new MetaPixelIntegration(),
            new MicrosoftClarityIntegration(),
            new HotjarIntegration(),
            new TikTokPixelIntegration(),
            new LinkedInInsightIntegration(),
            new GoogleMapsIntegration(),
            new YouTubeEmbedIntegration(),
            new CustomScriptIntegration(),
        ];

        $byKey = [];
        foreach ($items as $item) {
            $byKey[$item->key()] = $item;
        }

        return $byKey;
    }

    public function get(string $key): ?AbstractIntegration
    {
        return $this->all()[$key] ?? null;
    }
}
