<?php

declare(strict_types=1);

namespace Reklamova\Cms\Http;

use Reklamova\Cms\Admin\AdminController;
use Reklamova\Cms\Database\ConnectionFactory;
use Reklamova\Cms\Health\HealthCheck;
use Reklamova\Cms\Install\InstallController;
use Reklamova\Cms\Install\Installer;
use Reklamova\Cms\Modules\ModuleManager;
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

        $statement = $pdo->prepare('SELECT title, content FROM cms_pages WHERE slug = ? AND status = "published" LIMIT 1');
        $statement->execute([$slug]);
        $page = $statement->fetch();

        if (!$page) {
            http_response_code(404);
            $page = [
                'title' => 'Nie znaleziono',
                'content' => '<p>Strona nie zostala jeszcze opublikowana.</p>',
            ];
        }

        $this->respondRawHtml(
            (string) $page['title'],
            '<h1>' . htmlspecialchars((string) $page['title'], ENT_QUOTES) . '</h1><article>' . (string) $page['content'] . '</article>',
            (string) $config->get('app', 'name', 'Reklamova CMS')
        );
    }

    private function respondRawHtml(string $title, string $body, string $siteName = 'Reklamova CMS'): void
    {
        header('Content-Type: text/html; charset=utf-8');

        echo '<!doctype html><html lang="pl"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . htmlspecialchars($title, ENT_QUOTES) . '</title>'
            . '<style>body{font-family:system-ui,sans-serif;margin:40px;line-height:1.5;color:#1f2933;max-width:920px}header{margin-bottom:40px}pre{background:#f4f6f8;padding:16px;overflow:auto}</style>'
            . '</head><body><header><strong>' . htmlspecialchars($siteName, ENT_QUOTES) . '</strong></header>' . $body . '</body></html>';
    }
}
