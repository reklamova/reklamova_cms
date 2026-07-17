<?php

declare(strict_types=1);

namespace Reklamova\Cms\Modules\Privacy;

final class ConsentModeService
{
    public function defaultState(): array
    {
        return [
            'ad_storage' => 'denied',
            'ad_user_data' => 'denied',
            'ad_personalization' => 'denied',
            'analytics_storage' => 'denied',
            'functionality_storage' => 'denied',
            'personalization_storage' => 'denied',
            'security_storage' => 'granted',
        ];
    }

    public function stateFromCategories(array $categories, array $selected): array
    {
        $state = $this->defaultState();
        foreach ($categories as $category) {
            $slug = (string) ($category['slug'] ?? '');
            $isGranted = $slug === 'necessary' || !empty($selected[$slug]);
            $mapping = json_decode((string) ($category['consent_mode_mapping_json'] ?? '{}'), true) ?: [];
            foreach ($mapping as $key => $value) {
                $state[$key] = $isGranted ? 'granted' : 'denied';
            }
        }

        $state['security_storage'] = 'granted';

        return $state;
    }

    public function defaultSnippet(): string
    {
        $json = json_encode($this->defaultState(), JSON_UNESCAPED_SLASHES);

        return '<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag("consent","default",' . $json . ');window.ReklamovaConsentModeDefault=' . $json . ';</script>';
    }
}
