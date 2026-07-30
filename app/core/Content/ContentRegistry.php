<?php

declare(strict_types=1);

namespace Reklamova\Cms\Content;

final class ContentRegistry
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public function defaults(): array
    {
        return [
            'dashboard' => $this->record('dashboard', 'Start', 'Start', 'Najważniejsze skróty do obsługi strony.', 'Panel startowy pokazuje najczęściej używane działania.', '', 'view_dashboard', true, true, 10, 'home'),
            'pages' => $this->record('pages', 'Podstrony', 'Podstrony', 'Twórz i edytuj standardowe podstrony, np. O nas, Kontakt, Oferta.', 'Podstrony pojawiają się w publicznym serwisie i w menu, jeśli je opublikujesz.', 'Treści', 'manage_pages', true, true, 20, 'file-text'),
            'media' => $this->record('media', 'Media', 'Media', 'Wgrywaj zdjęcia, pliki i materiały do wykorzystania na stronie.', 'Media są wybierane w edycji stron, produktów, artykułów i sekcji.', 'Treści', 'manage_media', true, true, 30, 'image'),
            'homepage' => $this->record('company_site_kit', 'Strona główna', 'Strona główna', 'Edytuj główne sekcje widoczne na stronie startowej.', 'Treści pojawiają się na stronie głównej serwisu.', 'Treści', 'manage_homepage', true, true, 40, 'layout-dashboard'),
            'knowledge' => $this->record('knowledge', 'Poradnik', 'Poradnik', 'Dodawaj artykuły i treści poradnikowe widoczne na stronie.', 'Artykuły pojawiają się w poradniku i mogą wspierać SEO.', 'Treści', 'manage_blog', true, true, 50, 'book-open'),
            'inquiries' => $this->record('leads', 'Zapytania', 'Zapytania', 'Wiadomości i zgłoszenia wysłane przez formularze, kalkulator oraz kampanie.', 'Zapytania trafiają tutaj z formularzy kontaktowych, kalkulatorów i landing pages.', 'Kontakt', 'view_leads', true, true, 60, 'inbox'),
            'forms' => $this->record('forms', 'Formularze', 'Formularze', 'Zarządzaj formularzami używanymi na stronie.', 'Formularze pojawiają się w sekcjach kontaktu, podstronach i kampaniach.', 'Kontakt', 'manage_forms', true, true, 70, 'clipboard-list'),
            'products' => $this->record('catalog_products', 'Produkty', 'Produkty', 'Dodawaj i edytuj produkty widoczne w katalogu.', 'Produkty pojawiają się w katalogu, kategoriach i na osobnych kartach produktu.', 'Oferta', 'manage_products', true, true, 100, 'package'),
            'product_categories' => $this->record('catalog_categories', 'Kategorie produktów', 'Kategorie produktów', 'Grupuj produkty w katalogu.', 'Kategorie budują strukturę oferty i adresy URL katalogu.', 'Oferta', 'manage_product_categories', true, true, 110, 'folders'),
            'build_calculator' => $this->record('mero_calculator', 'Kalkulator budowy', 'Kalkulator budowy', 'Ustaw stawki i parametry kalkulatora widocznego na stronie.', 'Kalkulator pojawia się na stronie kalkulatora i generuje zapytania od klientów.', 'Oferta', 'manage_products', true, true, 120, 'calculator'),
            'campaign_pages' => $this->record('landing', 'Strony kampanii', 'Strony kampanii', 'Twórz osobne strony kampanii reklamowych, np. pod Google Ads lub Meta Ads.', 'Strony kampanii działają jako niezależne landing pages i źródła zapytań.', 'Marketing', 'manage_campaign_pages', true, true, 160, 'megaphone'),
            'trust' => $this->record('trust_center', 'Opinie i wiarygodność', 'Opinie i wiarygodność', 'Zarządzaj elementami budującymi zaufanie: opiniami, certyfikatami, liczbami i logotypami.', 'Elementy pojawiają się tylko w sekcjach obsługiwanych przez motyw strony.', 'Marketing', 'manage_reviews_trust', true, true, 170, 'badge-check'),
            'privacy' => $this->record('privacy', 'Prywatność i cookies', 'Prywatność i cookies', 'Zarządzaj komunikatem cookies, zgodami i podstawowymi dokumentami prywatności.', 'Ustawienia wpływają na baner cookies, stopkę i dokumenty prywatności.', 'Ustawienia', 'manage_privacy_basic', true, true, 180, 'shield'),
            'settings' => $this->record('settings', 'Dane strony', 'Dane strony', 'Edytuj podstawowe dane strony i firmy.', 'Te informacje są używane w panelu i mogą zasilać wybrane elementy motywu.', 'Ustawienia', 'manage_basic_settings', true, true, 700, 'settings'),
            'account' => $this->record('account', 'Konto', 'Konto', 'Zmień dane konta i hasło.', 'To ustawienia Twojego dostępu do panelu.', 'Ustawienia', 'view_dashboard', true, true, 710, 'user'),
            'installations' => $this->record('installations', 'Instalacje CMS', 'Instalacje CMS', 'Lista stron podpiętych do centralnego update servera.', 'To panel Reklamova do zarządzania instalacjami klientów.', 'Reklamova', 'manage_installations', false, true, 870, 'server'),
            'modules' => $this->record('modules', 'Moduły strony', 'Moduły strony', 'Włączaj tylko funkcje, których dana strona faktycznie używa.', 'Zmiany modułów wpływają na menu klienta i dostępne ekrany panelu.', 'Reklamova', 'manage_modules', false, true, 880, 'blocks'),
            'theme' => $this->record('theme', 'Motyw strony', 'Motyw strony', 'Techniczne ustawienia motywu klienta.', 'Motyw steruje tym, gdzie i jak wyświetlają się treści.', 'Reklamova', 'manage_theme', false, true, 890, 'palette'),
            'updates' => $this->record('updates', 'Aktualizacje CMS', 'Aktualizacje CMS', 'Bezpieczna aktualizacja core CMS z backupem i rollbackiem.', 'Aktualizacja zmienia tylko chronione ścieżki core i nie dotyka motywu klienta.', 'Reklamova', 'manage_updates', false, true, 900, 'refresh-cw'),
            'health' => $this->record('health', 'Stan systemu', 'Stan systemu', 'Sprawdź PHP, bazę danych, SSL, CRON i wymagane rozszerzenia.', 'To techniczny ekran diagnostyczny Reklamova.', 'Reklamova', 'view_system_health', false, true, 920, 'activity'),
        ];
    }

    /**
     * @param array<string, mixed> $module
     * @param array<string, mixed>|string $item
     * @return array<string, mixed>
     */
    public function normalizeMenuItem(string $href, array $module, array|string $item): array
    {
        $data = is_array($item) ? $item : ['label' => (string) $item];
        $record = $this->recordForRoute($href, (string) ($module['slug'] ?? '')) ?? $this->recordForModule((string) ($module['slug'] ?? ''));

        if ($record) {
            $data = array_merge($data, $record);
            $data['label'] = (string) ($record['menu_label'] ?? $record['public_label']);
            $data['menu_group'] = (string) ($record['menu_group'] ?? 'Treści');
            $data['permission'] = (string) ($record['required_permission'] ?? 'view_dashboard');
            $data['sort_order'] = (int) ($record['sort_order'] ?? 500);
            $data['visible_in_client_nav'] = (bool) ($record['visible_in_client_nav'] ?? false);
            $data['visible_in_admin_nav'] = (bool) ($record['visible_in_reklamova_nav'] ?? true);
            $data['nav_key'] = (string) ($record['nav_key'] ?? $record['technical_slug']);
        }

        $data['href'] = $href;
        $data['module'] = (string) ($module['slug'] ?? ($data['module'] ?? 'core'));
        $data['module_source'] = (string) ($module['source'] ?? 'core');
        $data['is_site_specific'] = (bool) ($data['is_site_specific'] ?? (($module['source'] ?? '') === 'custom'));

        return $data;
    }

    public function recordForModule(string $slug): ?array
    {
        $records = $this->defaults();

        return match ($slug) {
            'business' => $records['homepage'],
            'catalog' => $records['products'],
            'knowledge' => $records['knowledge'],
            'leads' => $records['inquiries'],
            'forms' => $records['forms'],
            'landing' => $records['campaign_pages'],
            'trust' => $records['trust'],
            'privacy' => $records['privacy'],
            'updates' => $records['updates'],
            'media' => $records['media'],
            'pages' => $records['pages'],
            default => null,
        };
    }

    public function recordForRoute(string $href, string $moduleSlug = ''): ?array
    {
        $records = $this->defaults();

        return match (true) {
            $href === '/admin' => $records['dashboard'],
            $href === '/admin/pages' || str_starts_with($href, '/admin/pages/') => $records['pages'],
            $href === '/admin/media' => $records['media'],
            $href === '/admin/business' || str_starts_with($href, '/admin/business/') => $records['homepage'],
            str_starts_with($href, '/admin/knowledge') || $href === '/admin/mero/articles' => $records['knowledge'],
            $href === '/admin/leads' || $href === '/admin/mero/leads' => $records['inquiries'],
            $href === '/admin/mero/calculator' => $records['build_calculator'],
            $href === '/admin/catalog/products' => $records['products'],
            $href === '/admin/catalog/categories' => $records['product_categories'],
            str_starts_with($href, '/admin/catalog') => $records['products'],
            str_starts_with($href, '/admin/landing') => $records['campaign_pages'],
            str_starts_with($href, '/admin/trust') => $records['trust'],
            str_starts_with($href, '/admin/privacy') => $records['privacy'],
            $href === '/admin/settings' => $records['settings'],
            $href === '/admin/account' => $records['account'],
            $href === '/admin/installations' || str_starts_with($href, '/admin/installations/') => $records['installations'],
            $href === '/admin/modules' => $records['modules'],
            $href === '/admin/themes' => $records['theme'],
            $href === '/admin/system' || $href === '/admin/updates' => $records['updates'],
            $href === '/admin/health' => $records['health'],
            default => $this->recordForModule($moduleSlug),
        };
    }

    /**
     * @param array<string, mixed> $record
     */
    public function helpHtml(array $record, string $audience = 'client'): string
    {
        $title = htmlspecialchars((string) ($record['public_label'] ?? $record['menu_label'] ?? 'Ten ekran'), ENT_QUOTES, 'UTF-8');
        $description = htmlspecialchars((string) ($record['public_description'] ?? ''), ENT_QUOTES, 'UTF-8');
        $where = htmlspecialchars((string) ($record['where_it_appears'] ?? ''), ENT_QUOTES, 'UTF-8');
        $who = $audience === 'internal'
            ? 'Ten ekran jest przeznaczony dla Reklamova lub osób z odpowiednimi uprawnieniami.'
            : 'Ten ekran jest przeznaczony do codziennej obsługi strony.';

        return '<section class="panel screen-help"><div><span class="eyebrow">Co tutaj edytuję?</span><h2>' . $title . '</h2><p>' . $description . '</p></div><div><span class="eyebrow">Gdzie to się pojawi?</span><p>' . $where . '</p><small>' . htmlspecialchars($who, ENT_QUOTES, 'UTF-8') . '</small></div></section>';
    }

    /**
     * @return array<string, mixed>
     */
    private function record(string $technicalSlug, string $publicLabel, string $menuLabel, string $description, string $where, string $group, string $permission, bool $clientVisible, bool $adminVisible, int $sortOrder, string $icon): array
    {
        return [
            'technical_slug' => $technicalSlug,
            'nav_key' => $technicalSlug,
            'public_label' => $publicLabel,
            'menu_label' => $menuLabel,
            'public_description' => $description,
            'empty_state_title' => 'Brak treści',
            'empty_state_description' => $description,
            'where_it_appears' => $where,
            'preview_url_pattern' => '',
            'menu_group' => $group,
            'required_permission' => $permission,
            'visible_in_client_nav' => $clientVisible,
            'visible_in_reklamova_nav' => $adminVisible,
            'sort_order' => $sortOrder,
            'icon' => $icon,
            'is_site_specific' => false,
            'is_core' => true,
            'is_system' => !$clientVisible,
            'is_locked' => false,
        ];
    }
}
