<?php

declare(strict_types=1);

namespace Reklamova\Cms\Admin;

use Reklamova\Cms\Auth\Csrf;
use Reklamova\Cms\Auth\PermissionManager;
use Reklamova\Cms\Content\ContentRegistry;

final class AdminView
{
    private ContentRegistry $registry;

    public function __construct(private array $extraNavigation = [], private ?PermissionManager $permissions = null)
    {
        $this->registry = new ContentRegistry();
    }

    public function render(string $title, string $content, ?array $user = null): void
    {
        header('Content-Type: text/html; charset=utf-8');
        $adminCss = '/assets/core/admin.css?v=' . rawurlencode($this->adminAssetVersion('admin.css'));
        $adminDesignCss = '/assets/core/admin-2026.css?v=' . rawurlencode($this->adminAssetVersion('admin-2026.css'));
        $adminGalleryJs = '/assets/core/admin-gallery.js?v=' . rawurlencode($this->adminAssetVersion('admin-gallery.js'));
        $adminShellJs = '/assets/core/admin-shell.js?v=' . rawurlencode($this->adminAssetVersion('admin-shell.js'));

        if (!$user) {
            echo '<!doctype html><html lang="pl"><head><meta charset="utf-8">'
                . '<meta name="viewport" content="width=device-width, initial-scale=1">'
                . '<title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . ' - Reklamova CMS</title>'
                . '<link rel="icon" type="image/svg+xml" href="/favicon.svg">'
                . '<link rel="stylesheet" href="' . $adminCss . '">'
                . '<link rel="stylesheet" href="' . $adminDesignCss . '">'
                . '</head><body>' . $content . '</body></html>';
            return;
        }

        $nav = $this->navigation($user);
        $accountLabel = $this->isInternalUser($user) ? 'Reklamova' : 'Administrator strony';
        $displayName = htmlspecialchars((string) ($user['name'] ?: $user['email']), ENT_QUOTES, 'UTF-8');
        $initials = htmlspecialchars($this->initials((string) ($user['name'] ?: $user['email'])), ENT_QUOTES, 'UTF-8');
        $account = '<details class="account-menu"><summary aria-label="Menu konta">'
            . '<span class="account-avatar" aria-hidden="true">' . $initials . '</span>'
            . '<span class="account-copy"><b>' . $displayName . '</b><small>' . htmlspecialchars($accountLabel, ENT_QUOTES, 'UTF-8') . '</small></span>'
            . '<span class="account-chevron" aria-hidden="true">' . $this->iconSvg('chevron-down') . '</span></summary>'
            . '<div class="account-popover"><div class="account-popover__identity"><b>' . $displayName . '</b><small>' . htmlspecialchars((string) ($user['email'] ?? ''), ENT_QUOTES, 'UTF-8') . '</small></div>'
            . '<a href="/admin/account">' . $this->iconSvg('user') . '<span>Ustawienia konta</span></a>'
            . '<form method="post" action="/admin/logout" class="logout">' . Csrf::field() . '<button>' . $this->iconSvg('logout') . '<span>Wyloguj</span></button></form>'
            . '</div></details>';
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/admin', PHP_URL_PATH) ?: '/admin';
        if ($path !== '/admin' && !str_contains($content, 'screen-help')) {
            $record = $this->registry->recordForRoute($path);
            if ($record) {
                $content = $this->registry->helpHtml($record, $this->isInternalUser($user) ? 'internal' : 'client') . $content;
            }
        }

        echo '<!doctype html><html lang="pl"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . ' - Reklamova CMS</title>'
            . '<link rel="icon" type="image/svg+xml" href="/favicon.svg">'
            . '<link rel="stylesheet" href="' . $adminCss . '">'
            . '<link rel="stylesheet" href="' . $adminDesignCss . '">'
            . '</head><body class="admin-body" data-admin-route="' . htmlspecialchars($path, ENT_QUOTES, 'UTF-8') . '"><div class="layout">'
            . $nav
            . '<main class="main"><header class="topbar">'
            . '<button class="sidebar-toggle" type="button" aria-controls="admin-sidebar" aria-expanded="false" data-sidebar-toggle><span class="sr-only">Otwórz menu</span>' . $this->iconSvg('menu') . '</button>'
            . '<form class="admin-search" role="search" action="/admin" data-admin-search><span aria-hidden="true">' . $this->iconSvg('search') . '</span><input type="search" name="q" autocomplete="off" placeholder="Szukaj w systemie…" aria-label="Szukaj w panelu"><kbd>⌘ K</kbd><div class="admin-search-results" data-admin-search-results hidden></div></form>'
            . '<div class="topbar-actions"><a class="view-site-link" href="/" target="_blank" rel="noopener" title="Zobacz stronę">' . $this->iconSvg('external') . '<span>Zobacz stronę</span></a>' . $account . '</div></header>'
            . '<section class="content"><header class="page-heading"><div><span class="page-heading__eyebrow">Reklamova CMS</span><h1>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1><p>' . htmlspecialchars($this->pageSubtitle($path), ENT_QUOTES, 'UTF-8') . '</p></div></header>' . $content . '</section></main>'
            . '<button class="sidebar-backdrop" type="button" aria-label="Zamknij menu" data-sidebar-backdrop></button>'
            . '</div><script src="' . $adminShellJs . '" defer></script><script src="' . $adminGalleryJs . '" defer></script></body></html>';
    }

