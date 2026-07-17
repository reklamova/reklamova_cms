<?php

declare(strict_types=1);

namespace Reklamova\Cms\Admin;

use Reklamova\Cms\Auth\AuthManager;
use Reklamova\Cms\Auth\Csrf;
use Reklamova\Cms\Auth\PermissionManager;
use Reklamova\Cms\Central\CentralInstallationsService;
use Reklamova\Cms\Database\ConnectionFactory;
use Reklamova\Cms\Database\Migrator;
use Reklamova\Cms\Health\HealthCheck;
use Reklamova\Cms\Logging\ActivityLogger;
use Reklamova\Cms\Modules\ModuleManager;
use Reklamova\Cms\Pages\PageRenderer;
use Reklamova\Cms\Pages\PageRepository;
use Reklamova\Cms\Support\Config;
use Reklamova\Cms\Support\Mailer;
use Reklamova\Cms\Support\Url;
use Reklamova\Cms\Themes\ThemeManager;
use Reklamova\Cms\Updates\PackageVerifier;
use Reklamova\Cms\Updates\UpdateClient;
use Reklamova\Cms\Updates\Updater;

final class AdminController
{
    private \PDO $pdo;
    private AuthManager $auth;
    private PermissionManager $permissions;
    private ActivityLogger $activity;
    private AdminView $view;
    private ModuleManager $modules;

    public function __construct(private array $container)
    {
        $this->pdo = (new ConnectionFactory($container))->make();
        $this->auth = new AuthManager($this->pdo);
        $this->permissions = new PermissionManager($this->pdo, $container);
        $this->activity = new ActivityLogger($this->pdo, (string) ($container['root_path'] ?? 'reklamova'));
        $this->modules = new ModuleManager($container);
        $this->view = new AdminView();
    }

    public function handle(): void
    {
        $path = Url::path();

        if ($path === '/admin/login') {
            $this->login();
            return;
        }

        if ($path === '/admin/forgot-password') {
            $this->forgotPassword();
            return;
        }

        if ($path === '/admin/logout' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            if (Csrf::verify($_POST['_csrf'] ?? null)) {
                $this->auth->logout();
            }
            Url::redirect('/admin/login');
        }

        $user = $this->auth->user();
        if (!$user) {
            Url::redirect('/admin/login');
        }

        $extensions = $this->modules->adminExtensions($this->pdo);
        $this->view = new AdminView($extensions['nav'] ?? [], $this->permissions);

        $permission = $this->permissionForRoute($path);
        if ($permission !== null && !$this->permissions->can($user, $permission)) {
            $this->notFound($user);
            return;
        }

        match ($path) {
            '/admin', '/admin/' => $this->dashboard($user),
            '/admin/pages' => $this->pages($user),
            '/admin/pages/edit' => $this->editPage($user),
            '/admin/pages/preview' => $this->previewPage($user),
            '/admin/media' => $this->media($user),
            '/admin/settings' => $this->settings($user),
            '/admin/account' => $this->account($user),
            '/admin/modules' => $this->modules($user),
            '/admin/installations' => $this->installations($user),
            '/admin/installations/modules' => $this->installationModules($user),
            '/admin/themes' => $this->themes($user),
            '/admin/system' => $this->system($user),
            '/admin/updates' => $this->updates($user),
            '/admin/health' => $this->health($user),
            default => $this->handleModuleRoute($path, $user, $extensions['routes'] ?? []),
        };
    }

    private function login(): void
    {
        $error = null;
        $config = new Config($this->container);
        $siteName = (string) $config->get('app', 'name', 'Klient');
        $clientName = (string) $config->get('app', 'client_name', $siteName);
        $clientLogo = (string) $config->get('app', 'client_logo', '');
        if ($this->isCentralCmsHost()) {
            $siteName = 'Reklamova CMS';
            $clientName = 'Reklamova CMS';
            $clientLogo = '';
        }

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            if (!Csrf::verify($_POST['_csrf'] ?? null)) {
                $error = 'Sesja formularza wygasla.';
            } elseif ($this->auth->attempt((string) ($_POST['email'] ?? ''), (string) ($_POST['password'] ?? ''))) {
                Url::redirect('/admin');
            } else {
                $error = 'Nieprawidlowy email lub haslo.';
            }
        }

        $errorHtml = $error ? '<div class="error">' . htmlspecialchars($error, ENT_QUOTES) . '</div>' : '';
        $clientBrand = $clientLogo !== ''
            ? '<img class="login-client-logo" src="' . htmlspecialchars($clientLogo, ENT_QUOTES) . '" alt="' . htmlspecialchars($clientName, ENT_QUOTES) . '">'
            : '<span class="login-client-text">' . htmlspecialchars($clientName, ENT_QUOTES) . '</span>';
        $content = '<main class="auth-screen">'
            . '<section class="auth-card-wrap">'
            . '<div class="auth-brand">' . $clientBrand . '<span class="auth-brand-x">x</span><img class="auth-reklamova-logo" src="/assets/core/reklamova-logo.svg" alt="Reklamova"></div>'
            . '<section class="auth-card"><span class="auth-eyebrow">Panel administracyjny</span><h1>Logowanie</h1>' . $errorHtml
            . '<form method="post" class="auth-form">' . Csrf::field()
            . '<label>Login<input type="email" name="email" autocomplete="username" required></label>'
            . '<label>Hasło<input type="password" name="password" autocomplete="current-password" required></label>'
            . '<button>Zaloguj -></button></form>'
            . '<a class="auth-forgot" href="/admin/forgot-password">Nie pamiętam hasła &raquo;</a></section></section>'
            . '<div class="auth-corner"><img src="/assets/core/reklamova-logo.svg" alt="Reklamova"><span>panel administracyjny</span></div>'
            . '<span class="auth-copy">&copy; reklamova.pl</span>'
            . '</main>';

