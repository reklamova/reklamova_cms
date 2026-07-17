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

        $this->renderPage();
    }

    public function handleAdmin(): void
    {
        if (!(new Installer($this->container))->isInstalled()) {
            (new InstallController($this->container))->handle();
            return;
        }

        (new AdminController($this->container))->handle();
    }

    private function renderPage(): void
    {
        $config = new Config($this->container);
        $slug = trim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/', '/');
        $slug = $slug === '' ? 'home' : $slug;

        $pdo = (new ConnectionFactory($this->container))->make();
        $extensions = (new ModuleManager($this->container))->publicExtensions($pdo);
        foreach ($extensions['fallbacks'] ?? [] as $fallback) {
            if (is_callable($fallback) && $fallback($slug)) {
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
                'content' => '<p>Strona nie zostala jeszcze opublikowana.</p>',
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