    private function navigation(array $user): string
    {
        return '<aside class="sidebar" id="admin-sidebar">' . $this->brandHtml() . '<nav aria-label="Główna nawigacja">' . $this->groupedNavigation($user) . '</nav>'
            . '<button class="sidebar-collapse" type="button" data-sidebar-collapse>' . $this->iconSvg('collapse') . '<span>Zwiń menu</span></button></aside>';
    }

    private function groupedNavigation(array $user): string
    {
        $groups = [
            '' => [
                $this->menuItem('/admin', 'Panel główny', 'view_dashboard', 10, true, true),
            ],
            'Treści' => [
                $this->menuItem('/admin/pages', 'Podstrony', 'manage_pages', 20, true, true),
                $this->menuItem('/admin/media', 'Media', 'manage_media', 30, true, true),
            ],
            'Oferta' => [],
            'Kontakt' => [],
            'Marketing' => [],
            'Ustawienia' => [
                $this->menuItem('/admin/settings', 'Dane strony', 'manage_basic_settings', 700, true, true),
                $this->menuItem('/admin/account', 'Konto', 'view_dashboard', 710, true, true),
            ],
            'Reklamova' => [
                $this->menuItem('/admin/installations', 'Instalacje CMS', 'manage_installations', 870, false, true, $this->centralPanelEnabled()),
                $this->menuItem('/admin/modules', 'Moduły strony', 'manage_modules', 880, false, true),
                $this->menuItem('/admin/themes', 'Motyw strony', 'manage_theme', 890, false, true),
                $this->menuItem('/admin/system', 'Aktualizacje CMS', 'manage_updates', 900, false, true),
                $this->menuItem('/admin/health', 'Stan systemu', 'view_system_health', 920, false, true),
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
            $data['visible_in_client_nav'] = (bool) ($data['visible_in_client_nav'] ?? true);
            $data['visible_in_admin_nav'] = (bool) ($data['visible_in_admin_nav'] ?? true);
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
                $groupLinks .= '<a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '" data-admin-nav data-search-label="' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '"' . ($active ? ' aria-current="page"' : '') . '>'
                    . '<span class="nav-icon" aria-hidden="true">' . $this->navigationIcon($href) . '</span><span class="nav-label">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span></a>';
            }

            if ($groupLinks !== '') {
                $section = $group !== '' ? '<div class="nav-section">' . htmlspecialchars($group, ENT_QUOTES, 'UTF-8') . '</div>' : '';
                $links .= $section . $groupLinks;
            }
        }

        return $links;
    }

