<?php

declare(strict_types=1);

namespace Reklamova\Cms\Http;

use Reklamova\Cms\Health\HealthCheck;
use Reklamova\Cms\Install\Installer;
use Reklamova\Cms\Updates\UpdateClient;

final class Application
{
    public function __construct(private array $container)
    {
    }

    public function handlePublic(): void
    {
        if (!(new Installer($this->container))->isInstalled()) {
            $this->respondHtml('Reklamova CMS installer', 'Instalacja nie jest jeszcze skonfigurowana.');
            return;
        }

        $this->respondHtml('Reklamova CMS', 'Publiczny frontend CMS dziala.');
    }

    public function handleAdmin(): void
    {
        $health = (new HealthCheck($this->container))->run();
        $updates = (new UpdateClient($this->container))->localStatus();

        $body = '<h1>Reklamova CMS Admin</h1>'
            . '<h2>Status systemu</h2><pre>' . htmlspecialchars(json_encode($health, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), ENT_QUOTES) . '</pre>'
            . '<h2>Aktualizacje</h2><pre>' . htmlspecialchars(json_encode($updates, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), ENT_QUOTES) . '</pre>';

        $this->respondRawHtml('Reklamova CMS Admin', $body);
    }

    private function respondHtml(string $title, string $message): void
    {
        $this->respondRawHtml($title, '<h1>' . htmlspecialchars($title, ENT_QUOTES) . '</h1><p>' . htmlspecialchars($message, ENT_QUOTES) . '</p>');
    }

    private function respondRawHtml(string $title, string $body): void
    {
        header('Content-Type: text/html; charset=utf-8');

        echo '<!doctype html><html lang="pl"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . htmlspecialchars($title, ENT_QUOTES) . '</title>'
            . '<style>body{font-family:system-ui,sans-serif;margin:40px;line-height:1.5;color:#1f2933}pre{background:#f4f6f8;padding:16px;overflow:auto}</style>'
            . '</head><body>' . $body . '</body></html>';
    }
}

