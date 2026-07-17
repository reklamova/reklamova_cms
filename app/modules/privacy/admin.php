<?php

declare(strict_types=1);

use Reklamova\Cms\Admin\AdminView;
use Reklamova\Cms\Auth\Csrf;
use Reklamova\Cms\Modules\Privacy\Integrations\IntegrationRegistry;
use Reklamova\Cms\Modules\Privacy\PrivacyDocumentService;
use Reklamova\Cms\Modules\Privacy\PrivacyRepository;
use Reklamova\Cms\Modules\Privacy\ScriptManager;
use Reklamova\Cms\Support\Url;

require_once __DIR__ . '/src/PrivacyRepository.php';
require_once __DIR__ . '/src/ScriptManager.php';
require_once __DIR__ . '/src/ConsentModeService.php';
require_once __DIR__ . '/src/PrivacyDocumentService.php';
require_once __DIR__ . '/src/CookieRegistryService.php';
require_once __DIR__ . '/src/FormConsentService.php';
require_once __DIR__ . '/src/PrivacyAuditLogger.php';
require_once __DIR__ . '/src/Integrations/IntegrationRegistry.php';

return static function (array $container, PDO $pdo, array $module): array {
    $repo = new PrivacyRepository($pdo);
    $scriptManager = new ScriptManager($repo);
    $documents = new PrivacyDocumentService();
    $integrations = new IntegrationRegistry();

    $h = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES);
    $checked = static fn (mixed $value): string => $value ? ' checked' : '';
    $selected = static fn (mixed $value, mixed $expected): string => (string) $value === (string) $expected ? ' selected' : '';
    $jsonPretty = static fn (mixed $value): string => json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    $tabs = static function (string $active) use ($h): string {
        $items = [
            '/admin/privacy' => 'Dashboard',
            '/admin/privacy/banner' => 'Baner cookies',
            '/admin/privacy/categories' => 'Kategorie',
            '/admin/privacy/scripts' => 'Skrypty',
            '/admin/privacy/documents' => 'Dokumenty',
            '/admin/privacy/forms' => 'Formularze',
            '/admin/privacy/cookies' => 'Rejestr cookies',
            '/admin/privacy/logs' => 'Logi i audyt',
        ];
        $html = '<div class="privacy-tabs">';
        foreach ($items as $href => $label) {
            $isActive = $href === $active;
            $html .= '<a class="button ' . ($isActive ? '' : 'secondary') . '" href="' . $h($href) . '"' . ($isActive ? ' aria-current="page"' : '') . '>' . $h($label) . '</a>';
        }
        return $html . '</div>';
    };

    $metrics = static function (string $label, string $value) use ($h): string {
        return '<div class="metric"><span>' . $h($label) . '</span><b>' . $h($value) . '</b></div>';
    };

    $saveBanner = static function (?array $user) use ($repo): void {
        foreach ([
            'module_enabled',
            'allow_client_custom_scripts',
            'emergency_disable_external_scripts',
            'test_mode_admin_always_show',
            'debug_mode',
        ] as $key) {
            $repo->setSetting($key, !empty($_POST[$key]));
        }
        foreach ([
            'banner_mode',
            'banner_style',
            'default_language',
            'button_accept_all',
            'button_reject_all',
            'button_customize',
            'button_save',
            'banner_title',
            'banner_text',
            'footer_privacy_label',
            'privacy_policy_version',
            'cookie_policy_version',
            'consent_ttl_days',
            'consent_log_retention_days',
            'administrator_name',
            'administrator_address',
            'administrator_nip',
            'privacy_email',
            'privacy_phone',
            'iod',
        ] as $key) {
            $repo->setSetting($key, trim((string) ($_POST[$key] ?? '')));
        }
        $repo->audit($user['id'] ?? null, 'privacy_settings.updated', 'privacy_settings', null, null, $_POST);
    };

    $categoryOptions = static function (?int $selectedId = null) use ($repo, $h, $selected): string {
        $html = '';
        foreach ($repo->categories() as $category) {
            $html .= '<option value="' . (int) $category['id'] . '"' . $selected($selectedId, $category['id']) . '>' . $h($category['name']) . ' (' . $h($category['slug']) . ')</option>';
        }
        return $html;
    };

    $dashboard = static function (AdminView $view, array $user) use ($repo, $tabs, $metrics, $h): void {
        $scripts = $repo->scripts();
        $activeScripts = array_filter($scripts, static fn (array $script): bool => (int) $script['is_active'] === 1);
        $externalScripts = array_filter($scripts, static fn (array $script): bool => (string) ($script['external_url'] ?? '') !== '');
        $documents = $repo->documents();
        $warnings = [];
        if ((bool) $repo->setting('emergency_disable_external_scripts', false)) {
            $warnings[] = 'Awaryjne wylaczenie skryptów jest aktywne.';
        }
        if (!(bool) $repo->setting('module_enabled', true)) {
            $warnings[] = 'Modul prywatności jest wylaczony. To powinien robic tylko Super Admin Reklamova.';
        }
        foreach ($scripts as $script) {
            if ($script['type'] === 'custom' && empty($script['risk_acknowledged_at'])) {
                $warnings[] = 'Custom script bez potwierdzenia ryzyka: ' . $script['name'];
            }
        }

        $consentModeDefault = [
            'ad_storage' => 'denied',
            'ad_user_data' => 'denied',
            'ad_personalization' => 'denied',
            'analytics_storage' => 'denied',
            'functionality_storage' => 'denied',
            'personalization_storage' => 'denied',
            'security_storage' => 'granted',
        ];

        $body = $tabs('/admin/privacy')
            . '<div class="grid">'
            . $metrics('Status modułu', (bool) $repo->setting('module_enabled', true) ? 'Aktywny' : 'Nieaktywny')
            . $metrics('Aktywne skrypty', (string) count($activeScripts))
            . $metrics('Skrypty zewnętrzne', (string) count($externalScripts))
            . $metrics('Dokumenty', (string) count($documents))
            . '</div>'
            . '<div class="privacy-dashboard">'
            . '<section class="panel privacy-panel-soft"><span class="eyebrow">Prywatność</span><h2>Centrum zgód jest gotowe do pracy</h2><p>Baner blokuje skrypty analityczne i marketingowe do czasu decyzji użytkownika. Ustawienia banera, dokumenty i skrypty są zarządzane z zakładek powyżej.</p><div class="actions"><a class="button" href="/admin/privacy/banner">Ustaw baner</a><a class="button secondary" href="/admin/privacy/scripts">Zarządzaj skryptami</a></div></section>'
            . '<section class="panel"><h2>Ostrzeżenia</h2>' . ($warnings ? '<ul><li>' . implode('</li><li>', array_map($h, $warnings)) . '</li></ul>' : '<p>Brak krytycznych ostrzeżeń.</p>') . '</section>'
            . '</div>'
            . '<section class="panel technical-details"><details><summary>Diagnostyka Consent Mode</summary><p>Przed decyzją użytkownika wartości marketingu i analityki są ustawione na denied, a security_storage pozostaje granted.</p><pre>' . $h(json_encode($consentModeDefault, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . '</pre></details></section>';

        $view->render('Prywatność i cookies', $body, $user);
    };

    $banner = static function (AdminView $view, array $user) use ($repo, $tabs, $h, $checked, $selected, $saveBanner): void {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && Csrf::verify($_POST['_csrf'] ?? null)) {
            $saveBanner($user);
            Url::redirect('/admin/privacy/banner');
        }
        $s = $repo->settings();
        $body = $tabs('/admin/privacy/banner')
            . '<section class="panel"><form method="post">' . Csrf::field()
            . '<div class="privacy-dashboard">'
            . '<div class="privacy-settings-grid">'
            . '<label class="field">Status modułu<select name="module_enabled"><option value="1"' . $selected($s['module_enabled'] ?? true, true) . '>aktywny</option><option value="0"' . $selected($s['module_enabled'] ?? true, false) . '>nieaktywny tylko Super Admin</option></select></label>'
            . '<label class="field">Tryb banera<select name="banner_mode"><option value="bottom_bar"' . $selected($s['banner_mode'] ?? '', 'bottom_bar') . '>dolny pasek</option><option value="modal"' . $selected($s['banner_mode'] ?? '', 'modal') . '>modal</option><option value="corner_box"' . $selected($s['banner_mode'] ?? '', 'corner_box') . '>narożny box</option></select></label>'
            . '<label class="field">Styl<select name="banner_style"><option value="minimal"' . $selected($s['banner_style'] ?? '', 'minimal') . '>minimalistyczny</option><option value="light"' . $selected($s['banner_style'] ?? '', 'light') . '>jasny</option><option value="dark"' . $selected($s['banner_style'] ?? '', 'dark') . '>ciemny</option></select></label>'
            . '<label class="field">Język<input name="default_language" value="' . $h($s['default_language'] ?? 'pl') . '"></label>'
            . '<label class="field field--wide">Tytuł banera<input name="banner_title" value="' . $h($s['banner_title'] ?? '') . '"></label>'
            . '<label class="field field--wide">Tekst banera<textarea name="banner_text">' . $h($s['banner_text'] ?? '') . '</textarea></label>'
            . '<label class="field">Akceptuję wszystko<input name="button_accept_all" value="' . $h($s['button_accept_all'] ?? '') . '"></label><label class="field">Odrzucam<input name="button_reject_all" value="' . $h($s['button_reject_all'] ?? '') . '"></label><label class="field">Dostosuj<input name="button_customize" value="' . $h($s['button_customize'] ?? '') . '"></label><label class="field">Zapisz wybór<input name="button_save" value="' . $h($s['button_save'] ?? '') . '"></label>'
            . '<label class="field">Ważność zgody<input type="number" name="consent_ttl_days" value="' . $h($s['consent_ttl_days'] ?? 365) . '"></label><label class="field">Retencja logów<input type="number" name="consent_log_retention_days" value="' . $h($s['consent_log_retention_days'] ?? 395) . '"></label><label class="field">Wersja prywatności<input name="privacy_policy_version" value="' . $h($s['privacy_policy_version'] ?? '1') . '"></label><label class="field">Wersja cookies<input name="cookie_policy_version" value="' . $h($s['cookie_policy_version'] ?? '1') . '"></label>'
            . '<label class="field field--half">Link w stopce<input name="footer_privacy_label" value="' . $h($s['footer_privacy_label'] ?? 'Ustawienia prywatności') . '"></label>'
            . '<label class="field field--switch"><input type="checkbox" name="test_mode_admin_always_show"' . $checked($s['test_mode_admin_always_show'] ?? false) . '> Tryb testowy</label><label class="field field--switch"><input type="checkbox" name="debug_mode"' . $checked($s['debug_mode'] ?? false) . '> Debug skryptów</label><label class="field field--switch"><input type="checkbox" name="allow_client_custom_scripts"' . $checked($s['allow_client_custom_scripts'] ?? false) . '> Custom scripts klienta</label>'
            . '<label class="field field--wide"><input type="checkbox" name="emergency_disable_external_scripts"' . $checked($s['emergency_disable_external_scripts'] ?? false) . '> Wyłącz awaryjnie wszystkie skrypty zewnętrzne, analityczne i marketingowe</label>'
            . '<h2 class="field field--wide">Dane do dokumentów</h2><label class="field field--half">Administrator danych<input name="administrator_name" value="' . $h($s['administrator_name'] ?? '') . '"></label><label class="field field--half">Adres<input name="administrator_address" value="' . $h($s['administrator_address'] ?? '') . '"></label><label class="field">NIP<input name="administrator_nip" value="' . $h($s['administrator_nip'] ?? '') . '"></label><label class="field">E-mail prywatności<input name="privacy_email" value="' . $h($s['privacy_email'] ?? '') . '"></label><label class="field">Telefon<input name="privacy_phone" value="' . $h($s['privacy_phone'] ?? '') . '"></label><label class="field">IOD<input name="iod" value="' . $h($s['iod'] ?? '') . '"></label>'
            . '</div>'
            . '<aside class="banner-preview"><span class="eyebrow">Podgląd</span><div class="banner-preview__box"><h2>' . $h($s['banner_title'] ?? 'Prywatność i cookies') . '</h2><p>' . $h($s['banner_text'] ?? '') . '</p><div class="banner-preview__actions"><span>' . $h($s['button_reject_all'] ?? 'Odrzucam') . '</span><span>' . $h($s['button_customize'] ?? 'Dostosuj') . '</span><span>' . $h($s['button_accept_all'] ?? 'Akceptuję wszystko') . '</span></div></div></aside>'
            . '</div><div class="actions"><button>Zapisz ustawienia</button></div></form></section>';
        $view->render('Baner cookies', $body, $user);
    };

    $categories = static function (AdminView $view, array $user) use ($repo, $tabs, $h, $checked): void {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && Csrf::verify($_POST['_csrf'] ?? null)) {
            $repo->saveCategory([
                'id' => (int) ($_POST['id'] ?? 0),
                'slug' => trim((string) ($_POST['slug'] ?? '')),
                'name' => trim((string) ($_POST['name'] ?? '')),
                'short_description' => trim((string) ($_POST['short_description'] ?? '')),
                'full_description' => trim((string) ($_POST['full_description'] ?? '')),
                'is_required' => !empty($_POST['is_required']),
                'is_active' => !empty($_POST['is_active']),
                'sort_order' => (int) ($_POST['sort_order'] ?? 0),
                'consent_mode_mapping_json' => (string) ($_POST['consent_mode_mapping_json'] ?? '{}'),
            ]);
            Url::redirect('/admin/privacy/categories');
        }
        $rows = '';
        foreach ($repo->categories() as $category) {
            $rows .= '<tr><td>' . $h($category['slug']) . '</td><td>' . $h($category['name']) . '</td><td>' . ((int) $category['is_required'] ? 'tak' : 'nie') . '</td><td>' . ((int) $category['is_active'] ? 'aktywna' : 'wylaczona') . '</td><td>' . (int) $category['sort_order'] . '</td></tr>'
                . '<tr><td colspan="5"><form method="post">' . Csrf::field() . '<input type="hidden" name="id" value="' . (int) $category['id'] . '"><div class="grid"><label>Nazwa<input name="name" value="' . $h($category['name']) . '"></label><label>Kolejność<input type="number" name="sort_order" value="' . (int) $category['sort_order'] . '"></label><label><input type="checkbox" name="is_active"' . $checked((int) $category['is_active']) . '> aktywna</label><label><input type="checkbox" name="is_required"' . $checked((int) $category['is_required']) . '> niezbędna</label></div><label>Krótki opis<input name="short_description" value="' . $h($category['short_description']) . '"></label><label>Pełny opis<textarea name="full_description">' . $h($category['full_description']) . '</textarea></label><label>Consent Mode JSON<textarea name="consent_mode_mapping_json">' . $h($category['consent_mode_mapping_json']) . '</textarea></label><button>Zapisz kategorię</button></form></td></tr>';
        }
        $body = $tabs('/admin/privacy/categories') . '<table><thead><tr><th>Identyfikator</th><th>Nazwa</th><th>Wymagana</th><th>Status</th><th>Kolejność</th></tr></thead><tbody>' . $rows . '</tbody></table>';
        $view->render('Kategorie zgod', $body, $user);
    };

    $scripts = static function (AdminView $view, array $user) use ($repo, $scriptManager, $tabs, $h): void {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && Csrf::verify($_POST['_csrf'] ?? null) && ($_POST['action'] ?? '') === 'emergency') {
            $repo->setSetting('emergency_disable_external_scripts', !empty($_POST['enabled']));
            $repo->audit($user['id'] ?? null, 'privacy_scripts.emergency_toggle', 'privacy_settings', null, null, ['enabled' => !empty($_POST['enabled'])]);
            Url::redirect('/admin/privacy/scripts');
        }
        $rows = '';
        foreach ($repo->scripts() as $script) {
            $warnings = $scriptManager->riskWarnings($script);
            $rows .= '<tr><td>' . $h($script['name']) . '<br><small>' . $h($script['provider']) . '</small></td><td>' . $h($script['category_name'] ?? '-') . '</td><td>' . $h($script['placement']) . '</td><td>' . $h($script['scope_type']) . '</td><td>' . ((int) $script['is_active'] ? 'aktywny' : 'wylaczony') . '</td><td>' . ($warnings ? '<span class="status bad">ryzyko</span>' : '<span class="status ok">ok</span>') . '</td><td><a class="button secondary" href="/admin/privacy/scripts/edit?id=' . (int) $script['id'] . '">Edytuj</a></td></tr>';
        }
        $emergency = (bool) $repo->setting('emergency_disable_external_scripts', false);
        $urlContext = isset($_GET['url']) ? (string) $_GET['url'] : '';
        $contextPanel = $urlContext !== '' ? '<section class="panel"><h2>Kontekst podstrony</h2><p>Wybrana podstrona: <b>' . $h($urlContext) . '</b></p><a class="button" href="/admin/privacy/scripts/edit?url=' . rawurlencode($urlContext) . '">Dodaj skrypt tylko tutaj</a></section>' : '';
        $body = $tabs('/admin/privacy/scripts')
            . $contextPanel
            . '<section class="panel"><form method="post">' . Csrf::field() . '<input type="hidden" name="action" value="emergency"><label><input type="checkbox" name="enabled" value="1"' . ($emergency ? ' checked' : '') . '> Wyłącz awaryjnie wszystkie skrypty zewnętrzne</label><button>Zapisz tryb awaryjny</button></form></section>'
            . '<div class="actions"><a class="button" href="/admin/privacy/scripts/edit">Dodaj skrypt</a></div><br>'
            . '<table><thead><tr><th>Nazwa</th><th>Kategoria</th><th>Miejsce</th><th>Zakres</th><th>Status</th><th>Ryzyko</th><th></th></tr></thead><tbody>' . $rows . '</tbody></table>';
        $view->render('Skrypty prywatności', $body, $user);
    };

    $scriptEdit = static function (AdminView $view, array $user) use ($repo, $scriptManager, $integrations, $tabs, $h, $checked, $selected, $categoryOptions): void {
        $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        $script = $id ? $repo->script($id) : null;
        $errors = [];
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && Csrf::verify($_POST['_csrf'] ?? null)) {
            if (($_POST['action'] ?? '') === 'rollback') {
                $repo->restoreScriptVersion((int) $_POST['script_id'], (int) $_POST['version_id'], $user);
                Url::redirect('/admin/privacy/scripts/edit?id=' . (int) $_POST['script_id']);
            }
            $data = $scriptManager->normalizeScriptInput($_POST, $script);
            $errors = $scriptManager->validate($data);
            if (!$errors) {
                $savedId = $repo->saveScript($data, $user);
                Url::redirect('/admin/privacy/scripts/edit?id=' . $savedId);
            }
        }
        $script = $id ? ($repo->script($id) ?: []) : [];
        $versions = $id ? $repo->scriptVersions($id) : [];
        $rules = implode("\n", json_decode((string) ($script['scope_rules_json'] ?? '[]'), true) ?: []);
        if (!$id && isset($_GET['url'])) {
            $rules = (string) $_GET['url'];
            $script['scope_type'] = 'selected_pages';
        }
        $excluded = implode("\n", json_decode((string) ($script['excluded_rules_json'] ?? '[]'), true) ?: []);
        $versionRows = '';
        foreach ($versions as $version) {
            $versionRows .= '<tr><td>v' . (int) $version['version_number'] . '</td><td>' . $h($version['created_at']) . '</td><td><form method="post">' . Csrf::field() . '<input type="hidden" name="action" value="rollback"><input type="hidden" name="script_id" value="' . (int) $id . '"><input type="hidden" name="version_id" value="' . (int) $version['id'] . '"><button>Przywroc</button></form></td></tr>';
        }
        $presetInfo = '';
        foreach ($integrations->all() as $integration) {
            $presetInfo .= '<li><b>' . $h($integration->name()) . '</b> - ' . $h($integration->provider() ?: 'Custom') . ', kategoria: ' . $h($integration->defaultCategory()) . ', cookies: ' . $h(implode(', ', $integration->cookies())) . '</li>';
        }
        $body = $tabs('/admin/privacy/scripts')
            . ($errors ? '<section class="panel"><ul><li>' . implode('</li><li>', array_map($h, $errors)) . '</li></ul></section>' : '')
            . '<section class="panel"><h2>Presety integracji</h2><ul>' . $presetInfo . '</ul><p>W tym MVP presety są dostępne jako klasy i generatory kodu. Pełny wizard pol integracji jest nastepnym krokiem UI.</p></section>'
            . '<section class="panel"><form method="post">' . Csrf::field()
            . '<div class="grid"><label>Nazwa<input name="name" value="' . $h($script['name'] ?? '') . '" required></label><label>Typ<select name="type"><option value="preset"' . $selected($script['type'] ?? 'custom', 'preset') . '>preset</option><option value="custom"' . $selected($script['type'] ?? 'custom', 'custom') . '>custom</option></select></label><label>Dostawca<input name="provider" value="' . $h($script['provider'] ?? '') . '"></label><label>Kategoria<select name="category_id">' . $categoryOptions(isset($script['category_id']) ? (int) $script['category_id'] : null) . '</select></label></div>'
            . '<div class="grid"><label>Miejsce<select name="placement"><option value="head"' . $selected($script['placement'] ?? 'body_end', 'head') . '>head</option><option value="body_start"' . $selected($script['placement'] ?? 'body_end', 'body_start') . '>body_start</option><option value="body_end"' . $selected($script['placement'] ?? 'body_end', 'body_end') . '>body_end</option></select></label><label>Zakres<select name="scope_type"><option value="global"' . $selected($script['scope_type'] ?? 'global', 'global') . '>cala strona</option><option value="selected_pages"' . $selected($script['scope_type'] ?? 'global', 'selected_pages') . '>wybrane podstrony</option><option value="blog"' . $selected($script['scope_type'] ?? 'global', 'blog') . '>blog/poradnik</option><option value="cart"' . $selected($script['scope_type'] ?? 'global', 'cart') . '>koszyk</option><option value="checkout"' . $selected($script['scope_type'] ?? 'global', 'checkout') . '>checkout</option><option value="thank_you"' . $selected($script['scope_type'] ?? 'global', 'thank_you') . '>podziekowanie</option><option value="urls"' . $selected($script['scope_type'] ?? 'global', 'urls') . '>konkretne URL-e</option></select></label><label>Priorytet<input type="number" name="priority" value="' . $h($script['priority'] ?? 100) . '"></label><label><input type="checkbox" name="is_active"' . $checked($script['is_active'] ?? false) . '> aktywny</label></div>'
            . '<div class="grid"><label><input type="checkbox" name="async_enabled"' . $checked($script['async_enabled'] ?? false) . '> async</label><label><input type="checkbox" name="defer_enabled"' . $checked($script['defer_enabled'] ?? false) . '> defer</label><label><input type="checkbox" name="is_test_mode"' . $checked($script['is_test_mode'] ?? false) . '> tryb testowy</label><label><input type="checkbox" name="third_country_transfer"> przekazuje dane poza EOG</label></div>'
            . '<label>URL zewnętrzny<input name="external_url" value="' . $h($script['external_url'] ?? '') . '"></label>'
            . '<label>Kod HTML/JS - w panelu jest escapowany i nie jest wykonywany<textarea name="code" spellcheck="false">' . $h($script['code'] ?? '') . '</textarea></label>'
            . '<div class="grid"><label>URL-e wlaczone, po jednym w linii<textarea name="scope_rules">' . $h($rules) . '</textarea></label><label>URL-e wykluczone, po jednym w linii<textarea name="excluded_rules">' . $h($excluded) . '</textarea></label></div>'
            . '<div class="grid"><label>Opis celu<textarea name="purpose"></textarea></label><label>Przewidywane cookies<textarea name="expected_cookies"></textarea></label><label>Czas przechowywania<textarea name="retention"></textarea></label><label>Notatka administratora<textarea name="admin_note"></textarea></label></div>'
            . '<label><input type="checkbox" name="risk_acknowledged"> Rozumiem, że wklejany kod może wpływać na prywatnosc użytkowników, szybkość strony i bezpieczeństwo. Potwierdzam, że skrypt został przypisany do właściwej kategorii zgody.</label>'
            . '<button>Zapisz skrypt</button></form></section>'
            . ($id ? '<section class="panel"><h2>Historia wersji</h2><table><thead><tr><th>Wersja</th><th>Data</th><th></th></tr></thead><tbody>' . $versionRows . '</tbody></table></section>' : '');
        $view->render($id ? 'Edycja skryptu' : 'Nowy skrypt', $body, $user);
    };

    $documentsRoute = static function (AdminView $view, array $user) use ($repo, $documents, $tabs, $h, $selected): void {
        $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && Csrf::verify($_POST['_csrf'] ?? null)) {
            $status = (string) ($_POST['status'] ?? 'draft');
            $repo->saveDocument([
                'id' => (int) ($_POST['id'] ?? 0),
                'type' => (string) ($_POST['type'] ?? 'privacy_policy'),
                'title' => trim((string) ($_POST['title'] ?? '')),
                'slug' => trim((string) ($_POST['slug'] ?? '')),
                'content' => (string) ($_POST['content'] ?? ''),
                'version' => (int) ($_POST['version'] ?? 1),
                'status' => $status,
                'published_at' => $status === 'published' ? date('Y-m-d H:i:s') : null,
            ], $user);
            Url::redirect('/admin/privacy/documents');
        }
        if ($id) {
            $doc = $repo->document($id) ?: [];
            $preview = $documents->renderTemplate((string) ($doc['content'] ?? ''), $documents->baseVariables($repo));
            $body = $tabs('/admin/privacy/documents') . '<section class="panel"><form method="post">' . Csrf::field() . '<input type="hidden" name="id" value="' . (int) $id . '"><div class="grid"><label>Typ<select name="type"><option value="privacy_policy"' . $selected($doc['type'] ?? '', 'privacy_policy') . '>polityka prywatności</option><option value="cookie_policy"' . $selected($doc['type'] ?? '', 'cookie_policy') . '>polityka cookies</option></select></label><label>Status<select name="status"><option value="draft"' . $selected($doc['status'] ?? '', 'draft') . '>Szkic</option><option value="published"' . $selected($doc['status'] ?? '', 'published') . '>Opublikowany</option></select></label><label>Wersja<input type="number" name="version" value="' . $h($doc['version'] ?? 1) . '"></label><label>Adres URL<input name="slug" value="' . $h($doc['slug'] ?? '') . '"></label></div><label>Tytuł<input name="title" value="' . $h($doc['title'] ?? '') . '"></label><label>Treść<textarea name="content">' . $h($doc['content'] ?? '') . '</textarea></label><button>Zapisz dokument</button></form></section><section class="panel"><h2>Podgląd zmiennych</h2><pre>' . $h($preview) . '</pre></section>';
            $view->render('Edycja dokumentu', $body, $user);
            return;
        }
        $rows = '';
        foreach ($repo->documents() as $doc) {
            $rows .= '<tr><td>' . $h($doc['title']) . '</td><td>' . $h($doc['type']) . '</td><td>v' . (int) $doc['version'] . '</td><td>' . $h($doc['status']) . '</td><td><a class="button secondary" href="/admin/privacy/documents?id=' . (int) $doc['id'] . '">Edytuj</a></td></tr>';
        }
        $body = $tabs('/admin/privacy/documents') . '<table><thead><tr><th>Tytuł</th><th>Typ</th><th>Wersja</th><th>Status</th><th></th></tr></thead><tbody>' . $rows . '</tbody></table>';
        $view->render('Dokumenty prywatności', $body, $user);
    };

    $forms = static function (AdminView $view, array $user) use ($repo, $tabs, $h, $checked): void {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && Csrf::verify($_POST['_csrf'] ?? null)) {
            $repo->saveFormClause([
                'id' => (int) ($_POST['id'] ?? 0),
                'name' => trim((string) ($_POST['name'] ?? '')),
                'type' => trim((string) ($_POST['type'] ?? 'contact')),
                'content' => (string) ($_POST['content'] ?? ''),
                'version' => (int) ($_POST['version'] ?? 1),
                'is_active' => !empty($_POST['is_active']),
            ], $user);
            Url::redirect('/admin/privacy/forms');
        }
        $rows = '';
        foreach ($repo->formClauses() as $clause) {
            $rows .= '<tr><td>' . $h($clause['name']) . '</td><td>' . $h($clause['type']) . '</td><td>v' . (int) $clause['version'] . '</td><td>' . ((int) $clause['is_active'] ? 'aktywna' : 'wylaczona') . '</td></tr>';
        }
        $body = $tabs('/admin/privacy/forms') . '<section class="panel"><form method="post">' . Csrf::field() . '<div class="grid"><label>Nazwa<input name="name"></label><label>Typ<input name="type" placeholder="contact/newsletter/quote/order"></label><label>Wersja<input type="number" name="version" value="1"></label><label><input type="checkbox" name="is_active" checked> aktywna</label></div><label>Klauzula<textarea name="content"></textarea></label><button>Dodaj klauzulę</button></form></section><table><thead><tr><th>Nazwa</th><th>Typ</th><th>Wersja</th><th>Status</th></tr></thead><tbody>' . $rows . '</tbody></table>';
        $view->render('Formularże i RODO', $body, $user);
    };

    $cookies = static function (AdminView $view, array $user) use ($repo, $tabs, $h, $categoryOptions): void {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && Csrf::verify($_POST['_csrf'] ?? null)) {
            $repo->saveCookie([
                'id' => (int) ($_POST['id'] ?? 0),
                'name' => trim((string) ($_POST['name'] ?? '')),
                'provider' => trim((string) ($_POST['provider'] ?? '')),
                'category_id' => (int) ($_POST['category_id'] ?? 0),
                'purpose' => trim((string) ($_POST['purpose'] ?? '')),
                'duration' => trim((string) ($_POST['duration'] ?? '')),
                'domain' => trim((string) ($_POST['domain'] ?? '')),
                'source_script_id' => (int) ($_POST['source_script_id'] ?? 0),
                'is_active' => !empty($_POST['is_active']),
            ], $user);
            Url::redirect('/admin/privacy/cookies');
        }
        $rows = '';
        foreach ($repo->cookies() as $cookie) {
            $rows .= '<tr><td>' . $h($cookie['name']) . '</td><td>' . $h($cookie['provider']) . '</td><td>' . $h($cookie['category_name'] ?? '-') . '</td><td>' . $h($cookie['duration']) . '</td><td>' . $h($cookie['domain']) . '</td></tr>';
        }
        $body = $tabs('/admin/privacy/cookies') . '<section class="panel"><form method="post">' . Csrf::field() . '<div class="grid"><label>Nazwa<input name="name"></label><label>Dostawca<input name="provider"></label><label>Kategoria<select name="category_id">' . $categoryOptions() . '</select></label><label>Czas przechowywania<input name="duration"></label><label>Domena<input name="domain"></label><label><input type="checkbox" name="is_active" checked> aktywne</label></div><label>Cel<input name="purpose"></label><button>Dodaj cookie</button></form></section><table><thead><tr><th>Nazwa</th><th>Dostawca</th><th>Kategoria</th><th>Czas</th><th>Domena</th></tr></thead><tbody>' . $rows . '</tbody></table>';
        $view->render('Rejestr cookies', $body, $user);
    };

    $logs = static function (AdminView $view, array $user) use ($repo, $tabs, $h): void {
        $auditRows = '';
        foreach ($repo->auditLog(80) as $row) {
            $auditRows .= '<tr><td>' . $h($row['created_at']) . '</td><td>' . $h($row['action']) . '</td><td>' . $h($row['entity_type']) . '</td><td>' . $h($row['entity_id']) . '</td></tr>';
        }
        $consentRows = '';
        foreach ($repo->consentLog(80) as $row) {
            $consentRows .= '<tr><td>' . $h($row['created_at']) . '</td><td>' . $h($row['consent_uuid']) . '</td><td>' . $h($row['consent_version']) . '</td><td>' . $h($row['page_url']) . '</td></tr>';
        }
        $body = $tabs('/admin/privacy/logs') . '<section class="panel"><h2>Audyt administratorów</h2><table><thead><tr><th>Data</th><th>Akcja</th><th>Encja</th><th>ID</th></tr></thead><tbody>' . $auditRows . '</tbody></table></section><section class="panel"><h2>Log decyzji użytkowników</h2><p>Log przechowuje UUID zgody, wersję, stan kategorii oraz hashe IP/user-agent zamiast danych jawnych.</p><table><thead><tr><th>Data</th><th>UUID</th><th>Wersja</th><th>URL</th></tr></thead><tbody>' . $consentRows . '</tbody></table></section>';
        $view->render('Logi prywatności', $body, $user);
    };

    return [
        'nav' => [
            '/admin/privacy' => 'Prywatność i cookies',
        ],
        'routes' => [
            '/admin/privacy' => $dashboard,
            '/admin/privacy/banner' => $banner,
            '/admin/privacy/categories' => $categories,
            '/admin/privacy/scripts' => $scripts,
            '/admin/privacy/scripts/edit' => $scriptEdit,
            '/admin/privacy/documents' => $documentsRoute,
            '/admin/privacy/forms' => $forms,
            '/admin/privacy/cookies' => $cookies,
            '/admin/privacy/logs' => $logs,
        ],
    ];
};