    /**
     * @return array<string, mixed>
     */
    private function menuItem(string $href, string $label, string $permission, int $sortOrder, bool $clientVisible, bool $adminVisible = true, bool $enabled = true): array
    {
        $record = $this->registry->recordForRoute($href) ?? [];

        return [
            'href' => $href,
            'label' => $label,
            'description' => (string) ($record['public_description'] ?? ''),
            'where_it_appears' => (string) ($record['where_it_appears'] ?? ''),
            'icon' => (string) ($record['icon'] ?? ''),
            'permission' => $permission,
            'sort_order' => $sortOrder,
            'visible_in_client_nav' => $clientVisible,
            'visible_in_admin_nav' => $adminVisible && $enabled,
            'internal_only' => !$clientVisible,
            'module' => (string) ($record['technical_slug'] ?? 'core'),
            'nav_key' => (string) ($record['nav_key'] ?? $href),
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
            str_contains($href, 'mero/leads') || str_contains($href, 'lead') => 'view_leads',
            str_contains($href, 'form') => 'manage_forms',
            str_contains($href, 'business') => 'manage_homepage',
            str_contains($href, 'knowledge') || str_contains($href, 'article') || str_contains($href, 'blog') => 'manage_blog',
            str_contains($href, 'categories') => 'manage_product_categories',
            str_contains($href, 'catalog') || str_contains($href, 'product') || str_contains($href, 'calculator') => 'manage_products',
            str_contains($href, 'landing') => 'manage_campaign_pages',
            str_contains($href, 'trust') => 'manage_reviews_trust',
            str_contains($href, 'privacy/scripts') => 'manage_privacy_scripts',
            str_contains($href, 'privacy') => 'manage_privacy_basic',
            default => 'manage_pages',
        };
    }

    private function groupForPath(string $href): string
    {
        return match (true) {
            str_contains($href, 'catalog') || str_contains($href, 'product') || str_contains($href, 'calculator') => 'Oferta',
            str_contains($href, 'lead') || str_contains($href, 'form') => 'Kontakt',
            str_contains($href, 'privacy') => 'Ustawienia',
            str_contains($href, 'landing') || str_contains($href, 'trust') => 'Marketing',
            str_contains($href, 'system') || str_contains($href, 'modules') || str_contains($href, 'themes') || str_contains($href, 'health') => 'Reklamova',
            default => 'Treści',
        };
    }

