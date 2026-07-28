<?php

declare(strict_types=1);

namespace Reklamova\Cms\Http;

use Reklamova\Cms\Admin\AdminController;
use Reklamova\Cms\Database\ConnectionFactory;
use Reklamova\Cms\Health\HealthCheck;
use Reklamova\Cms\Install\InstallController;
use Reklamova\Cms\Install\Installer;
use Reklamova\Cms\Modules\ModuleManager;
use Reklamova\Cms\Pages\PageRenderer;
use Reklamova\Cms\Pages\PageRepository;
use Reklamova\Cms\Support\Config;
use Reklamova\Cms\Support\Url;
use Reklamova\Cms\Updates\UpdateClient;

final class Application
{
    public function __construct(private array $container)
    {
    }

    public function handlePublic(): void
    {
        $installer = new Installer($this->container);
        if (!$installer->isInstalled()) {
            (new InstallController($this->container))->handle();
            return;
        }

        $pdo = (new ConnectionFactory($this->container))->make();
        $path = Url::path();
        $extensions = (new ModuleManager($this->container))->publicExtensions($pdo);
        $handler = $extensions['routes'][$path] ?? null;
        if (is_callable($handler)) {
            $handler();
            return;
        }

        $this->renderPage($extensions);
    }

    public function handleAdmin(): void
    {
        if (!(new Installer($this->container))->isInstalled()) {
            (new InstallController($this->container))->handle();
            return;
        }

        (new AdminController($this->container))->handle();
    }

    private function renderPage(array $extensions): void
    {
        $config = new Config($this->container);
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $slug = trim(rawurldecode($path), '/');
        $slug = $slug === '' ? 'home' : $slug;

        $pdo = (new ConnectionFactory($this->container))->make();
        foreach ($extensions['fallbacks'] ?? [] as $fallback) {
            if (is_callable($fallback) && $this->renderFallback($fallback, $slug, $extensions)) {
                return;
            }
        }

        $repo = new PageRepository($pdo);
        $renderer = new PageRenderer();
        $page = $repo->findPublishedBySlug($slug);

        if (!$page) {
            http_response_code(404);
            $page = [
                'title' => 'Nie znaleziono',
                'slug' => $slug,
                'content' => '<p>Strona nie została jeszcze opublikowana.</p>',
                'status' => 'draft',
                'template' => 'default',
            ];
        }

        $siteName = (string) $config->get('app', 'name', 'Reklamova CMS');
        $meta = $renderer->meta($page, $siteName, (string) $config->get('app', 'url', ''));
        $this->respondRawHtml(
            $meta,
            $renderer->render($page),
            $siteName,
            $extensions,
            $repo->navigationPages()
        );
    }

    /**
     * @param array<string, string> $meta
     * @param array<int, array<string, mixed>> $navigation
     */
    private function respondRawHtml(array $meta, string $body, string $siteName = 'Reklamova CMS', array $extensions = [], array $navigation = []): void
    {
        header('Content-Type: text/html; charset=utf-8');
        $head = $this->renderHook($extensions['head'] ?? []);
        $bodyStart = $this->renderHook($extensions['body_start'] ?? []);
        $bodyEnd = $this->renderHook($extensions['body_end'] ?? []);
        $footerLinks = $this->renderHook($extensions['footer_links'] ?? []);
        $title = (string) ($meta['title'] ?? $siteName);
        $description = (string) ($meta['description'] ?? '');
        $canonical = (string) ($meta['canonical'] ?? '');
        $robots = (string) ($meta['robots'] ?? 'index,follow');
        $image = (string) ($meta['image'] ?? '');
        $schema = (string) ($meta['schema'] ?? '');
        $nav = '';
        foreach ($navigation as $item) {
            $slug = trim((string) ($item['slug'] ?? ''), '/');
            $url = $slug === '' || $slug === 'home' ? '/' : '/' . $slug;
            $label = (string) (($item['menu_label'] ?? '') ?: ($item['title'] ?? $url));
            $nav .= '<a href="' . htmlspecialchars($url, ENT_QUOTES) . '">' . htmlspecialchars($label, ENT_QUOTES) . '</a>';
        }

        echo '<!doctype html><html lang="pl"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . htmlspecialchars($title, ENT_QUOTES) . '</title>'
            . ($description !== '' ? '<meta name="description" content="' . htmlspecialchars($description, ENT_QUOTES) . '">' : '')
            . '<meta name="robots" content="' . htmlspecialchars($robots, ENT_QUOTES) . '">'
            . ($canonical !== '' ? '<link rel="canonical" href="' . htmlspecialchars($canonical, ENT_QUOTES) . '">' : '')
            . '<meta property="og:title" content="' . htmlspecialchars($title, ENT_QUOTES) . '">'
            . ($description !== '' ? '<meta property="og:description" content="' . htmlspecialchars($description, ENT_QUOTES) . '">' : '')
            . ($image !== '' ? '<meta property="og:image" content="' . htmlspecialchars($image, ENT_QUOTES) . '">' : '')
            . '<link rel="stylesheet" href="/assets/core/page.css">'
            . $schema
            . $head
            . '</head><body class="cms-public">' . $bodyStart . '<div class="cms-shell"><header class="cms-public-header"><a class="cms-public-brand" href="/">' . htmlspecialchars($siteName, ENT_QUOTES) . '</a>' . ($nav !== '' ? '<nav class="cms-public-nav">' . $nav . '</nav>' : '') . '</header>'
            . $body
            . '<footer class="cms-public-footer"><span>&copy; ' . htmlspecialchars($siteName, ENT_QUOTES) . '</span>' . ($footerLinks ? '<span>' . $footerLinks . '</span>' : '') . '</footer></div>'
            . $bodyEnd . '</body></html>';
    }

