<?php

declare(strict_types=1);

namespace Reklamova\Cms\Admin;

use Reklamova\Cms\Auth\Csrf;

final class AdminView
{
    public function __construct(private array $extraNavigation = [])
    {
    }

    public function render(string $title, string $content, array $user = null): void
    {
        header('Content-Type: text/html; charset=utf-8');

        if (!$user) {
            echo '<!doctype html><html lang="pl"><head><meta charset="utf-8">'
                . '<meta name="viewport" content="width=device-width, initial-scale=1">'
                . '<title>' . htmlspecialchars($title, ENT_QUOTES) . ' - Reklamova CMS</title>'
                . '<link rel="stylesheet" href="/assets/core/admin.css">'
                . '</head><body>' . $content . '</body></html>';
            return;
        }

        $nav = $user ? $this->navigation() : '';
        $account = '<form method="post" action="/admin/logout" class="logout">' . Csrf::field() . '<span>' . htmlspecialchars($user['name'] ?: $user['email'], ENT_QUOTES) . '</span><button>Wyloguj</button></form>';

        echo '<!doctype html><html lang="pl"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . htmlspecialchars($title, ENT_QUOTES) . ' - Reklamova CMS</title>'
            . '<link rel="stylesheet" href="/assets/core/admin.css">'
            . '</head><body><div class="layout">'
            . $nav
            . '<main class="main"><header class="topbar"><h1>' . htmlspecialchars($title, ENT_QUOTES) . '</h1>' . $account . '</header>'
            . '<section class="content">' . $content . '</section></main>'
            . '</div></body></html>';
    }

    private function navigation(): string
    {
        $items = [
            '/admin' => 'Dashboard',
            '/admin/pages' => 'Strony',
            '/admin/media' => 'Media',
            '/admin/settings' => 'Ustawienia',
            '/admin/modules' => 'Moduly',
            '/admin/themes' => 'Motyw',
            '/admin/updates' => 'Aktualizacje',
            '/admin/health' => 'Health',
        ];
        $items = array_merge($items, $this->extraNavigation);

        $links = '';
        foreach ($items as $href => $label) {
            $links .= '<a href="' . $href . '">' . htmlspecialchars($label, ENT_QUOTES) . '</a>';
        }

        return '<aside class="sidebar"><a class="brand" href="/admin" aria-label="Reklamova CMS"><img src="/assets/core/reklamova-logo.svg" alt="Reklamova"></a><nav>' . $links . '</nav></aside>';
    }
}
