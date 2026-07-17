<?php

declare(strict_types=1);

use Reklamova\Cms\Modules\Privacy\ConsentModeService;
use Reklamova\Cms\Modules\Privacy\PrivacyDocumentService;
use Reklamova\Cms\Modules\Privacy\PrivacyRepository;
use Reklamova\Cms\Modules\Privacy\ScriptManager;

require_once __DIR__ . '/src/PrivacyRepository.php';
require_once __DIR__ . '/src/ConsentModeService.php';
require_once __DIR__ . '/src/ScriptManager.php';
require_once __DIR__ . '/src/PrivacyDocumentService.php';
require_once __DIR__ . '/src/CookieRegistryService.php';
require_once __DIR__ . '/src/FormConsentService.php';
require_once __DIR__ . '/src/PrivacyAuditLogger.php';

return static function (array $container, PDO $pdo, array $module): array {
    $repo = new PrivacyRepository($pdo);
    $consentMode = new ConsentModeService();
    $scriptManager = new ScriptManager($repo);
    $documents = new PrivacyDocumentService();

    $json = static function (array $payload, int $status = 200): void {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    };

    $adminPreviewEnabled = static function (array $settings): bool {
        if (empty($settings['test_mode_admin_always_show'])) {
            return false;
        }

        if (session_status() !== PHP_SESSION_ACTIVE) {
            $sessionName = session_name();
            if (!isset($_COOKIE[$sessionName]) || $_COOKIE[$sessionName] === '') {
                return false;
            }

            if (!@session_start()) {
                return false;
            }
        }

        return isset($_SESSION['admin_user']) && is_array($_SESSION['admin_user']);
    };

    $publicSettings = static function () use ($repo, $json, $adminPreviewEnabled): void {
        try {
            $settings = $repo->settings();
            $categories = array_map(static function (array $category): array {
                return [
                    'slug' => $category['slug'],
                    'name' => $category['name'],
                    'shortDescription' => $category['short_description'],
                    'fullDescription' => $category['full_description'],
                    'required' => (bool) $category['is_required'],
                    'active' => (bool) $category['is_active'],
                    'sortOrder' => (int) $category['sort_order'],
                    'consentMode' => json_decode((string) $category['consent_mode_mapping_json'], true) ?: [],
                ];
            }, $repo->categories(true));
            $json([
                'module' => 'privacy',
                'enabled' => (bool) ($settings['module_enabled'] ?? true),
                'banner' => [
                    'mode' => $settings['banner_mode'] ?? 'bottom_bar',
                    'style' => $settings['banner_style'] ?? 'minimal',
                    'language' => $settings['default_language'] ?? 'pl',
                    'title' => $settings['banner_title'] ?? 'Prywatność i cookies',
                    'text' => $settings['banner_text'] ?? '',
                    'buttons' => [
                        'acceptAll' => $settings['button_accept_all'] ?? 'Akceptuję wszystko',
                        'rejectAll' => $settings['button_reject_all'] ?? 'Odrzucam',
                        'customize' => $settings['button_customize'] ?? 'Dostosuj',
                        'save' => $settings['button_save'] ?? 'Zapisz wybór',
                    ],
                    'showAlwaysForAdmins' => $adminPreviewEnabled($settings),
                    'debug' => (bool) ($settings['debug_mode'] ?? false),
                    'ttlDays' => (int) ($settings['consent_ttl_days'] ?? 365),
                    'consentVersion' => ($settings['privacy_policy_version'] ?? '1') . ':' . ($settings['cookie_policy_version'] ?? '1'),
                ],
                'categories' => $categories,
            ]);
        } catch (Throwable $exception) {
            $json(['error' => 'Privacy Center nie jest jeszcże gotowy. Uruchom migracje.'], 503);
        }
    };

    $storeConsent = static function () use ($repo, $json, $container): void {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            $json(['error' => 'Method not allowed'], 405);
            return;
        }

        $raw = (string) file_get_contents('php://input');
        $payload = json_decode($raw, true) ?: [];
        $salt = (string) (($container['cms_version'] ?? '0.1.0') . '|' . ($container['root_path'] ?? 'reklamova'));
        $uuid = (string) ($payload['consentUuid'] ?? bin2hex(random_bytes(16)));
        try {
            $repo->logConsent([
                'consent_uuid' => $uuid,
                'consent_version' => (string) ($payload['consentVersion'] ?? '1'),
                'categories' => $payload['categories'] ?? [],
                'state' => $payload['state'] ?? [],
                'page_url' => substr((string) ($payload['pageUrl'] ?? ($_SERVER['HTTP_REFERER'] ?? '')), 0, 500),
                'user_agent_hash' => hash('sha256', $salt . '|' . (string) ($_SERVER['HTTP_USER_AGENT'] ?? '')),
                'ip_hash' => hash('sha256', $salt . '|' . (string) ($_SERVER['REMOTE_ADDR'] ?? '')),
            ]);
            $json(['ok' => true, 'consentUuid' => $uuid]);
        } catch (Throwable $exception) {
            $json(['ok' => false, 'error' => 'Nie udalo sie zapisac zgody.'], 500);
        }
    };

    $publicScripts = static function () use ($scriptManager, $json): void {
        try {
            $path = parse_url($_SERVER['HTTP_REFERER'] ?? ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
            $json(['scripts' => $scriptManager->publicScripts($path)]);
        } catch (Throwable $exception) {
            $json(['scripts' => []]);
        }
    };

    $documentRoute = static function (string $slug) use ($repo, $documents, $json): bool {
        if (!str_starts_with($slug, 'api/privacy/document/')) {
            return false;
        }
        $documentSlug = trim(substr($slug, strlen('api/privacy/document/')), '/');
        $document = $repo->documentBySlug($documentSlug);
        if (!$document) {
            $json(['error' => 'Nie znaleziono dokumentu.'], 404);
            return true;
        }

        $json([
            'title' => $document['title'],
            'slug' => $document['slug'],
            'type' => $document['type'],
            'version' => (int) $document['version'],
            'status' => $document['status'],
            'content' => $documents->renderTemplate((string) $document['content'], $documents->baseVariables($repo)),
            'publishedAt' => $document['published_at'],
        ]);
        return true;
    };

    $publicDocument = static function (string $slug) use ($repo, $documents): bool {
        $mapped = [
            'polityka-prywatności' => 'polityka-prywatności',
            'polityka-cookies' => 'polityka-cookies',
            'ustawienia-prywatności' => 'ustawienia-prywatności',
        ];
        if (!isset($mapped[$slug])) {
            return false;
        }

        header('Content-Type: text/html; charset=utf-8');
        if ($slug === 'ustawienia-prywatności') {
            echo '<!doctype html><html lang="pl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Ustawienia prywatności</title>' . privacy_head() . '</head><body>' . privacy_body_start() . '<main style="max-width:760px;margin:40px auto;font-family:system-ui,sans-serif"><h1>Ustawienia prywatności</h1><p>Tutaj możesz zmienic swoja decyzję dotyczaca cookies i skryptów zewnętrznych.</p><button data-reklamova-privacy-open>Otworz ustawienia prywatności</button></main>' . privacy_body_end() . '</body></html>';
            return true;
        }

        $document = $repo->documentBySlug($mapped[$slug]);
        if (!$document) {
            http_response_code(404);
            echo 'Nie znaleziono dokumentu.';
            return true;
        }
        $content = nl2br(htmlspecialchars($documents->renderTemplate((string) $document['content'], $documents->baseVariables($repo)), ENT_QUOTES));
        echo '<!doctype html><html lang="pl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . htmlspecialchars((string) $document['title'], ENT_QUOTES) . '</title>' . privacy_head() . '</head><body>' . privacy_body_start() . '<main style="max-width:860px;margin:40px auto;font-family:system-ui,sans-serif;line-height:1.65"><h1>' . htmlspecialchars((string) $document['title'], ENT_QUOTES) . '</h1><article>' . $content . '</article></main>' . privacy_footer_link() . privacy_body_end() . '</body></html>';
        return true;
    };

    return [
        'routes' => [
            '/api/privacy/settings' => $publicSettings,
            '/api/privacy/consent' => $storeConsent,
            '/api/privacy/scripts' => $publicScripts,
        ],
        'fallbacks' => [
            $documentRoute,
            $publicDocument,
        ],
        'head' => [
            static fn (): string => '<link rel="stylesheet" href="/assets/core/privacy/consent-manager.css">' . $consentMode->defaultSnippet() . '<script src="/assets/core/privacy/consent-manager.js" defer></script>',
        ],
        'body_start' => [
            static fn (): string => privacy_body_start(),
        ],
        'body_end' => [
            static function () use ($repo, $scriptManager): string {
                try {
                    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
                    $payload = [
                        'settingsEndpoint' => '/api/privacy/settings',
                        'consentEndpoint' => '/api/privacy/consent',
                        'scripts' => $scriptManager->publicScripts($path),
                        'debug' => (bool) $repo->setting('debug_mode', false),
                    ];
                    return '<script type="application/json" id="reklamova-privacy-config">' . htmlspecialchars(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ENT_NOQUOTES) . '</script>';
                } catch (Throwable) {
                    return '';
                }
            },
        ],
        'footer_links' => [
            static fn (): string => privacy_footer_link((string) $repo->setting('footer_privacy_label', 'Ustawienia prywatności')),
        ],
    ];
};
