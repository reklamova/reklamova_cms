<?php

declare(strict_types=1);

namespace Reklamova\Cms\Admin;

use Reklamova\Cms\Auth\Csrf;
use Reklamova\Cms\Auth\PermissionManager;

final class AdminView
{
    public function __construct(private array $extraNavigation = [], private ?PermissionManager $permissions = null)
    {
    }

    public function render(string $title, string $content, ?array $user = null): void
    {
        header('Content-Type: text/html; charset=utf-8');
        $adminCss = '/assets/core/admin.css?v=' . rawurlencode($this->adminAssetVersion());

        if (!$user) {
            echo '<!doctype html><html lang="pl"><head><meta charset="utf-8">'
                . '<meta name="viewport" content="width=device-width, initial-scale=1">'
                . '<title>' . htmlspecialchars($title, ENT_QUOTES) . ' - Reklamova CMS</title>'
                . '<link rel="icon" type="image/svg+xml" href="/favicon.svg">'
                . '<link rel="stylesheet" href="' . $adminCss . '">'
                . '</head><body>' . $content . '</body></html>';
            return;
        }

        $nav = $this->navigation($user);
        $accountLabel = $this->isInternalUser($user) ? 'Reklamova' : 'Administrator strony';
        $displayName = htmlspecialchars((string) ($user['name'] ?: $user['email']), ENT_QUOTES);
        $account = '<form method="post" action="/admin/logout" class="logout">' . Csrf::field()
            . '<span><b>' . $displayName . '</b><small>' . htmlspecialchars($accountLabel, ENT_QUOTES) . '</small></span><button>Wyloguj</button></form>';

        echo '<!doctype html><html lang="pl"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . htmlspecialchars($title, ENT_QUOTES) . ' - Reklamova CMS</title>'
            . '<link rel="icon" type="image/svg+xml" href="/favicon.svg">'
            . '<link rel="stylesheet" href="' . $adminCss . '">'
            . '</head><body><div class="layout">'
            . $nav
            . '<main class="main"><header class="topbar"><div class="topbar-title"><span class="topbar-kicker">Panel CMS</span><h1>' . htmlspecialchars($title, ENT_QUOTES) . '</h1></div><div class="topbar-actions"><a class="view-site-link" href="/" target="_blank" rel="noopener">Zobacz stronę</a>' . $account . '</div></header>'
            . '<section class="content">' . $content . '</section></main>'
            . '</div></body></html>';
    }

    private function navigation(array $user): string
    {
        return '<aside class="sidebar">' . $this->brandHtml() . '<nav>' . $this->groupedNavigation($user) . '</nav></aside>';
    }