    private function renderFallback(callable $fallback, string $slug, array $extensions): bool
    {
        ob_start();
        try {
            $handled = (bool) $fallback($slug);
            $output = (string) ob_get_clean();
        } catch (\Throwable $exception) {
            ob_end_clean();
            throw $exception;
        }

        if (!$handled) {
            echo $output;
            return false;
        }

        echo $this->injectPublicExtensions($output, $extensions);
        return true;
    }

    private function injectPublicExtensions(string $html, array $extensions): string
    {
        if ($html === '' || stripos($html, '<html') === false || stripos($html, '</head>') === false) {
            return $html;
        }

        $head = $this->renderHook($extensions['head'] ?? []);
        $bodyStart = $this->renderHook($extensions['body_start'] ?? []);
        $bodyEnd = $this->renderHook($extensions['body_end'] ?? []);
        $footerLinks = $this->renderHook($extensions['footer_links'] ?? []);

        if (str_contains($html, '/assets/core/privacy/consent-manager.css')) {
            $head = (string) preg_replace('~<link\b[^>]*consent-manager\.css[^>]*>~i', '', $head);
        }
        if (str_contains($html, '/assets/core/privacy/consent-manager.js')) {
            $head = (string) preg_replace('~<script\b[^>]*consent-manager\.js[^>]*>\s*</script>~i', '', $head);
        }
        if (str_contains($html, 'ReklamovaConsentModeDefault')) {
            $head = (string) preg_replace('~<script>[^<]*ReklamovaConsentModeDefault[^<]*</script>~i', '', $head);
        }
        if (str_contains($html, 'id="reklamova-privacy-root"')) {
            $bodyStart = '';
        }
        if (str_contains($html, 'id="reklamova-privacy-config"')) {
            $bodyEnd = '';
        }
        if (str_contains($html, 'data-reklamova-privacy-open')) {
            $footerLinks = '';
        }

        if ($head !== '') {
            $html = $this->insertBeforeClosingTag($html, 'head', $head);
        }
        if ($bodyStart !== '') {
            $html = (string) preg_replace_callback(
                '~<body\b[^>]*>~i',
                static fn (array $matches): string => $matches[0] . $bodyStart,
                $html,
                1
            );
        }
        if ($footerLinks !== '') {
            $footer = '<span class="cms-public-footer-links">' . $footerLinks . '</span>';
            if (stripos($html, '</footer>') !== false) {
                $html = $this->insertBeforeClosingTag($html, 'footer', $footer);
            } else {
                $bodyEnd = $footer . $bodyEnd;
            }
        }
        if ($bodyEnd !== '') {
            $html = $this->insertBeforeClosingTag($html, 'body', $bodyEnd);
        }

        return $html;
    }

    private function insertBeforeClosingTag(string $html, string $tag, string $content): string
    {
        return (string) preg_replace_callback(
            '~</' . preg_quote($tag, '~') . '>~i',
            static fn (array $matches): string => $content . $matches[0],
            $html,
            1
        );
    }

    private function renderHook(array $callbacks): string
    {
        $html = '';
        foreach ($callbacks as $callback) {
            if (is_callable($callback)) {
                $html .= (string) $callback();
            }
        }

        return $html;
    }
}
