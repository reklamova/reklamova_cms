<?php

declare(strict_types=1);

namespace Reklamova\Cms\Themes;

final class ThemeManager
{
    public function __construct(private array $container)
    {
    }

    public function discover(): array
    {
        $themes = [];
        foreach (glob($this->container['app_path'] . '/themes/*/theme.json') ?: [] as $manifestPath) {
            $manifest = json_decode((string) file_get_contents($manifestPath), true) ?: [];
            if (isset($manifest['slug'])) {
                $themes[$manifest['slug']] = $manifest;
            }
        }

        return $themes;
    }
}