    private function friendlyMenuLabel(string $label, string $href): string
    {
        $normalized = trim($label);
        if (str_contains($href, '/admin/mero/leads') || str_contains($href, '/admin/leads')) {
            return 'Zapytania';
        }
        if (str_contains($href, '/admin/mero/articles') || str_contains($href, '/admin/knowledge')) {
            return 'Poradnik';
        }
        if (str_contains($href, '/admin/mero/calculator')) {
            return 'Kalkulator budowy';
        }

        return match ($normalized) {
            'Strony' => 'Podstrony',
            'Strona firmowa' => 'Strona główna',
            'Landing page', 'Landing pages' => 'Strony kampanii',
            'Zaufanie', 'Trust Center', 'Opinie i referencje' => 'Opinie i wiarygodność',
            'Katalog' => str_contains($href, 'categories') ? 'Kategorie produktów' : 'Produkty',
            'Leady', 'MERO Leady' => 'Zapytania',
            'MERO Poradnik' => 'Poradnik',
            'MERO Kalkulator' => 'Kalkulator budowy',
            'Ustawienia strony' => 'Dane strony',
            default => $normalized,
        };
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

    private function adminAssetVersion(string $filename): string
    {
        $root = dirname(__DIR__, 3);
        foreach ([$root . '/public_html/assets/core/' . $filename, $root . '/public/assets/core/' . $filename] as $path) {
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
        $isCentral = $this->isCentralCmsHost();
        $clientLogo = $isCentral ? '' : $this->resolveClientLogo($config, $clientName);
        $context = '';
        if (!$isCentral) {
            $contextVisual = $clientLogo !== ''
                ? '<img src="' . htmlspecialchars($clientLogo, ENT_QUOTES, 'UTF-8') . '" alt="" onerror="this.hidden=true">'
                : '<span class="sidebar-client__initial" aria-hidden="true">' . htmlspecialchars(mb_strtoupper(mb_substr($clientName, 0, 1)), ENT_QUOTES, 'UTF-8') . '</span>';
            $context = '<div class="sidebar-client">' . $contextVisual . '<span><small>Zarządzana strona</small><b>' . htmlspecialchars($clientName, ENT_QUOTES, 'UTF-8') . '</b></span></div>';
        }

        return '<div class="sidebar-brand"><a class="brand" href="/admin" aria-label="Reklamova CMS"><span class="brand-mark">R</span><span class="brand-copy"><img src="/assets/core/reklamova-logo.svg" alt="Reklamova"><small>CMS</small></span></a>' . $context . '</div>';
    }

    private function initials(string $value): string
    {
        $parts = preg_split('/\s+/u', trim($value)) ?: [];
        $initials = '';
        foreach (array_slice($parts, 0, 2) as $part) {
            $initials .= mb_strtoupper(mb_substr($part, 0, 1));
        }

        return $initials !== '' ? $initials : 'A';
    }

    private function pageSubtitle(string $path): string
    {
        return match (true) {
            $path === '/admin' || $path === '/admin/' => 'Przegląd najważniejszych informacji i szybkich działań.',
            str_starts_with($path, '/admin/pages/edit') => 'Edytuj treść, strukturę, widoczność i ustawienia SEO strony.',
            str_starts_with($path, '/admin/pages') => 'Zarządzaj wszystkimi stronami i ich publikacją.',
            str_starts_with($path, '/admin/media') => 'Zarządzaj plikami i biblioteką mediów.',
            str_starts_with($path, '/admin/catalog/products') => 'Zarządzaj ofertą, zdjęciami i informacjami produktowymi.',
            str_starts_with($path, '/admin/catalog/categories') => 'Porządkuj strukturę kategorii produktów.',
            str_contains($path, 'lead') => 'Przeglądaj wiadomości i zapytania przesłane przez klientów.',
            str_starts_with($path, '/admin/settings') => 'Konfiguruj podstawowe dane i ustawienia strony.',
            str_starts_with($path, '/admin/account') => 'Zarządzaj bezpieczeństwem i danymi swojego konta.',
            str_starts_with($path, '/admin/modules') => 'Kontroluj funkcje dostępne w tej instalacji CMS.',
            str_starts_with($path, '/admin/themes') => 'Zarządzaj warstwą wizualną strony.',
            str_starts_with($path, '/admin/system'), str_starts_with($path, '/admin/updates') => 'Sprawdź wersję CMS i dostępność aktualizacji.',
            str_starts_with($path, '/admin/health') => 'Sprawdź stan techniczny instalacji.',
            default => 'Zarządzaj zawartością i ustawieniami tej części systemu.',
        };
    }

    private function navigationIcon(string $href): string
    {
        $icon = match (true) {
            $href === '/admin' => 'dashboard',
            str_contains($href, '/pages') => 'pages',
            str_contains($href, '/media') => 'media',
            str_contains($href, 'catalog/categories') => 'categories',
            str_contains($href, 'catalog') || str_contains($href, 'product') => 'products',
            str_contains($href, 'lead') || str_contains($href, 'form') => 'message',
            str_contains($href, 'privacy') => 'shield',
            str_contains($href, 'modules') => 'modules',
            str_contains($href, 'themes') => 'theme',
            str_contains($href, 'health') => 'health',
            str_contains($href, 'system') || str_contains($href, 'updates') => 'updates',
            str_contains($href, 'settings') => 'settings',
            str_contains($href, 'account') => 'user',
            default => 'circle',
        };

        return $this->iconSvg($icon);
    }

    private function iconSvg(string $name): string
    {
        $paths = [
            'menu' => '<path d="M4 7h16M4 12h16M4 17h16"/>',
            'search' => '<circle cx="11" cy="11" r="6"/><path d="m16 16 4 4"/>',
            'external' => '<path d="M14 5h5v5M19 5l-8 8"/><path d="M18 13v5a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/>',
            'chevron-down' => '<path d="m8 10 4 4 4-4"/>',
            'logout' => '<path d="M10 5H6a1 1 0 0 0-1 1v12a1 1 0 0 0 1 1h4M14 8l4 4-4 4M9 12h9"/>',
            'collapse' => '<path d="m13 7-5 5 5 5M18 7l-5 5 5 5"/>',
            'dashboard' => '<rect x="4" y="4" width="6" height="6" rx="1"/><rect x="14" y="4" width="6" height="6" rx="1"/><rect x="4" y="14" width="6" height="6" rx="1"/><rect x="14" y="14" width="6" height="6" rx="1"/>',
            'pages' => '<path d="M7 3h8l4 4v14H7z"/><path d="M15 3v5h5M10 12h6M10 16h6"/>',
            'media' => '<rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="8.5" cy="9" r="1.5"/><path d="m4 17 5-5 4 4 2-2 5 5"/>',
            'categories' => '<path d="M4 6h6M4 12h6M4 18h6M14 6h6M14 12h6M14 18h6"/>',
            'products' => '<path d="m4 8 8-4 8 4-8 4zM4 8v8l8 4 8-4V8M12 12v8"/>',
            'message' => '<path d="M4 5h16v12H8l-4 4z"/><path d="M8 9h8M8 13h5"/>',
            'shield' => '<path d="M12 3 5 6v5c0 4.6 2.8 8 7 10 4.2-2 7-5.4 7-10V6z"/><path d="m9 12 2 2 4-5"/>',
            'modules' => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><path d="M17.5 14v7M14 17.5h7"/>',
            'theme' => '<path d="M12 3a9 9 0 1 0 0 18h1.5a2 2 0 0 0 0-4H12a1.5 1.5 0 0 1 0-3h3a6 6 0 0 0 0-12z"/><circle cx="7.5" cy="10" r="1"/><circle cx="9" cy="6.5" r="1"/><circle cx="14" cy="6" r="1"/>',
            'health' => '<path d="M3 12h4l2-5 4 10 2-5h6"/>',
            'updates' => '<path d="M20 11a8 8 0 1 0-2.3 5.7"/><path d="M20 5v6h-6"/>',
            'settings' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1-2.8 2.8-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.6v.2h-4V21a1.7 1.7 0 0 0-1-1.6 1.7 1.7 0 0 0-1.9.3l-.1.1L4.2 17l.1-.1a1.7 1.7 0 0 0 .3-1.9A1.7 1.7 0 0 0 3 14H2.8v-4H3a1.7 1.7 0 0 0 1.6-1 1.7 1.7 0 0 0-.3-1.9L4.2 7 7 4.2l.1.1a1.7 1.7 0 0 0 1.9.3A1.7 1.7 0 0 0 10 3V2.8h4V3a1.7 1.7 0 0 0 1 1.6 1.7 1.7 0 0 0 1.9-.3l.1-.1L19.8 7l-.1.1a1.7 1.7 0 0 0-.3 1.9A1.7 1.7 0 0 0 21 10h.2v4H21a1.7 1.7 0 0 0-1.6 1z"/>',
            'user' => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
            'circle' => '<circle cx="12" cy="12" r="7"/>',
        ];

        return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" focusable="false">' . ($paths[$name] ?? $paths['circle']) . '</svg>';
    }

    /**
     * @return array<string, mixed>
     */
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

        if (str_contains(strtolower($clientName), 'mero')) {
            $configured[] = 'assets/images/mero-logo.svg';
            $configured[] = 'assets/client/mero-logo.svg';
        }

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

    private function centralPanelEnabled(): bool
    {
        $config = $this->appConfig();

        return $this->isCentralCmsHost() || !empty($config['central_panel_enabled']);
    }
}