        (new AdminView())->render('Logowanie', $content);
    }

    private function forgotPassword(): void
    {
        $notice = null;
        $error = null;
        $config = new Config($this->container);
        $siteName = (string) $config->get('app', 'name', 'Reklamova CMS');
        $siteUrl = rtrim((string) $config->get('app', 'url', ''), '/');
        $clientName = (string) $config->get('app', 'client_name', $siteName);
        $clientLogo = (string) $config->get('app', 'client_logo', '');
        if ($this->isCentralCmsHost()) {
            $siteName = 'Reklamova CMS';
            $clientName = 'Reklamova CMS';
            $clientLogo = '';
        }

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            if (!Csrf::verify($_POST['_csrf'] ?? null)) {
                $error = 'Sesja formularza wygasła. Spróbuj ponownie.';
            } else {
                $email = trim((string) ($_POST['email'] ?? ''));
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $error = 'Podaj poprawny adres e-mail.';
                } elseif ($this->passwordResetAllowed()) {
                    $this->handlePasswordResetRequest($email, $siteName, $siteUrl);
                    $_SESSION['last_password_reset_request_at'] = time();
                    $notice = 'Jeśli ten adres jest przypisany do aktywnego konta, wysłaliśmy na niego tymczasowe hasło.';
                } else {
                    $notice = 'Jeśli ten adres jest przypisany do aktywnego konta, wyślemy na niego tymczasowe hasło. Spróbuj ponownie za chwilę.';
                }
            }
        }

        $message = $notice ? '<div class="notice">' . htmlspecialchars($notice, ENT_QUOTES) . '</div>' : '';
        $errorHtml = $error ? '<div class="error">' . htmlspecialchars($error, ENT_QUOTES) . '</div>' : '';
        $clientBrand = $clientLogo !== ''
            ? '<img class="login-client-logo" src="' . htmlspecialchars($clientLogo, ENT_QUOTES) . '" alt="' . htmlspecialchars($clientName, ENT_QUOTES) . '">'
            : '<span class="login-client-text">' . htmlspecialchars($clientName, ENT_QUOTES) . '</span>';
        $content = '<main class="auth-screen">'
            . '<section class="auth-card-wrap">'
            . '<div class="auth-brand">' . $clientBrand . '<span class="auth-brand-x">x</span><img class="auth-reklamova-logo" src="/assets/core/reklamova-logo.svg" alt="Reklamova"></div>'
            . '<section class="auth-card"><span class="auth-eyebrow">Reset hasła</span><h1>Nie pamiętasz hasła?</h1>' . $message . $errorHtml
            . '<p class="auth-help">Wpisz e-mail administratora. Jeśli konto istnieje, wyślemy jednorazowe tymczasowe hasło do logowania.</p>'
            . '<form method="post" class="auth-form">' . Csrf::field()
            . '<label>E-mail<input type="email" name="email" autocomplete="username" required></label>'
            . '<button>Wyślij tymczasowe hasło</button></form>'
            . '<a class="auth-forgot" href="/admin/login">Wróć do logowania &raquo;</a></section></section>'
            . '<div class="auth-corner"><img src="/assets/core/reklamova-logo.svg" alt="Reklamova"><span>panel administracyjny</span></div>'
            . '<span class="auth-copy">&copy; reklamova.pl</span>'
            . '</main>';

        (new AdminView())->render('Reset hasła', $content);
    }

    private function passwordResetAllowed(): bool
    {
        Csrf::startSession();
        $last = (int) ($_SESSION['last_password_reset_request_at'] ?? 0);

        return $last === 0 || (time() - $last) >= 60;
    }

    private function handlePasswordResetRequest(string $email, string $siteName, string $siteUrl): void
    {
        $user = $this->auth->activeUserByEmail($email);
        if (!$user) {
            return;
        }

        $temporaryPassword = $this->generateTemporaryPassword();
        $loginUrl = ($siteUrl !== '' ? $siteUrl : '') . '/admin/login';
        $subject = 'Tymczasowe hasło do panelu ' . $siteName;
        $body = "Dzień dobry,\n\n"
            . "otrzymaliśmy prośbę o reset hasła do panelu administracyjnego {$siteName}.\n\n"
            . "Login: {$user['email']}\n"
            . "Tymczasowe hasło: {$temporaryPassword}\n"
            . "Adres panelu: {$loginUrl}\n\n"
            . "Po zalogowaniu ustaw własne bezpieczne hasło. Jeśli to nie Ty prosisz o reset, skontaktuj się z administratorem Reklamova.\n\n"
            . "Reklamova CMS";

        if ((new Mailer($this->container))->send((string) $user['email'], $subject, $body)) {
            $this->auth->setTemporaryPassword((int) $user['id'], $temporaryPassword);
            $this->logSecurityEvent('admin_password_reset', (int) $user['id'], (string) $user['email']);
        }
    }

    private function isCentralCmsHost(): bool
    {
        $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));

        return $host === 'cms.reklamova.pl' || str_starts_with($host, 'cms.reklamova.pl:');
    }

    private function generateTemporaryPassword(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%';
        $password = '';
        $max = strlen($alphabet) - 1;
        for ($i = 0; $i < 16; $i++) {
            $password .= $alphabet[random_int(0, $max)];
        }

        return $password;
    }

    private function logSecurityEvent(string $event, int $userId, string $email): void
    {
        $logDir = $this->container['storage_path'] . '/logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0775, true);
        }

        $entry = [
            'time' => date(DATE_ATOM),
            'event' => $event,
            'user_id' => $userId,
            'email_hash' => hash('sha256', strtolower($email)),
            'ip_hash' => hash('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? '')),
        ];

        @file_put_contents($logDir . '/security.log', json_encode($entry, JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    private function dashboard(array $user): void
    {
        $pages = $this->countTable('cms_pages');
        $media = $this->countTable('cms_media');
        $leads = $this->countFirstExistingTable(['cms_leads', 'mero_leads']);
        $articles = $this->countFirstExistingTable(['knowledge_articles', 'mero_articles']);
        $config = new Config($this->container);
        $siteName = (string) $config->get('app', 'name', 'stroną');
        $displayName = (string) ($user['name'] ?: $user['email']);
        $updateStatus = (new UpdateClient($this->container))->cachedStatus() ?: [];
        $updateBody = $updateStatus['result']['body'] ?? [];
        $updateNotice = '';
        if (!empty($updateBody['update_available']) && $this->permissions->can($user, 'manage_updates')) {
            $latest = $this->h((string) ($updateBody['latest_version'] ?? 'nowa wersja'));
            $updateNotice = '<section class="panel update-card"><div><span class="eyebrow">Aktualizacja</span><h2>Nowa wersja CMS jest gotowa</h2><p>Możesz ją uruchomić jednym kliknięciem. Przed zmianą system wykona kopię bezpieczeństwa.</p></div><a class="button" href="/admin/system">Przejdź do aktualizacji ' . $latest . '</a></section>';
        } elseif (!empty($updateBody['update_available']) && $this->permissions->can($user, 'view_update_notice')) {
            $updateNotice = '<section class="panel update-card"><div><span class="eyebrow">Aktualizacja CMS</span><h2>Dostępna jest nowa wersja panelu</h2><p>Zawiera poprawki bezpieczeństwa i usprawnienia obsługi strony. Szczegóły techniczne są po stronie Reklamova.</p></div></section>';
        }

        $content = '<section class="panel dashboard-hero"><div><span class="eyebrow">Panel strony</span><h2>Witaj, ' . $this->h($displayName) . '</h2><p>Zarządzaj treścią, mediami, zapytaniami i prywatnością serwisu ' . $this->h($siteName) . '. Techniczne ustawienia core zostają po stronie Reklamova.</p></div><div class="dashboard-hero__actions"><a class="button" href="/admin/pages">Edytuj strony</a><a class="button secondary" href="/" target="_blank" rel="noopener">Zobacz stronę</a></div></section>'
            . '<div class="grid dashboard-grid">'
            . $this->metric('Podstrony', (string) $pages)
            . $this->metric('Media', (string) $media)
            . $this->metric('Zapytania', (string) $leads)
            . $this->metric('Poradnik', (string) $articles)
            . '</div>'
            . $updateNotice
            . '<section class="panel quick-panel"><div class="panel-heading"><span class="eyebrow">Szybkie akcje</span><h2>Najczęściej używane</h2></div><div class="quick-grid">'
            . $this->quickAction('/admin/pages/edit', 'Nowa podstrona', 'Dodaj stronę, ustaw URL, SEO i widoczność w menu.')
            . $this->quickAction('/admin/pages', 'Lista podstron', 'Edytuj treści, sekcje i status publikacji.')
            . $this->quickAction('/admin/media', 'Media', 'Wgraj zdjęcia, pliki i materiały do wykorzystania na stronie.')
            . $this->quickAction('/admin/privacy', 'Prywatność i cookies', 'Zarządzaj banerem, zgodami i skryptami.')
            . $this->quickAction('/admin/system', 'Aktualizacje CMS', 'Sprawdź i zainstaluj nową wersję systemu.')
            . '</div></section>';

        if (!$this->permissions->can($user, 'manage_privacy')) {
            $content = preg_replace('#<a class="quick-card" href="/admin/privacy".*?</a>#s', '', $content) ?? $content;
        }
        if (!$this->permissions->can($user, 'manage_updates')) {
            $content = preg_replace('#<a class="quick-card" href="/admin/system".*?</a>#s', '', $content) ?? $content;
        }

        $this->view->render('Dashboard', $content, $user);
    }

    private function pages(array $user): void
    {
        $repo = new PageRepository($this->pdo);
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && Csrf::verify($_POST['_csrf'] ?? null)) {
            $action = (string) ($_POST['action'] ?? '');
            $pageId = (int) ($_POST['id'] ?? 0);
            if ($action === 'duplicate') {
                $repo->duplicate($pageId, (int) $user['id']);
            }
            if ($action === 'delete') {
                $repo->delete($pageId);
            }
            Url::redirect('/admin/pages');
        }

        $rows = $repo->all();
        $published = 0;
        $drafts = 0;
        $inMenu = 0;
        foreach ($rows as $row) {
            $published += ($row['status'] ?? '') === 'published' ? 1 : 0;
            $drafts += ($row['status'] ?? '') === 'draft' ? 1 : 0;
            $inMenu += !empty($row['show_in_menu']) ? 1 : 0;
        }

        $summary = '<section class="panel system-hero page-studio-hero"><div><span class="eyebrow">Strony</span><h2>Podstrony, SEO i menu w jednym miejscu</h2><p>Twórz strony z sekcji, ustawiaj adres URL, widoczność w menu, metadane SEO i podgląd bez dotykania kodu motywu.</p></div><a class="button" href="/admin/pages/edit">Nowa strona</a></section>'
            . '<div class="grid">'
            . $this->metric('Wszystkie strony', (string) count($rows))
            . $this->metric('Opublikowane', (string) $published)
            . $this->metric('Szkice', (string) $drafts)
            . $this->metric('W menu', (string) $inMenu)
            . '</div>';
        $body = '';
        foreach ($rows as $row) {
            $url = '/' . trim((string) ($row['slug'] ?? ''), '/');
            $url = $url === '/home' ? '/' : $url;
            $body .= '<tr><td><b>' . $this->h($row['title'] ?? '') . '</b><br><small>' . $this->h($row['meta_title'] ?? '') . '</small></td>'
                . '<td><a href="' . $this->h($url) . '" target="_blank" rel="noopener">' . $this->h($url) . '</a></td>'
                . '<td>' . $this->statusPill((string) ($row['status'] ?? 'draft')) . '</td>'
                . '<td>' . $this->h($row['template'] ?? 'default') . '</td>'
                . '<td>' . (!empty($row['show_in_menu']) ? 'Tak' : 'Nie') . '</td>'
                . '<td>' . $this->h($row['updated_at'] ?? '') . '</td>'
                . '<td><div class="actions">'
                . '<a class="button secondary" href="/admin/pages/edit?id=' . (int) $row['id'] . '">Edytuj</a>'
                . '<a class="button secondary" href="/admin/pages/preview?id=' . (int) $row['id'] . '" target="_blank" rel="noopener">Podgląd</a>'
                . '<form method="post">' . Csrf::field() . '<input type="hidden" name="action" value="duplicate"><input type="hidden" name="id" value="' . (int) $row['id'] . '"><button class="secondary">Duplikuj</button></form>'
                . ((string) ($row['slug'] ?? '') !== 'home' ? '<form method="post" onsubmit="return confirm(\'Usunąć tę stronę?\')">' . Csrf::field() . '<input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="' . (int) $row['id'] . '"><button class="secondary">Usuń</button></form>' : '')
                . '</div></td></tr>';
        }

        $content = $summary . '<section class="panel"><h2>Lista podstron</h2><table class="page-table"><thead><tr><th>Tytuł</th><th>Adres</th><th>Status</th><th>Szablon</th><th>Menu</th><th>Aktualizacja</th><th></th></tr></thead><tbody>' . ($body ?: '<tr><td colspan="7">Brak podstron.</td></tr>') . '</tbody></table></section>';

        $this->view->render('Strony', $content, $user);
    }

    private function editPage(array $user): void
    {
        $repo = new PageRepository($this->pdo);
        $id = isset($_GET['id']) && $_GET['id'] !== '' ? (int) $_GET['id'] : null;
        $error = '';

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            if (!Csrf::verify($_POST['_csrf'] ?? null)) {
                $error = 'Sesja formularza wygasła. Spróbuj ponownie.';
            } else {
                try {
                    if (($_POST['action'] ?? '') === 'restore_revision') {
                        $restoredId = $repo->restoreRevision((int) ($_POST['revision_id'] ?? 0), (int) $user['id']);
                        Url::redirect('/admin/pages/edit?id=' . (int) ($restoredId ?: $id));
                    }

                    $beforePage = $id ? $repo->find($id) : null;
                    $savedId = $repo->save($_POST, $id, (int) $user['id']);
                    $this->activity->log($user, $id ? 'page.updated' : 'page.created', 'page', $savedId, $beforePage, ['title' => $_POST['title'] ?? '', 'status' => $_POST['status'] ?? 'draft']);
                    Url::redirect('/admin/pages/edit?id=' . $savedId . '&saved=1');
                } catch (\Throwable $exception) {
                    $error = $exception->getMessage();
                }
            }
        }

        $page = [
            'title' => '',
            'slug' => '',
            'excerpt' => '',
            'content' => '',
            'status' => 'draft',
            'template' => 'default',
            'meta_title' => '',
            'meta_description' => '',
            'canonical_url' => '',
            'robots' => 'index,follow',
            'featured_image' => '',
            'og_image' => '',
            'parent_id' => '',
            'sort_order' => 100,
            'show_in_menu' => 0,
            'menu_label' => '',
            'published_at' => '',
            'blocks_json' => null,
            'settings_json' => null,
            'schema_json' => null,
            'form_config_json' => null,
            'cta_config_json' => null,
            'routing_priority' => 100,
            'source_html' => '',
        ];
        if ($id) {
            $page = $repo->find($id) ?: $page;
        }

        $settings = json_decode((string) ($page['settings_json'] ?? '{}'), true);
        $settings = is_array($settings) ? $settings : [];
        $schema = json_decode((string) ($page['schema_json'] ?? '{}'), true);
        $schema = is_array($schema) ? $schema : [];
        $businessSchema = is_array($schema['local_business'] ?? null) ? $schema['local_business'] : [];
        $formConfig = json_decode((string) ($page['form_config_json'] ?? '{}'), true);
        $formConfig = is_array($formConfig) ? $formConfig : [];
        $ctaConfig = json_decode((string) ($page['cta_config_json'] ?? '{}'), true);
        $ctaConfig = is_array($ctaConfig) ? $ctaConfig : [];
        $media = $this->mediaOptions();
        $saved = isset($_GET['saved']) ? '<div class="notice">Strona została zapisana.</div>' : '';
        $errorHtml = $error !== '' ? '<div class="error">' . $this->h($error) . '</div>' : '';
        $action = '/admin/pages/edit' . ($id ? '?id=' . (int) $id : '');
        $preview = $id ? '<a class="button secondary" href="/admin/pages/preview?id=' . (int) $id . '" target="_blank" rel="noopener">Podgląd</a>' : '';
        $content = '<form method="post" action="' . $action . '" class="page-editor">' . Csrf::field()
            . '<section class="panel page-editor__panel"><div class="page-editor__head"><div><span class="eyebrow">Ustawienia strony</span><h2>Adres, status i menu</h2></div><div class="actions"><a class="button secondary" href="/admin/pages">Wróć do listy</a>' . $preview . '<button>Zapisz stronę</button></div></div>'
            . '<div class="privacy-settings-grid">'
            . '<label class="field field--half">Tytuł strony<input name="title" value="' . $this->h($page['title']) . '" required></label>'
            . '<label class="field field--half">Adres URL<input name="slug" value="' . $this->h($page['slug']) . '" placeholder="np. o-nas"></label>'
            . '<label class="field field--half">Krótki opis strony<textarea name="excerpt">' . $this->h($page['excerpt'] ?? '') . '</textarea></label>'
            . '<label class="field">Status<select name="status">' . $this->options(['draft' => 'Szkic', 'published' => 'Opublikowana', 'archived' => 'Archiwum'], (string) ($page['status'] ?? 'draft')) . '</select></label>'
            . '<label class="field">Szablon<select name="template">' . $this->options(['default' => 'Standard', 'landing' => 'Strona kampanii', 'legal' => 'Dokument prawny', 'wide' => 'Szeroka strona'], (string) ($page['template'] ?? 'default')) . '</select></label>'
            . '<label class="field">Data publikacji<input name="published_at" value="' . $this->h($page['published_at'] ?? '') . '" placeholder="YYYY-MM-DD HH:MM:SS"></label>'
            . '<label class="field">Kolejność<input type="number" name="sort_order" value="' . $this->h($page['sort_order'] ?? 100) . '"></label>'
            . '<label class="field">Priorytet routingu<input type="number" name="routing_priority" value="' . $this->h($page['routing_priority'] ?? 100) . '"></label>'
            . '<label class="field">Strona nadrzędna<select name="parent_id">' . $this->pageOptions($repo->all(), $page['parent_id'] ?? null, $id) . '</select></label>'
            . '<label class="field field--switch"><input type="checkbox" name="show_in_menu" value="1"' . (!empty($page['show_in_menu']) ? ' checked' : '') . '> Pokaż w menu</label>'
            . '<label class="field field--half">Etykieta w menu<input name="menu_label" value="' . $this->h($page['menu_label'] ?? '') . '" placeholder="domyślnie tytuł strony"></label>'
            . '<label class="field field--switch"><input type="checkbox" name="hide_title" value="1"' . (!empty($settings['hide_title']) ? ' checked' : '') . '> Ukryj nagłówek H1 renderowany przez core</label>'
            . '<label class="field">Szerokość layoutu<select name="layout_width">' . $this->options(['default' => 'Standard', 'wide' => 'Szeroko', 'narrow' => 'Wąsko'], (string) ($settings['layout_width'] ?? 'default')) . '</select></label>'
            . '</div></section>'
            . '<section class="panel page-editor__panel"><div class="page-editor__head"><div><span class="eyebrow">Konstruktor sekcji</span><h2>Budowa podstrony</h2><p>Dodaj sekcje w kolejności od góry strony. Puste sekcje zostaną pominięte.</p></div></div>'
            . '<div class="page-blocks">' . $this->renderBlockEditor($this->pageBlocksForForm($page), $media) . '</div></section>'
            . '<section class="panel page-editor__panel"><div class="page-editor__head"><div><span class="eyebrow">Import HTML</span><h2>Stan przejściowy</h2><p>Wklej istniejący HTML tylko wtedy, gdy strona nie została jeszcze przepisana na sekcje. Kod zostanie zapisany osobno i może zostać dodany jako blok HTML.</p></div></div>'
            . '<label class="field field--wide">Treść HTML legacy<textarea name="content" class="code-area">' . $this->h($page['content'] ?? '') . '</textarea></label>'
            . '<label class="field field--wide">Importowany HTML<textarea name="source_html" class="code-area">' . $this->h($page['source_html'] ?? '') . '</textarea></label>'
            . '<label class="field field--switch"><input type="checkbox" name="import_html_as_block" value="1"> Utwórz przejściowy blok HTML z importu</label></section>'
            . '<section class="panel page-editor__panel"><div class="page-editor__head"><div><span class="eyebrow">SEO</span><h2>Widoczność w Google i social media</h2></div></div>'
            . '<div class="privacy-settings-grid">'
            . '<label class="field field--half">Meta title<input name="meta_title" maxlength="190" value="' . $this->h($page['meta_title'] ?? '') . '"></label>'
            . '<label class="field">Robots<select name="robots">' . $this->options(['index,follow' => 'index, follow', 'noindex,follow' => 'noindex, follow', 'noindex,nofollow' => 'noindex, nofollow'], (string) ($page['robots'] ?? 'index,follow')) . '</select></label>'
            . '<label class="field field--wide">Meta description<textarea name="meta_description">' . $this->h($page['meta_description'] ?? '') . '</textarea></label>'
            . '<label class="field field--half">Canonical URL<input name="canonical_url" value="' . $this->h($page['canonical_url'] ?? '') . '"></label>'
            . '<label class="field field--half">Obraz wyróżniający<select name="featured_image">' . $this->mediaSelectOptions($media, (string) ($page['featured_image'] ?? '')) . '</select></label>'
            . '<label class="field field--half">Open Graph image<select name="og_image">' . $this->mediaSelectOptions($media, (string) (($page['og_image'] ?? '') ?: ($page['featured_image'] ?? ''))) . '</select></label>'
            . '</div><div class="seo-preview"><span>Podgląd wyniku</span><b>' . $this->h((string) (($page['meta_title'] ?? '') ?: ($page['title'] ?? 'Tytuł strony'))) . '</b><p>' . $this->h((string) (($page['meta_description'] ?? '') ?: ($page['excerpt'] ?? 'Opis strony pojawi się tutaj.'))) . '</p></div></section>'
            . '<section class="panel page-editor__panel"><div class="page-editor__head"><div><span class="eyebrow">Schema</span><h2>Dane strukturalne bez kodu</h2></div></div>'
            . '<div class="privacy-settings-grid">'
            . '<label class="field field--switch"><input type="checkbox" name="schema_faq_enabled" value="1"' . (!empty($settings['schema_faq_enabled']) ? ' checked' : '') . '> FAQ schema</label>'
            . '<label class="field field--switch"><input type="checkbox" name="schema_breadcrumb_enabled" value="1"' . (!empty($settings['schema_breadcrumb_enabled']) ? ' checked' : '') . '> BreadcrumbList</label>'
            . '<label class="field field--switch"><input type="checkbox" name="schema_local_business_enabled" value="1"' . (!empty($settings['schema_local_business_enabled']) ? ' checked' : '') . '> LocalBusiness</label>'
            . '<label class="field field--half">Nazwa firmy<input name="schema_business_name" value="' . $this->h($businessSchema['name'] ?? '') . '"></label>'
            . '<label class="field field--half">Telefon<input name="schema_business_phone" value="' . $this->h($businessSchema['phone'] ?? '') . '"></label>'
            . '<label class="field field--half">E-mail<input name="schema_business_email" value="' . $this->h($businessSchema['email'] ?? '') . '"></label>'
            . '<label class="field field--wide">Adres<textarea name="schema_business_address">' . $this->h($businessSchema['address'] ?? '') . '</textarea></label>'
            . '<label class="field field--wide">Własny JSON-LD<textarea name="schema_custom_jsonld" class="code-area">' . $this->h($schema['custom_jsonld'] ?? '') . '</textarea></label>'
            . '</div></section>'
            . '<section class="panel page-editor__panel"><div class="page-editor__head"><div><span class="eyebrow">Formularz i CTA</span><h2>Przypisane elementy strony</h2></div></div>'
            . '<div class="privacy-settings-grid">'
            . '<label class="field field--switch"><input type="checkbox" name="page_form_enabled" value="1"' . (!empty($formConfig['enabled']) ? ' checked' : '') . '> Formularz na tej stronie</label>'
            . '<label class="field">Typ formularza<select name="page_form_type">' . $this->options(['contact' => 'Kontakt', 'offer' => 'Zapytanie ofertowe', 'newsletter' => 'Newsletter', 'order' => 'Zamówienie'], (string) ($formConfig['type'] ?? 'contact')) . '</select></label>'
            . '<label class="field field--half">Tytuł formularza<input name="page_form_title" value="' . $this->h($formConfig['title'] ?? '') . '"></label>'
            . '<label class="field field--half">E-mail odbiorcy<input name="page_form_target_email" value="' . $this->h($formConfig['target_email'] ?? '') . '"></label>'
            . '<label class="field field--switch"><input type="checkbox" name="page_form_marketing_consent" value="1"' . (!empty($formConfig['marketing_consent']) ? ' checked' : '') . '> Checkbox zgody marketingowej</label>'
            . '<label class="field field--switch"><input type="checkbox" name="page_cta_enabled" value="1"' . (!empty($ctaConfig['enabled']) ? ' checked' : '') . '> CTA na końcu strony</label>'
            . '<label class="field">Styl CTA<select name="page_cta_variant">' . $this->options(['standard' => 'Standard', 'soft' => 'Jasny', 'dark' => 'Ciemny'], (string) ($ctaConfig['variant'] ?? 'standard')) . '</select></label>'
            . '<label class="field field--half">Tytuł CTA<input name="page_cta_title" value="' . $this->h($ctaConfig['title'] ?? '') . '"></label>'
            . '<label class="field field--half">Przycisk CTA<input name="page_cta_button_label" value="' . $this->h($ctaConfig['button_label'] ?? '') . '"></label>'
            . '<label class="field field--half">URL CTA<input name="page_cta_button_url" value="' . $this->h($ctaConfig['button_url'] ?? '') . '"></label>'
            . '<label class="field field--wide">Tekst CTA<textarea name="page_cta_text">' . $this->h($ctaConfig['text'] ?? '') . '</textarea></label>'
            . '</div></section>'
            . '<div class="sticky-save"><a class="button secondary" href="/admin/pages">Anuluj</a>' . $preview . '<button>Zapisz stronę</button></div>'
            . '</form>';

        $privacyUrl = '/' . trim((string) ($page['slug'] ?? ''), '/');
        $privacyPanel = '<section class="panel"><h2>Skrypty / Prywatność</h2><p>Sprawdź lub dodaj skrypty prywatnościowe przypisane tylko do tej podstrony. Skrypty analityczne i marketingowe pozostaną blokowane do czasu zgody użytkownika.</p>'
            . '<a class="button secondary" href="/admin/privacy/scripts?url=' . rawurlencode($privacyUrl) . '">Zobacz skrypty dla podstrony</a> '
            . '<a class="button secondary" href="/admin/privacy/scripts/edit?url=' . rawurlencode($privacyUrl) . '">Dodaj skrypt do tej podstrony</a></section>';

        $revisions = '';
        if ($id) {
            foreach ($repo->revisions($id) as $revision) {
                $revisions .= '<tr><td>v' . (int) $revision['version_number'] . '</td><td>' . $this->h($revision['title'] ?? '') . '</td><td>' . $this->h($revision['created_at'] ?? '') . '</td><td><form method="post" action="/admin/pages/edit?id=' . (int) $id . '" onsubmit="return confirm(\'Przywrócić tę wersję?\')">' . Csrf::field() . '<input type="hidden" name="action" value="restore_revision"><input type="hidden" name="revision_id" value="' . (int) $revision['id'] . '"><button class="secondary">Przywróć</button></form></td></tr>';
            }
            $privacyPanel .= '<section class="panel"><h2>Historia wersji</h2><table><thead><tr><th>Wersja</th><th>Tytuł</th><th>Data</th><th></th></tr></thead><tbody>' . ($revisions ?: '<tr><td colspan="4">Historia pojawi się po pierwszej zmianie strony.</td></tr>') . '</tbody></table></section>';
        }

        if (!$this->permissions->can($user, 'manage_advanced_seo')) {
            $content = preg_replace('#<label class="field">Robots.*?</label>#s', '', $content) ?? $content;
            $content = preg_replace('#<label class="field field--half">Canonical URL.*?</label>#s', '', $content) ?? $content;
            $content = preg_replace('#<label class="field field--half">Open Graph image.*?</label>#s', '', $content) ?? $content;
            $content = preg_replace('#<section class="panel page-editor__panel"><div class="page-editor__head"><div><span class="eyebrow">Schema.*?</div></section>#s', '', $content) ?? $content;
        }
        $content = preg_replace(
            '#(<section class="panel page-editor__panel"><div class="page-editor__head"><div><span class="eyebrow">SEO.*?</section>)#s',
            '<details class="panel page-editor__panel seo-accordion"><summary><span class="eyebrow">Ustawienia SEO</span><b>Widoczność w Google</b></summary>$1</details>',
            $content,
            1
        ) ?? $content;

        $this->view->render($id ? 'Edycja strony' : 'Nowa strona', $saved . $errorHtml . $content . $privacyPanel, $user);
    }

    private function previewPage(array $user): void
    {
        $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        $page = (new PageRepository($this->pdo))->find($id);
        if (!$page) {
            http_response_code(404);
            echo 'Nie znaleziono strony.';
            return;
        }

        $config = new Config($this->container);
        $siteName = (string) $config->get('app', 'name', 'Reklamova CMS');
        $renderer = new PageRenderer();
        $meta = $renderer->meta($page, $siteName, (string) $config->get('app', 'url', ''));

        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="pl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>Podgląd - ' . $this->h($meta['title']) . '</title><link rel="stylesheet" href="/assets/core/page.css">'
            . (string) ($meta['schema'] ?? '')
            . '<style>.preview-bar{position:sticky;top:0;z-index:20;display:flex;justify-content:space-between;gap:16px;align-items:center;padding:12px 22px;background:#090b18;color:#fff;font-family:Geologica,system-ui,sans-serif}.preview-bar a{color:#ffd34b;text-decoration:none;font-weight:700}</style>'
            . '</head><body class="cms-public"><div class="preview-bar"><span>Podgląd roboczy: ' . $this->h($page['title'] ?? '') . '</span><a href="/admin/pages/edit?id=' . (int) $id . '">Wróć do edycji</a></div>'
            . $renderer->render($page)
            . '</body></html>';
    }

    private function h(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }

    /**
     * @param array<string, string> $items
     */
    private function options(array $items, string $selected): string
    {
        $html = '';
        foreach ($items as $value => $label) {
            $html .= '<option value="' . $this->h($value) . '"' . ($value === $selected ? ' selected' : '') . '>' . $this->h($label) . '</option>';
        }

        return $html;
    }

    /**
     * @param array<int, array<string, mixed>> $pages
     */
    private function pageOptions(array $pages, mixed $selected, ?int $currentId): string
    {
        $html = '<option value="">Brak</option>';
        foreach ($pages as $page) {
            if ($currentId !== null && (int) $page['id'] === $currentId) {
                continue;
            }
            $html .= '<option value="' . (int) $page['id'] . '"' . ((int) $selected === (int) $page['id'] ? ' selected' : '') . '>' . $this->h($page['title'] ?? '') . '</option>';
        }

        return $html;
    }

    private function statusPill(string $status): string
    {
        $labels = [
            'published' => 'Opublikowana',
            'draft' => 'Szkic',
            'archived' => 'Archiwum',
        ];

        return '<span class="status-pill status-pill--' . $this->h($status) . '">' . $this->h($labels[$status] ?? $status) . '</span>';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function pageBlocksForForm(array $page): array
    {
        $blocks = json_decode((string) ($page['blocks_json'] ?? ''), true);
        $blocks = is_array($blocks) ? array_values(array_filter($blocks, 'is_array')) : [];
        while (count($blocks) < 6) {
            $blocks[] = ['type' => '', 'title' => '', 'text' => '', 'media_url' => '', 'button_label' => '', 'button_url' => '', 'items' => [], 'gallery' => [], 'html' => ''];
        }

        return array_slice($blocks, 0, 10);
    }

    /**
     * @param array<int, array<string, mixed>> $blocks
     */
    private function renderBlockEditor(array $blocks, array $media): string
    {
        $html = '';
        $typeLabels = [
            '' => 'Dodaj sekcję',
            'hero' => 'Hero - główny baner',
            'text' => 'Sekcja tekstowa',
            'image_text' => 'Obraz i tekst',
            'cards' => 'Karty / usługi',
            'faq' => 'FAQ',
            'cta' => 'CTA',
            'gallery' => 'Galeria',
            'map' => 'Mapa',
            'form' => 'Formularz',
            'html' => 'HTML przejściowy',
        ];

        foreach ($blocks as $index => $block) {
            $type = (string) ($block['type'] ?? '');
            $gallery = is_array($block['gallery'] ?? null) ? array_values($block['gallery']) : [];
            $open = $type !== '' || $index === 0 ? ' open' : '';
            $summaryTitle = $typeLabels[$type] ?? 'Sekcja ' . ($index + 1);
            $summaryHint = $type === '' ? 'Pusta sekcja nie pokaże się na stronie.' : 'Sekcja będzie renderowana w tej kolejności na podstronie.';
            $html .= '<details class="page-block-editor"' . $open . '><summary><b>Sekcja ' . ($index + 1) . ': ' . $this->h($summaryTitle) . '</b><span>' . $this->h($summaryHint) . '</span></summary>'
                . '<div class="privacy-settings-grid">'
                . '<label class="field field--half">Typ sekcji<select name="block_type[]">' . $this->options($typeLabels, $type) . '</select></label>'
                . '<label class="field field--half">Nagłówek<input name="block_title[]" value="' . $this->h($block['title'] ?? '') . '"></label>'
                . '<label class="field field--half">Obraz z Media<select name="block_media_url[]">' . $this->mediaSelectOptions($media, (string) ($block['media_url'] ?? '')) . '</select></label>'
                . '<label class="field field--wide">Tekst<textarea name="block_text[]">' . $this->h($block['text'] ?? '') . '</textarea></label>'
                . '<label class="field field--half">Przycisk - tekst<input name="block_button_label[]" value="' . $this->h($block['button_label'] ?? '') . '"></label>'
                . '<label class="field field--half">Przycisk - URL<input name="block_button_url[]" value="' . $this->h($block['button_url'] ?? '') . '"></label>'
                . '<label class="field">Typ formularza<select name="block_form_type[]">' . $this->options(['contact' => 'Kontakt', 'offer' => 'Zapytanie ofertowe', 'newsletter' => 'Newsletter', 'order' => 'Zamówienie'], (string) ($block['form_type'] ?? 'contact')) . '</select></label>'
                . '<label class="field">Styl CTA<select name="block_cta_variant[]">' . $this->options(['standard' => 'Standard', 'soft' => 'Jasny', 'dark' => 'Ciemny'], (string) ($block['cta_variant'] ?? 'standard')) . '</select></label>'
                . '<label class="field field--half">Adres mapy<input name="block_map_address[]" value="' . $this->h($block['map_address'] ?? '') . '"></label>'
                . '<label class="field field--half">URL osadzenia mapy<input name="block_map_embed_url[]" value="' . $this->h($block['map_embed_url'] ?? '') . '"></label>'
                . '<label class="field field--switch"><input type="checkbox" name="block_schema_enabled[' . $index . ']" value="1"' . (!empty($block['schema_enabled']) ? ' checked' : '') . '> Schema dla tej sekcji</label>'
                . '<label class="field field--wide">Elementy kart lub FAQ<textarea name="block_items[]" placeholder="Karty: Tytuł | opis | /link&#10;FAQ: Pytanie | odpowiedź">' . $this->h($this->blockItemsText($block)) . '</textarea></label>'
                . '<div class="field field--wide gallery-picker"><span>Galeria z Media</span><div>'
                . $this->gallerySelect($media, $index, (string) ($gallery[0]['url'] ?? ''))
                . $this->gallerySelect($media, $index, (string) ($gallery[1]['url'] ?? ''))
                . $this->gallerySelect($media, $index, (string) ($gallery[2]['url'] ?? ''))
                . $this->gallerySelect($media, $index, (string) ($gallery[3]['url'] ?? ''))
                . '</div></div>'
                . '<label class="field field--wide">HTML tej sekcji<textarea name="block_html[]" class="code-area">' . $this->h($block['html'] ?? '') . '</textarea></label>'
                . '</div></details>';
        }

        return $html;
    }

    /**
     * @return array<int, array{filename:string,path:string}>
     */
    private function mediaOptions(): array
    {
        if (!$this->tableExists('cms_media')) {
            return [];
        }

        try {
            $rows = $this->pdo->query('SELECT filename, path FROM cms_media WHERE mime_type LIKE "image/%" ORDER BY created_at DESC, id DESC LIMIT 200')->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable) {
            return [];
        }

        return array_map(static fn (array $row): array => [
            'filename' => (string) ($row['filename'] ?? ''),
            'path' => (string) ($row['path'] ?? ''),
        ], $rows ?: []);
    }

    private function mediaSelectOptions(array $media, string $selected): string
    {
        $html = '<option value="">Brak</option>';
        foreach ($media as $item) {
            $path = (string) ($item['path'] ?? '');
            if ($path === '') {
                continue;
            }
            $label = (string) (($item['filename'] ?? '') ?: $path);
            $html .= '<option value="' . $this->h($path) . '"' . ($path === $selected ? ' selected' : '') . '>' . $this->h($label) . '</option>';
        }

        if ($selected !== '' && !array_filter($media, static fn (array $item): bool => (string) ($item['path'] ?? '') === $selected)) {
            $html .= '<option value="' . $this->h($selected) . '" selected>' . $this->h($selected) . '</option>';
        }

        return $html;
    }

    private function gallerySelect(array $media, int $index, string $selected): string
    {
        return '<select name="block_gallery_media_' . $index . '[]">' . $this->mediaSelectOptions($media, $selected) . '</select>';
    }

    private function blockItemsText(array $block): string
    {
        $type = (string) ($block['type'] ?? '');
        $lines = [];
        foreach (($block['items'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            if ($type === 'faq') {
                $lines[] = (string) ($item['question'] ?? '') . ' | ' . (string) ($item['answer'] ?? '');
                continue;
            }
            $lines[] = (string) ($item['title'] ?? '') . ' | ' . (string) ($item['text'] ?? '') . ' | ' . (string) ($item['url'] ?? '');
        }

        return implode("\n", $lines);
    }

    private function media(array $user): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && Csrf::verify($_POST['_csrf'] ?? null) && isset($_FILES['upload'])) {
            $storedPath = $this->storeUpload($_FILES['upload']);
            $this->activity->log($user, 'media.uploaded', 'media', null, null, ['path' => $storedPath, 'filename' => $_FILES['upload']['name'] ?? '']);
            Url::redirect('/admin/media');
        }

        $rows = $this->pdo->query('SELECT filename, path, mime_type, size, created_at FROM cms_media ORDER BY created_at DESC')->fetchAll();
        $body = '';
        foreach ($rows as $row) {
            $body .= '<tr><td>' . htmlspecialchars($row['filename'], ENT_QUOTES) . '</td><td>' . htmlspecialchars($row['path'], ENT_QUOTES) . '</td><td>' . htmlspecialchars($row['mime_type'], ENT_QUOTES) . '</td><td>' . (int) $row['size'] . '</td><td>' . htmlspecialchars($row['created_at'], ENT_QUOTES) . '</td></tr>';
        }

        $content = '<section class="panel"><form method="post" enctype="multipart/form-data">' . Csrf::field()
            . '<label>Plik<input type="file" name="upload" required></label><button>Wgraj plik</button></form></section>'
            . '<table><thead><tr><th>Plik</th><th>Ścieżka</th><th>Typ</th><th>Rozmiar</th><th>Data</th></tr></thead><tbody>' . $body . '</tbody></table>';

        $this->view->render('Media', $content, $user);
    }

    private function settings(array $user): void
    {
        $config = new Config($this->container);
        $content = '<section class="panel system-hero"><div><span class="eyebrow">Ustawienia</span><h2>Podstawowe dane strony</h2><p>Tu pokazujemy tylko informacje potrzebne do codziennej obsługi strony.</p></div></section>'
            . '<section class="panel"><p><b>Nazwa strony:</b> ' . htmlspecialchars($config->get('app', 'name', 'Reklamova CMS'), ENT_QUOTES) . '</p>'
            . '<p><b>Adres strony:</b> ' . htmlspecialchars($config->get('app', 'url', ''), ENT_QUOTES) . '</p>'
            . ($this->isInternalAdmin($user) ? '<p><b>Motyw strony:</b> ' . htmlspecialchars($config->get('app', 'active_theme', 'client-default'), ENT_QUOTES) . '</p>' : '')
            . '</section>';

        $this->view->render('Ustawienia strony', $content, $user);
    }

    private function account(array $user): void
    {
        $notice = null;
        $error = null;

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            if (!Csrf::verify($_POST['_csrf'] ?? null)) {
                $error = 'Sesja formularza wygasła. Spróbuj ponownie.';
            } else {
                $currentPassword = (string) ($_POST['current_password'] ?? '');
                $newPassword = (string) ($_POST['new_password'] ?? '');
                $repeatPassword = (string) ($_POST['repeat_password'] ?? '');

                if (strlen($newPassword) < 12) {
                    $error = 'Nowe hasło musi mieć co najmniej 12 znaków.';
                } elseif ($newPassword !== $repeatPassword) {
                    $error = 'Powtórzone hasło nie jest takie samo.';
                } elseif (!$this->auth->changePassword((int) $user['id'], $currentPassword, $newPassword)) {
                    $error = 'Obecne hasło jest nieprawidłowe.';
                } else {
                    $notice = 'Hasło zostało zmienione.';
                    $this->logSecurityEvent('admin_password_changed', (int) $user['id'], (string) $user['email']);
                }
            }
        }

        $content = '<section class="panel account-panel">'
            . '<h2>Dane konta</h2>'
            . '<p><b>Użytkownik:</b> ' . htmlspecialchars((string) ($user['name'] ?: $user['email']), ENT_QUOTES) . '<br><b>E-mail:</b> ' . htmlspecialchars((string) $user['email'], ENT_QUOTES) . '</p>'
            . '</section>'
            . '<section class="panel account-panel"><h2>Zmień hasło</h2>'
            . ($notice ? '<div class="notice">' . htmlspecialchars($notice, ENT_QUOTES) . '</div>' : '')
            . ($error ? '<div class="error">' . htmlspecialchars($error, ENT_QUOTES) . '</div>' : '')
            . '<form method="post" class="settings-form">' . Csrf::field()
            . '<label>Obecne hasło<input type="password" name="current_password" autocomplete="current-password" required></label>'
            . '<label>Nowe hasło<input type="password" name="new_password" autocomplete="new-password" minlength="12" required></label>'
            . '<label>Powtórz nowe hasło<input type="password" name="repeat_password" autocomplete="new-password" minlength="12" required></label>'
            . '<button>Zmień hasło</button></form></section>';

        $this->view->render('Konto', $content, $user);
    }

    private function modules(array $user): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            if (!Csrf::verify($_POST['_csrf'] ?? null)) {
                $this->view->render('Moduły strony', '<section class="panel error-panel"><h2>Sesja wygasła</h2><p>Odśwież stronę i spróbuj ponownie.</p></section>', $user);
                return;
            }

            try {
                $moduleSlug = (string) ($_POST['slug'] ?? '');
                $moduleEnabled = ($_POST['action'] ?? '') === 'enable';
                $this->modules->setEnabled($this->pdo, $moduleSlug, $moduleEnabled);
                $this->activity->log($user, $moduleEnabled ? 'module.enabled' : 'module.disabled', 'module', null, null, ['slug' => $moduleSlug]);
                Url::redirect('/admin/modules?saved=1');
            } catch (\Throwable $exception) {
                $this->view->render('Moduły strony', '<section class="panel error-panel"><h2>Nie można zmienić modułu</h2><p>' . $this->h($exception->getMessage()) . '</p></section>', $user);
                return;
            }
        }

        $rows = '';
        foreach ($this->modules->modulesWithState($this->pdo) as $module) {
            $slug = (string) ($module['slug'] ?? '');
            $enabled = !empty($module['enabled']);
            $locked = !empty($module['locked']);
            $button = $locked
                ? '<span class="pill">Systemowa</span>'
                : '<form method="post" onsubmit="return confirm(\'Zmienić status tej funkcji?\')">' . Csrf::field() . '<input type="hidden" name="slug" value="' . $this->h($slug) . '"><button class="secondary" name="action" value="' . ($enabled ? 'disable' : 'enable') . '">' . ($enabled ? 'Wyłącz' : 'Włącz') . '</button></form>';
            $rows .= '<tr><td><b>' . $this->h($module['name'] ?? $slug) . '</b><br><small>' . $this->h($slug) . '</small></td>'
                . '<td>' . ($enabled ? '<span class="pill success">Aktywna</span>' : '<span class="pill">Nieaktywna</span>') . '</td>'
                . '<td>' . $this->h($module['source'] ?? 'official') . '</td>'
                . '<td>' . $this->h($module['version'] ?? '-') . '</td>'
                . '<td>' . (!empty($module['visible_in_client_nav']) ? 'Tak' : 'Nie') . '</td>'
                . '<td>' . (!empty($module['client_manageable']) ? 'Tak' : 'Nie') . '</td>'
                . '<td>' . $button . '</td></tr>';
        }

        $content = '<section class="panel system-hero"><div><span class="eyebrow">Reklamova / techniczne</span><h2>Moduły strony</h2><p>Włączaj tylko te moduły, których dana strona faktycznie używa. Moduły systemowe pozostają zablokowane, żeby nie uszkodzić instalacji.</p></div></section>'
            . '<section class="panel"><table><thead><tr><th>Moduł</th><th>Status</th><th>Typ</th><th>Wersja</th><th>Menu klienta</th><th>Klient zarządza</th><th></th></tr></thead><tbody>' . ($rows ?: '<tr><td colspan="7">Brak modułów do pokazania.</td></tr>') . '</tbody></table></section>';
        $this->view->render('Moduły strony', $content, $user);
        return;

        $modules = (new ModuleManager($this->container))->discover();
        $body = '';
        foreach ($modules as $module) {
            $body .= '<tr><td>' . htmlspecialchars($module['name'] ?? $module['slug'], ENT_QUOTES) . '</td><td>' . htmlspecialchars($module['slug'], ENT_QUOTES) . '</td><td>' . htmlspecialchars($module['version'] ?? '-', ENT_QUOTES) . '</td><td>' . htmlspecialchars($module['source'] ?? 'official', ENT_QUOTES) . '</td></tr>';
        }

        $this->view->render('Moduły', '<table><thead><tr><th>Nazwa</th><th>Slug</th><th>Wersja</th><th>Źródło</th></tr></thead><tbody>' . $body . '</tbody></table>', $user);
    }

    private function installations(array $user): void
    {
        $service = new CentralInstallationsService($this->container);
        $root = $service->updateServerRoot();
        if ($root === null) {
            $this->view->render('Instalacje CMS', '<section class="panel error-panel"><h2>Nie widzę update servera</h2><p>Ustaw w app/config/app.php pole <code>central_update_server_path</code> albo uruchom ten panel na hostingu, który ma dostęp do katalogu updates.reklamova.pl.</p></section>', $user);
            return;
        }

        $rows = '';
        foreach ($service->installations() as $installation) {
            $siteId = (string) ($installation['site_id'] ?? '');
            $domain = (string) ($installation['domain'] ?? '');
            $activeModules = is_array($installation['active_modules'] ?? null) ? $installation['active_modules'] : [];
            $policy = is_array($installation['module_policy'] ?? null) ? $installation['module_policy'] : [];
            $lastUpdate = is_array($installation['last_update'] ?? null) ? $installation['last_update'] : null;
            $updateLabel = !empty($installation['update_available']) ? '<span class="pill warning">Jest aktualizacja</span>' : '<span class="pill success">Aktualna</span>';
            $license = (string) ($installation['license_status'] ?? 'active');
            $licenseLabel = $license === 'active' ? '<span class="pill success">Aktywna</span>' : '<span class="pill">' . $this->h($license) . '</span>';
            $panelUrl = $domain !== '' ? 'https://' . $domain . '/admin' : '';

            $rows .= '<tr>'
                . '<td><b>' . $this->h($domain !== '' ? $domain : $siteId) . '</b><br><small>' . $this->h($siteId) . '</small></td>'
                . '<td>' . $this->h($installation['current_version'] ?? '') . '</td>'
                . '<td>' . $this->h($installation['latest_version'] ?? '') . '</td>'
                . '<td>' . $updateLabel . '</td>'
                . '<td>' . $licenseLabel . '<br><small>' . $this->h($installation['channel'] ?? 'stable') . '</small></td>'
                . '<td>' . $this->h($installation['php_version'] ?: 'brak danych') . '</td>'
                . '<td>' . $this->moduleSummary($activeModules) . '</td>'
                . '<td>' . ($policy ? '<span class="pill success">Ustawiona</span><br><small>' . $this->h($policy['updated_at'] ?? '') . '</small>' : '<span class="pill">Brak polityki</span>') . '</td>'
                . '<td>' . $this->h($installation['checked_at'] ?: 'brak checku') . ($lastUpdate ? '<br><small>' . $this->h($lastUpdate['event'] ?? '') . ' ' . $this->h($lastUpdate['created_at'] ?? '') . '</small>' : '') . '</td>'
                . '<td><div class="actions"><a class="button secondary" href="/admin/installations/modules?site_id=' . rawurlencode($siteId !== '' ? $siteId : $domain) . '">Moduły</a>'
                . ($panelUrl !== '' ? '<a class="button secondary" href="' . $this->h($panelUrl) . '" target="_blank" rel="noopener">Panel</a>' : '')
                . '</div></td>'
                . '</tr>';
        }

        $content = '<section class="panel system-hero"><div><span class="eyebrow">Reklamova Central</span><h2>Instalacje CMS</h2><p>To jest lista stron podpiętych do centralnego update servera. Tutaj widzisz wersje, licencje, ostatni kontakt z serwerem i politykę modułów dla konkretnej instalacji.</p></div><a class="button secondary" href="/admin/modules">Moduły tej instalacji</a></section>'
            . '<section class="panel central-note"><h2>Jak to działa</h2><p>Instalacje klientów cyklicznie odpytują updates.reklamova.pl. Panel centralny zapisuje politykę modułów w update serverze, a instalacja stosuje ją przy najbliższym checku lub aktualizacji. Motywy, uploady i konfiguracje klientów dalej są chronione.</p></section>'
            . '<section class="panel"><table class="installations-table"><thead><tr><th>Instalacja</th><th>CMS</th><th>Najnowsza</th><th>Status</th><th>Licencja</th><th>PHP</th><th>Moduły aktywne</th><th>Polityka</th><th>Ostatni kontakt</th><th></th></tr></thead><tbody>'
            . ($rows ?: '<tr><td colspan="10">Nie ma jeszcze instalacji w rejestrze update servera.</td></tr>')
            . '</tbody></table></section>';

        $this->view->render('Instalacje CMS', $content, $user);
    }

    private function installationModules(array $user): void
    {
        $siteId = trim((string) ($_GET['site_id'] ?? $_POST['site_id'] ?? ''));
        $service = new CentralInstallationsService($this->container);
        $installation = $siteId !== '' ? $service->installation($siteId) : null;
        if (!$installation) {
            $this->view->render('Moduły instalacji', '<section class="panel error-panel"><h2>Nie znaleziono instalacji</h2><p>Wróć do listy i wybierz stronę z rejestru update servera.</p><a class="button secondary" href="/admin/installations">Lista instalacji</a></section>', $user);
            return;
        }

        $available = array_filter(
            $this->modules->discover(),
            static fn (array $module): bool => (string) ($module['source'] ?? 'official') !== 'custom'
        );
        uasort($available, static fn (array $a, array $b): int => ((int) ($a['sort_order'] ?? 500)) <=> ((int) ($b['sort_order'] ?? 500)));

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            if (!Csrf::verify($_POST['_csrf'] ?? null)) {
                $this->view->render('Moduły instalacji', '<section class="panel error-panel"><h2>Sesja wygasła</h2><p>Odśwież stronę i spróbuj ponownie.</p></section>', $user);
                return;
            }

            $policyModules = [];
            foreach ($available as $slug => $module) {
                if (!empty($module['locked'])) {
                    continue;
                }
                $policyModules[(string) $slug] = isset($_POST['modules'][(string) $slug]);
            }
            $service->saveModulePolicy($siteId, $policyModules, $user);
            $this->activity->log($user, 'central.module_policy.updated', 'installation', null, null, ['site_id' => $siteId, 'modules' => $policyModules]);
            Url::redirect('/admin/installations/modules?site_id=' . rawurlencode($siteId) . '&saved=1');
        }

        $policy = $service->modulePolicy($siteId);
        $policyModules = is_array($policy['modules'] ?? null) ? $policy['modules'] : [];
        $activeModules = is_array($installation['active_modules'] ?? null) ? $installation['active_modules'] : [];
        $saved = isset($_GET['saved']) ? '<div class="notice">Polityka modułów została zapisana. Instalacja zastosuje ją po najbliższym checku update servera albo po wejściu w aktualizacje CMS.</div>' : '';
        $rows = '';
        foreach ($available as $slug => $module) {
            $locked = !empty($module['locked']);
            $currentActive = array_key_exists((string) $slug, $activeModules);
            $enabled = array_key_exists((string) $slug, $policyModules) ? (bool) $policyModules[(string) $slug] : $currentActive;
            $checkbox = $locked
                ? '<span class="pill">Systemowy</span>'
                : '<label class="module-toggle"><input type="checkbox" name="modules[' . $this->h($slug) . ']" value="1"' . ($enabled ? ' checked' : '') . '> Aktywny</label>';
            $rows .= '<tr>'
                . '<td><b>' . $this->h($module['menu_label'] ?? $module['name'] ?? $slug) . '</b><br><small>' . $this->h($module['description'] ?? '') . '</small></td>'
                . '<td><code>' . $this->h($slug) . '</code></td>'
                . '<td>' . $this->h($module['source'] ?? 'core') . '</td>'
                . '<td>' . ($currentActive ? '<span class="pill success">Aktywny teraz</span>' : '<span class="pill">Nieaktywny teraz</span>') . '</td>'
                . '<td>' . (!empty($module['visible_in_client_nav']) ? 'Tak' : 'Nie') . '</td>'
                . '<td>' . $checkbox . '</td>'
                . '</tr>';
        }

        $content = '<section class="panel system-hero"><div><span class="eyebrow">Reklamova Central</span><h2>Moduły: ' . $this->h($installation['domain'] ?: $installation['site_id']) . '</h2><p>Ustaw tutaj, które oficjalne moduły core mają być aktywne na tej instalacji. Moduły systemowe są chronione, a moduły custom klienta nie są nadpisywane przez core.</p></div><a class="button secondary" href="/admin/installations">Wróć do instalacji</a></section>'
            . $saved
            . '<section class="panel central-note"><h2>Aktualny raport instalacji</h2><p><b>CMS:</b> ' . $this->h($installation['current_version'] ?? '') . ' / <b>PHP:</b> ' . $this->h($installation['php_version'] ?: 'brak danych') . ' / <b>Ostatni check:</b> ' . $this->h($installation['checked_at'] ?: 'brak danych') . '</p><p><b>Aktywne moduły zgłoszone przez instalację:</b> ' . $this->moduleSummary($activeModules) . '</p></section>'
            . '<form method="post" class="panel">' . Csrf::field() . '<input type="hidden" name="site_id" value="' . $this->h($siteId) . '">'
            . '<table class="installations-table"><thead><tr><th>Moduł</th><th>Slug</th><th>Typ</th><th>Status lokalny</th><th>Menu klienta</th><th>Polityka centralna</th></tr></thead><tbody>' . $rows . '</tbody></table>'
            . '<div class="actions form-actions"><button>Zapisz politykę modułów</button><a class="button secondary" href="/admin/installations">Anuluj</a></div></form>';

        $this->view->render('Moduły instalacji', $content, $user);
    }

    private function themes(array $user): void
    {
        $themes = (new ThemeManager($this->container))->discover();
        $body = '';
        foreach ($themes as $theme) {
            $body .= '<tr><td>' . htmlspecialchars($theme['name'] ?? $theme['slug'], ENT_QUOTES) . '</td><td>' . htmlspecialchars($theme['slug'], ENT_QUOTES) . '</td><td>' . htmlspecialchars($theme['version'] ?? '-', ENT_QUOTES) . '</td><td>' . htmlspecialchars($theme['type'] ?? '-', ENT_QUOTES) . '</td></tr>';
        }

        $this->view->render('Motyw', '<table><thead><tr><th>Nazwa</th><th>Slug</th><th>Wersja</th><th>Typ</th></tr></thead><tbody>' . $body . '</tbody></table>', $user);
    }

    private function updates(array $user): void
    {
        $this->system($user);
    }

    private function system(array $user): void
    {
        $client = new UpdateClient($this->container);
        $status = $client->localStatus();
        $result = null;
        $error = null;

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && Csrf::verify($_POST['_csrf'] ?? null)) {
            $action = (string) ($_POST['action'] ?? 'check');
            try {
                $health = (new HealthCheck($this->container))->run();
                $modules = $this->activeModuleVersions();
                $check = $client->check($health, $modules);
                $result = ['check' => $check];
                $policy = $check['body']['module_policy'] ?? null;
                if (is_array($policy) && !empty($policy['modules']) && is_array($policy['modules'])) {
                    $result['module_policy'] = $this->modules->applyCentralPolicy($this->pdo, $policy);
                    (new Migrator($this->container))->runActiveModuleMigrations();
                }

                if ($action === 'dry_run') {
                    $package = $check['body']['package'] ?? null;
                    if (empty($check['body']['update_available']) || !is_array($package)) {
                        throw new \RuntimeException('Brak dostępnej aktualizacji do sprawdzenia.');
                    }

                    $zipPath = $client->downloadPackage($package);
                    $trustedKeys = require $this->container['app_path'] . '/core/Updates/trusted_keys.php';
                    $channel = $status['update_channel'] ?? 'stable';
                    $publicKey = (string) ($trustedKeys[$channel] ?? '');
                    $manifest = (new PackageVerifier($publicKey))->verify(
                        $zipPath,
                        (string) ($package['sha256'] ?? ''),
                        (string) ($package['signature'] ?? '')
                    );
                    $result['dry_run'] = (new Updater($this->container))->dryRun($zipPath, array_merge($package, $manifest));
                }

                if ($action === 'apply') {
                    $package = $check['body']['package'] ?? null;
                    if (empty($check['body']['update_available']) || !is_array($package)) {
                        throw new \RuntimeException('Brak dostępnej aktualizacji dla tej instalacji.');
                    }

                    $client->report('update-started', ['package' => $package]);
                    $zipPath = $client->downloadPackage($package);
                    $trustedKeys = require $this->container['app_path'] . '/core/Updates/trusted_keys.php';
                    $channel = $status['update_channel'] ?? 'stable';
                    $publicKey = (string) ($trustedKeys[$channel] ?? '');
                    $manifest = (new PackageVerifier($publicKey))->verify(
                        $zipPath,
                        (string) ($package['sha256'] ?? ''),
                        (string) ($package['signature'] ?? '')
                    );

                    $apply = (new Updater($this->container))->apply($zipPath, array_merge($package, $manifest));
                    $client->report('update-finished', ['package' => $package, 'result' => $apply]);
                    $this->activity->log($user, 'cms.updated', 'update', null, ['version' => $this->container['cms_version']], ['package' => $package, 'result' => $apply]);
                    $result['apply'] = $apply;
                    Url::redirect('/admin/system?updated=1');
                }
            } catch (\Throwable $exception) {
                $error = $exception->getMessage();
                $client->report('update-failed', ['error' => $error]);
            }
        }

        $health = (new HealthCheck($this->container))->run();
        $cached = $client->cachedStatus() ?: [];
        $updateBody = $result['check']['body'] ?? $cached['result']['body'] ?? [];
        $currentVersion = (string) $status['cms_version'];
        $latestVersion = (string) ($updateBody['latest_version'] ?? $currentVersion);
        $updateAvailable = !empty($updateBody['update_available']) && version_compare($latestVersion, $currentVersion, '>');
        $checkState = $updateAvailable ? 'Dostępna jest wersja ' . $latestVersion : 'CMS jest aktualny';
        $backupStatus = !empty($health['writable_paths']['app/storage']) ? 'Kopia bezpieczeństwa gotowa' : 'Wymaga pomocy Reklamova';
        $connectionStatus = !empty($cached['result']['status']) && (int) $cached['result']['status'] === 200 ? 'Połączono z serwerem aktualizacji' : 'Gotowe do sprawdzenia';
        $lastCheckedAt = (string) ($cached['checked_at'] ?? 'Jeszcze nie sprawdzano');

        $updatedNotice = isset($_GET['updated']) ? '<section class="panel notice-panel"><h2>Aktualizacja zakończona</h2><p>CMS został zaktualizowany i panel został automatycznie odświeżony.</p></section>' : '';

        $content = $updatedNotice . '<section class="panel system-hero update-hero"><div><span class="eyebrow">Aktualizacje CMS</span><h2>' . $this->h($checkState) . '</h2><p>Aktualizacja pobierze nową wersję core Reklamova CMS, wykona kopię bezpieczeństwa i sprawdzi stronę po zakończeniu.</p></div>'
            . '<form method="post" class="actions update-form" data-update-form>' . Csrf::field()
            . '<button name="action" value="check" data-progress-title="Sprawdzam aktualizacje" data-progress-message="Łączę się z serwerem aktualizacji Reklamova i sprawdzam dostępne wersje.">Sprawdź aktualizację</button>'
            . '<button name="action" value="apply" data-progress-title="Trwa aktualizacja CMS" data-progress-message="Pobieram paczkę, wykonuję kopię bezpieczeństwa i wdrażam nową wersję. Nie zamykaj tej karty.">Zaktualizuj CMS</button>'
            . '</form></section>'
            . '<div class="grid update-grid">'
            . $this->metric('Obecna wersja', (string) $status['cms_version'])
            . $this->metric('Najnowsza wersja', $latestVersion)
            . $this->metric('Status kopii', $backupStatus)
            . $this->metric('Serwer aktualizacji', $connectionStatus)
            . '</div>'
            . '<section class="panel update-summary"><h2>Ostatnie sprawdzenie</h2><p>' . $this->h($lastCheckedAt) . '</p></section>';
        $content = str_replace(
            '<button name="action" value="apply"',
            '<button name="action" value="dry_run" data-progress-title="Sprawdzam bezpieczeństwo aktualizacji" data-progress-message="Pobieram paczkę testowo i sprawdzam wymagania bez zmiany plików.">Test aktualizacji</button><button name="action" value="apply"',
            $content
        );

        if ($error) {
            $content .= '<section class="panel error-panel"><h2>Aktualizacja została przerwana</h2><p>Dane strony nie zostały usunięte. Jeśli komunikat powtórzy się po ponownej próbie, skontaktuj się z Reklamova.</p>'
                . ($this->isInternalAdmin($user) ? '<pre>' . $this->h($error) . '</pre>' : '')
                . '</section>';
        }

        if ($result !== null && !$error) {
            $content .= '<section class="panel notice-panel"><h2>Gotowe</h2><p>' . ($updateAvailable ? 'Aktualizacja jest gotowa do uruchomienia.' : 'Ta instalacja działa na aktualnej wersji CMS.') . '</p></section>';
        }

        if ($this->isInternalAdmin($user)) {
            $content .= '<section class="panel technical-details"><details><summary>Informacje techniczne Reklamova</summary>'
                . '<h3>Status lokalny</h3><pre>' . $this->h(json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . '</pre>'
                . '<h3>Health check</h3><pre>' . $this->h(json_encode($health, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . '</pre>'
                . '<h3>Ostatnie sprawdzenie aktualizacji</h3><pre>' . $this->h(json_encode($cached, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . '</pre>'
                . ($result !== null ? '<h3>Wynik bieżącej operacji</h3><pre>' . $this->h(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . '</pre>' : '')
                . '</details></section>';
        }

        $content .= $this->updateProgressScript();

        $this->view->render('Aktualizacje CMS', $content, $user);
    }

    private function health(array $user): void
    {
        $this->system($user);
    }

    private function notFound(array $user): void
    {
        http_response_code(404);
        $this->view->render('Nie znaleziono', '<section class="panel"><p>Nie znaleziono ekranu panelu.</p></section>', $user);
    }

    private function handleModuleRoute(string $path, array $user, array $routes): void
    {
        $handler = $routes[$path] ?? null;
        if (is_callable($handler)) {
            $permission = $this->permissionForModuleRoute($path);
            if (!$this->permissions->can($user, $permission)) {
                $this->notFound($user);
                return;
            }

            $handler($this->view, $user);
            return;
        }

        $this->notFound($user);
    }

    private function isTechnicalRoute(string $path): bool
    {
        return in_array($path, ['/admin/settings', '/admin/modules', '/admin/installations', '/admin/installations/modules', '/admin/themes', '/admin/health'], true);
    }

    private function permissionForRoute(string $path): ?string
    {
        return match ($path) {
            '/admin', '/admin/' => 'view_dashboard',
            '/admin/pages', '/admin/pages/edit', '/admin/pages/preview' => 'manage_pages',
            '/admin/media' => 'manage_media',
            '/admin/settings' => 'manage_basic_settings',
            '/admin/modules' => 'manage_modules',
            '/admin/installations', '/admin/installations/modules' => 'manage_installations',
            '/admin/themes' => 'manage_themes',
            '/admin/system', '/admin/updates' => 'manage_updates',
            '/admin/health' => 'view_health',
            '/admin/account' => 'view_dashboard',
            default => null,
        };
    }

    private function permissionForModuleRoute(string $path): string
    {
        return match (true) {
            str_contains($path, 'lead') || str_contains($path, 'form') => 'manage_forms',
            str_contains($path, 'knowledge') || str_contains($path, 'article') || str_contains($path, 'blog') => 'manage_blog',
            str_contains($path, 'catalog') || str_contains($path, 'product') => 'manage_products',
            str_contains($path, 'privacy/scripts') => 'manage_privacy_scripts',
            str_contains($path, 'privacy') => 'manage_privacy',
            default => 'manage_pages',
        };
    }

    private function isInternalAdmin(array $user): bool
    {
        return $this->permissions->isInternalUser($user);
    }

    private function countTable(string $table): int
    {
        if (!$this->tableExists($table)) {
            return 0;
        }

        return (int) $this->pdo->query('SELECT COUNT(*) FROM `' . str_replace('`', '', $table) . '`')->fetchColumn();
    }

    /**
     * @param array<int, string> $tables
     */
    private function countFirstExistingTable(array $tables): int
    {
        foreach ($tables as $table) {
            if ($this->tableExists($table)) {
                return $this->countTable($table);
            }
        }

        return 0;
    }

    private function tableExists(string $table): bool
    {
        try {
            $statement = $this->pdo->prepare('SHOW TABLES LIKE ?');
            $statement->execute([$table]);
            return (bool) $statement->fetchColumn();
        } catch (\Throwable) {
            return false;
        }
    }

    private function quickAction(string $href, string $title, string $description): string
    {
        return '<a class="quick-card" href="' . $this->h($href) . '"><b>' . $this->h($title) . '</b><span>' . $this->h($description) . '</span></a>';
    }

    private function updateProgressScript(): string
    {
        return <<<'HTML'
<script>
document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('[data-update-form]').forEach(function (form) {
    form.addEventListener('submit', function (event) {
      if (form.dataset.busy === '1') {
        event.preventDefault();
        return;
      }

      var submitter = event.submitter || form.querySelector('button[name="action"]');
      var action = submitter && submitter.value ? submitter.value : 'check';
      var hidden = form.querySelector('input[type="hidden"][name="action"]');
      if (!hidden) {
        hidden = document.createElement('input');
        hidden.type = 'hidden';
        hidden.name = 'action';
        form.appendChild(hidden);
      }
      hidden.value = action;

      var title = submitter && submitter.dataset.progressTitle ? submitter.dataset.progressTitle : 'Pracuję nad aktualizacją';
      var message = submitter && submitter.dataset.progressMessage ? submitter.dataset.progressMessage : 'To może potrwać chwilę.';
      var overlay = document.querySelector('[data-update-progress]');
      if (!overlay) {
        overlay = document.createElement('div');
        overlay.className = 'update-progress';
        overlay.setAttribute('data-update-progress', '');
        overlay.setAttribute('role', 'status');
        overlay.setAttribute('aria-live', 'polite');
        overlay.innerHTML = '<section class="update-progress__card"><div class="update-progress__spinner" aria-hidden="true"></div><span class="eyebrow">Reklamova CMS</span><h2 data-update-progress-title></h2><p data-update-progress-message></p><div class="update-progress__bar" aria-hidden="true"><span></span></div><ol><li data-step="download">Pobranie paczki</li><li data-step="backup">Kopia bezpieczeństwa</li><li data-step="files">Aktualizacja core</li><li data-step="health">Sprawdzenie strony</li></ol></section>';
        document.body.appendChild(overlay);
      }

      overlay.querySelector('[data-update-progress-title]').textContent = title;
      overlay.querySelector('[data-update-progress-message]').textContent = message;
      overlay.classList.add('is-visible');
      document.body.setAttribute('aria-busy', 'true');
      form.dataset.busy = '1';
      form.querySelectorAll('button').forEach(function (button) {
        button.disabled = true;
      });

      var steps = overlay.querySelectorAll('[data-step]');
      var index = 0;
      steps.forEach(function (step) { step.classList.remove('is-active'); });
      var timer = window.setInterval(function () {
        if (steps[index]) {
          steps[index].classList.add('is-active');
        }
        index = Math.min(index + 1, steps.length - 1);
      }, 1400);
      window.addEventListener('beforeunload', function () {
        window.clearInterval(timer);
      }, { once: true });
    });
  });
});
</script>
HTML;
    }

    private function metric(string $label, string $value): string
    {
        return '<div class="metric"><span>' . htmlspecialchars($label, ENT_QUOTES) . '</span><b>' . htmlspecialchars($value, ENT_QUOTES) . '</b></div>';
    }

    private function moduleSummary(array $modules): string
    {
        if ($modules === []) {
            return '<span class="muted">brak danych</span>';
        }

        $items = [];
        foreach ($modules as $slug => $version) {
            $items[] = '<span class="module-chip">' . $this->h((string) $slug) . ($version !== '' ? ' <small>' . $this->h((string) $version) . '</small>' : '') . '</span>';
        }

        return '<div class="module-chip-list">' . implode('', array_slice($items, 0, 8)) . (count($items) > 8 ? '<span class="module-chip">+' . (count($items) - 8) . '</span>' : '') . '</div>';
    }

    private function activeModuleVersions(): array
    {
        $versions = [];
        foreach ((new ModuleManager($this->container))->activeModules($this->pdo) as $slug => $module) {
            $versions[$slug] = (string) ($module['version'] ?? 'unknown');
        }

        return $versions;
    }

    private function slugify(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/i', '-', $value) ?: 'page';
        return trim($value, '-') ?: 'page';
    }

    private function storeUpload(array $file): string
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('Upload pliku nie powiodl sie.');
        }

        $originalName = basename((string) $file['name']);
        $extension = pathinfo($originalName, PATHINFO_EXTENSION);
        $safeName = $this->slugify(pathinfo($originalName, PATHINFO_FILENAME)) . '-' . bin2hex(random_bytes(4));
        if ($extension !== '') {
            $safeName .= '.' . strtolower($extension);
        }

        $relativeDir = 'uploads/' . date('Y/m');
        $targetDir = $this->container['public_path'] . '/' . $relativeDir;
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0775, true);
        }

        $targetPath = $targetDir . '/' . $safeName;
        if (!move_uploaded_file((string) $file['tmp_name'], $targetPath)) {
            throw new \RuntimeException('Nie można zapisać pliku uploadu.');
        }

        $statement = $this->pdo->prepare('INSERT INTO cms_media (filename, path, mime_type, size) VALUES (?, ?, ?, ?)');
        $statement->execute([
            $originalName,
            '/' . $relativeDir . '/' . $safeName,
            mime_content_type($targetPath) ?: null,
            filesize($targetPath) ?: 0,
        ]);

        return '/' . $relativeDir . '/' . $safeName;
    }
}