    private function isInternalUser(array $user): bool
    {
        if ($this->permissions) {
            return $this->permissions->isInternalUser($user);
        }

        $role = strtolower((string) ($user['role'] ?? 'admin'));
        $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));

        return in_array($role, ['super_admin', 'reklamova_admin', 'reklamova', 'developer'], true)
            || str_contains($host, 'cms.reklamova.pl');
    }

    private function groupedNavigation(array $user): string
    {
        $groups = [
            'Treść' => [
                $this->menuItem('/admin', 'Start', 'view_dashboard', 10, true),
                $this->menuItem('/admin/pages', 'Strony', 'manage_pages', 20, true),
                $this->menuItem('/admin/media', 'Media', 'manage_media', 30, true),
            ],
            'Sprzedaż' => [],
            'Marketing' => [],
            'Ustawienia' => [
                $this->menuItem('/admin/settings', 'Ustawienia strony', 'manage_basic_settings', 700, true),
                $this->menuItem('/admin/account', 'Konto', 'view_dashboard', 710, true),
            ],
            'Reklamova / techniczne' => [
                $this->menuItem('/admin/installations', 'Instalacje CMS', 'manage_installations', 870, false, true),
                $this->menuItem('/admin/modules', 'Moduły strony', 'manage_modules', 880, false, true),
                $this->menuItem('/admin/themes', 'Motyw strony', 'manage_themes', 890, false, true),
                $this->menuItem('/admin/system', 'Aktualizacje CMS', 'manage_updates', 900, false, true),
                $this->menuItem('/admin/health', 'Stan systemu', 'view_health', 920, false, true),
            ],
        ];

        foreach ($this->extraNavigation as $href => $item) {
            $href = (string) $href;
            $data = is_array($item) ? $item : ['label' => (string) $item];
            $data['href'] = $href;
            $data['label'] = $this->friendlyMenuLabel((string) ($data['label'] ?? $href), $href);
            $data['permission'] = (string) ($data['permission'] ?? $this->permissionForPath($href));
            $data['menu_group'] = (string) ($data['menu_group'] ?? $this->groupForPath($href));
            $data['sort_order'] = (int) ($data['sort_order'] ?? 500);
            $groups[$data['menu_group']][] = $data;
        }

        $currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '/admin', PHP_URL_PATH) ?: '/admin';
        $links = '';
        foreach ($groups as $group => $items) {
            usort($items, static fn (array $a, array $b): int => ((int) ($a['sort_order'] ?? 500)) <=> ((int) ($b['sort_order'] ?? 500)));
            $groupLinks = '';
            foreach ($items as $item) {
                if (!$this->canSeeMenuItem($user, $item)) {
                    continue;
                }

                $href = (string) ($item['href'] ?? '#');
                $label = (string) ($item['label'] ?? $href);
                $active = $currentPath === $href || ($href !== '/admin' && str_starts_with($currentPath, $href . '/'));
                $groupLinks .= '<a href="' . htmlspecialchars($href, ENT_QUOTES) . '"' . ($active ? ' aria-current="page"' : '') . '>' . htmlspecialchars($label, ENT_QUOTES) . '</a>';
            }

            if ($groupLinks !== '') {
                $links .= '<div class="nav-section">' . htmlspecialchars($group, ENT_QUOTES) . '</div>' . $groupLinks;
            }
        }

        return $links;
    }

    /**
     * @return array<string, mixed>
     */
    private function menuItem(string $href, string $label, string $permission, int $sortOrder, bool $clientVisible, bool $internalOnly = false): array
    {
        return [
            'href' => $href,
            'label' => $label,
            'permission' => $permission,
            'sort_order' => $sortOrder,
            'visible_in_client_nav' => $clientVisible,
            'visible_in_admin_nav' => true,
            'internal_only' => $internalOnly,
        ];
    }

    /**
     * @param array<string, mixed> $item
     */
    private function canSeeMenuItem(array $user, array $item): bool
    {
        if ($this->permissions) {
            return $this->permissions->canSeeMenuItem($user, $item);
        }

        return empty($item['internal_only']) || $this->isInternalUser($user);
    }

    private function permissionForPath(string $href): string
    {
        return match (true) {
            str_contains($href, 'lead') || str_contains($href, 'form') => 'manage_forms',
            str_contains($href, 'knowledge') || str_contains($href, 'article') || str_contains($href, 'blog') => 'manage_blog',
            str_contains($href, 'catalog') || str_contains($href, 'product') => 'manage_products',
            str_contains($href, 'privacy') => 'manage_privacy',
            default => 'manage_pages',
        };
    }

    private function groupForPath(string $href): string
    {
        return match (true) {
            str_contains($href, 'catalog') || str_contains($href, 'product') => 'Sprzedaż',
            str_contains($href, 'privacy') || str_contains($href, 'landing') || str_contains($href, 'trust') => 'Marketing',
            default => 'Treść',
        };
    }

    private function friendlyMenuLabel(string $label, string $href): string
    {
        $normalized = trim($label);

        return match ($normalized) {
            'Strona firmowa' => 'Strona główna',
            'Landing page' => 'Landing pages',
            'Zaufanie' => 'Opinie i referencje',
            'Katalog' => str_contains($href, 'categories') ? 'Kategorie produktów' : 'Produkty',
            'Leady' => 'Formularze',
            default => $normalized,
        };
    }

    private function adminAssetVersion(): string
    {
        $root = dirname(__DIR__, 3);
        foreach ([$root . '/public_html/assets/core/admin.css', $root . '/public/assets/core/admin.css'] as $path) {
            if (is_file($path)) {
                return (string) filemtime($path);
            }
        }

        return '1';
    }

    private function brandHtml(): string
    {
        $config = $this->appConfig();
        $clientName = (string) ($config['client_name'] ?? $config['name'] ?? 'Reklamova CMS');
        $clientLogo = $this->isCentralCmsHost() ? '' : $this->resolveClientLogo($config, $clientName);
        if ($this->isCentralCmsHost()) {
            $clientName = 'Reklamova CMS';
        }
        $clientText = '<span class="brand-client-text"' . ($clientLogo !== '' ? ' hidden' : '') . '>' . htmlspecialchars($clientName, ENT_QUOTES) . '</span>';
        $client = $clientLogo !== ''
            ? '<img class="brand-client-logo" src="' . htmlspecialchars($clientLogo, ENT_QUOTES) . '" alt="' . htmlspecialchars($clientName, ENT_QUOTES) . '" onerror="this.hidden=true;this.nextElementSibling.hidden=false">' . $clientText
            : $clientText;

        return '<a class="brand" href="/admin" aria-label="' . htmlspecialchars($clientName, ENT_QUOTES) . ' x Reklamova CMS">'
            . '<span class="brand-client">' . $client . '</span>'
            . '<span class="brand-separator">x</span>'
            . '<span class="brand-core"><img src="/assets/core/reklamova-logo.svg" alt="Reklamova"></span>'
            . '</a>';
    }

    private function appConfig(): array
    {
        $path = dirname(__DIR__, 3) . '/app/config/app.php';
        if (!is_file($path)) {
            return [];
        }

        $config = require $path;
        return is_array($config) ? $config : [];
    }

    private function resolveClientLogo(array $config, string $clientName): string
    {
        $configured = [
            $config['client_logo'] ?? '',
            $config['header_logo'] ?? '',
            $config['brand_logo'] ?? '',
            $config['logo'] ?? '',
            $config['branding']['logo'] ?? '',
            $config['theme']['logo'] ?? '',
        ];

        foreach ($configured as $logo) {
            $resolved = $this->publicLogoUrl((string) $logo);
            if ($resolved !== '') {
                return $resolved;
            }
        }

        return '';
    }

    private function publicLogoUrl(string $logo): string
    {
        $logo = trim($logo);
        if ($logo === '') {
            return '';
        }

        if (str_starts_with($logo, 'http://') || str_starts_with($logo, 'https://') || str_starts_with($logo, 'data:')) {
            return $logo;
        }

        $url = '/' . ltrim($logo, '/');
        $relative = ltrim(parse_url($url, PHP_URL_PATH) ?: '', '/');
        foreach ($this->publicRoots() as $root) {
            if (is_file($root . '/' . $relative)) {
                return $url;
            }
        }

        return '';
    }

    /**
     * @return array<int, string>
     */
    private function publicRoots(): array
    {
        $root = dirname(__DIR__, 3);
        return array_values(array_filter([$root . '/public_html', $root . '/public'], 'is_dir'));
    }

    private function isCentralCmsHost(): bool
    {
        $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));

        return $host === 'cms.reklamova.pl' || str_starts_with($host, 'cms.reklamova.pl:');
    }
}
