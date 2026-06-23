<?php

declare(strict_types=1);

namespace Reklamova\Cms\Admin;

use Reklamova\Cms\Auth\AuthManager;
use Reklamova\Cms\Auth\Csrf;
use Reklamova\Cms\Database\ConnectionFactory;
use Reklamova\Cms\Health\HealthCheck;
use Reklamova\Cms\Modules\ModuleManager;
use Reklamova\Cms\Support\Config;
use Reklamova\Cms\Support\Url;
use Reklamova\Cms\Themes\ThemeManager;
use Reklamova\Cms\Updates\UpdateClient;

final class AdminController
{
    private \PDO $pdo;
    private AuthManager $auth;
    private AdminView $view;

    public function __construct(private array $container)
    {
        $this->pdo = (new ConnectionFactory($container))->make();
        $this->auth = new AuthManager($this->pdo);
        $this->view = new AdminView();
    }

    public function handle(): void
    {
        $path = Url::path();

        if ($path === '/admin/login') {
            $this->login();
            return;
        }

        if ($path === '/admin/logout' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            if (Csrf::verify($_POST['_csrf'] ?? null)) {
                $this->auth->logout();
            }
            Url::redirect('/admin/login');
        }

        $user = $this->auth->user();
        if (!$user) {
            Url::redirect('/admin/login');
        }

        match ($path) {
            '/admin', '/admin/' => $this->dashboard($user),
            '/admin/pages' => $this->pages($user),
            '/admin/pages/edit' => $this->editPage($user),
            '/admin/media' => $this->media($user),
            '/admin/settings' => $this->settings($user),
            '/admin/modules' => $this->modules($user),
            '/admin/themes' => $this->themes($user),
            '/admin/updates' => $this->updates($user),
            '/admin/health' => $this->health($user),
            default => $this->notFound($user),
        };
    }

    private function login(): void
    {
        $error = null;

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!Csrf::verify($_POST['_csrf'] ?? null)) {
                $error = 'Sesja formularza wygasla.';
            } elseif ($this->auth->attempt((string) ($_POST['email'] ?? ''), (string) ($_POST['password'] ?? ''))) {
                Url::redirect('/admin');
            } else {
                $error = 'Nieprawidlowy email lub haslo.';
            }
        }

        $errorHtml = $error ? '<div class="error">' . htmlspecialchars($error, ENT_QUOTES) . '</div>' : '';
        $content = '<div class="login"><section class="panel"><h1>Logowanie</h1>' . $errorHtml
            . '<form method="post">' . Csrf::field()
            . '<label>Email<input type="email" name="email" required></label>'
            . '<label>Haslo<input type="password" name="password" required></label>'
            . '<button>Zaloguj</button></form></section></div>';

        (new AdminView())->render('Logowanie', $content);
    }

    private function dashboard(array $user): void
    {
        $pages = (int) $this->pdo->query('SELECT COUNT(*) FROM cms_pages')->fetchColumn();
        $media = (int) $this->pdo->query('SELECT COUNT(*) FROM cms_media')->fetchColumn();
        $modules = (new ModuleManager($this->container))->discover();
        $config = new Config($this->container);

        $content = '<div class="grid">'
            . $this->metric('Wersja CMS', $this->container['cms_version'])
            . $this->metric('Strony', (string) $pages)
            . $this->metric('Media', (string) $media)
            . $this->metric('Moduly', (string) count($modules))
            . '</div><section class="panel"><h2>Instalacja</h2><p><b>Strona:</b> ' . htmlspecialchars($config->get('app', 'name', 'Reklamova CMS'), ENT_QUOTES) . '</p></section>';

        $this->view->render('Dashboard', $content, $user);
    }

    private function pages(array $user): void
    {
        $rows = $this->pdo->query('SELECT id, title, slug, status, updated_at FROM cms_pages ORDER BY updated_at DESC')->fetchAll();
        $body = '';
        foreach ($rows as $row) {
            $body .= '<tr><td>' . htmlspecialchars($row['title'], ENT_QUOTES) . '</td><td>/' . htmlspecialchars($row['slug'], ENT_QUOTES) . '</td><td>' . htmlspecialchars($row['status'], ENT_QUOTES) . '</td><td>' . htmlspecialchars($row['updated_at'], ENT_QUOTES) . '</td><td><a class="button secondary" href="/admin/pages/edit?id=' . (int) $row['id'] . '">Edytuj</a></td></tr>';
        }

        $content = '<div class="actions"><a class="button" href="/admin/pages/edit">Nowa strona</a></div><br>'
            . '<table><thead><tr><th>Tytul</th><th>Slug</th><th>Status</th><th>Aktualizacja</th><th></th></tr></thead><tbody>' . $body . '</tbody></table>';

        $this->view->render('Strony', $content, $user);
    }

    private function editPage(array $user): void
    {
        $id = isset($_GET['id']) ? (int) $_GET['id'] : null;

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && Csrf::verify($_POST['_csrf'] ?? null)) {
            $title = trim((string) $_POST['title']);
            $slug = trim((string) $_POST['slug']) ?: $this->slugify($title);
            $content = (string) $_POST['content'];
            $status = (string) $_POST['status'];

            if ($id) {
                $statement = $this->pdo->prepare('UPDATE cms_pages SET title = ?, slug = ?, content = ?, status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
                $statement->execute([$title, $slug, $content, $status, $id]);
            } else {
                $statement = $this->pdo->prepare('INSERT INTO cms_pages (title, slug, content, status) VALUES (?, ?, ?, ?)');
                $statement->execute([$title, $slug, $content, $status]);
            }

            Url::redirect('/admin/pages');
        }

        $page = ['title' => '', 'slug' => '', 'content' => '', 'status' => 'draft'];
        if ($id) {
            $statement = $this->pdo->prepare('SELECT * FROM cms_pages WHERE id = ?');
            $statement->execute([$id]);
            $page = $statement->fetch() ?: $page;
        }

        $content = '<form method="post">' . Csrf::field()
            . '<label>Tytul<input name="title" value="' . htmlspecialchars($page['title'], ENT_QUOTES) . '" required></label>'
            . '<label>Slug<input name="slug" value="' . htmlspecialchars($page['slug'], ENT_QUOTES) . '"></label>'
            . '<label>Status<select name="status"><option value="draft">draft</option><option value="published"' . ($page['status'] === 'published' ? ' selected' : '') . '>published</option></select></label>'
            . '<label>Tresc<textarea name="content">' . htmlspecialchars($page['content'], ENT_QUOTES) . '</textarea></label>'
            . '<button>Zapisz</button></form>';

        $this->view->render($id ? 'Edycja strony' : 'Nowa strona', '<section class="panel">' . $content . '</section>', $user);
    }

    private function media(array $user): void
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && Csrf::verify($_POST['_csrf'] ?? null) && isset($_FILES['upload'])) {
            $this->storeUpload($_FILES['upload']);
            Url::redirect('/admin/media');
        }

        $rows = $this->pdo->query('SELECT filename, path, mime_type, size, created_at FROM cms_media ORDER BY created_at DESC')->fetchAll();
        $body = '';
        foreach ($rows as $row) {
            $body .= '<tr><td>' . htmlspecialchars($row['filename'], ENT_QUOTES) . '</td><td>' . htmlspecialchars($row['path'], ENT_QUOTES) . '</td><td>' . htmlspecialchars($row['mime_type'], ENT_QUOTES) . '</td><td>' . (int) $row['size'] . '</td><td>' . htmlspecialchars($row['created_at'], ENT_QUOTES) . '</td></tr>';
        }

        $content = '<section class="panel"><form method="post" enctype="multipart/form-data">' . Csrf::field()
            . '<label>Plik<input type="file" name="upload" required></label><button>Wgraj plik</button></form></section>'
            . '<table><thead><tr><th>Plik</th><th>Sciezka</th><th>Typ</th><th>Rozmiar</th><th>Data</th></tr></thead><tbody>' . $body . '</tbody></table>';

        $this->view->render('Media', $content, $user);
    }

    private function settings(array $user): void
    {
        $config = new Config($this->container);
        $content = '<section class="panel"><p><b>Nazwa:</b> ' . htmlspecialchars($config->get('app', 'name', 'Reklamova CMS'), ENT_QUOTES) . '</p>'
            . '<p><b>URL:</b> ' . htmlspecialchars($config->get('app', 'url', ''), ENT_QUOTES) . '</p>'
            . '<p><b>Motyw:</b> ' . htmlspecialchars($config->get('app', 'active_theme', 'client-default'), ENT_QUOTES) . '</p></section>';

        $this->view->render('Ustawienia', $content, $user);
    }

    private function modules(array $user): void
    {
        $modules = (new ModuleManager($this->container))->discover();
        $body = '';
        foreach ($modules as $module) {
            $body .= '<tr><td>' . htmlspecialchars($module['name'] ?? $module['slug'], ENT_QUOTES) . '</td><td>' . htmlspecialchars($module['slug'], ENT_QUOTES) . '</td><td>' . htmlspecialchars($module['version'] ?? '-', ENT_QUOTES) . '</td><td>' . htmlspecialchars($module['source'] ?? 'official', ENT_QUOTES) . '</td></tr>';
        }

        $this->view->render('Moduly', '<table><thead><tr><th>Nazwa</th><th>Slug</th><th>Wersja</th><th>Zrodlo</th></tr></thead><tbody>' . $body . '</tbody></table>', $user);
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
        $status = (new UpdateClient($this->container))->localStatus();
        $this->view->render('Aktualizacje', '<section class="panel"><pre>' . htmlspecialchars(json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), ENT_QUOTES) . '</pre></section>', $user);
    }

    private function health(array $user): void
    {
        $health = (new HealthCheck($this->container))->run();
        $this->view->render('Health', '<section class="panel"><pre>' . htmlspecialchars(json_encode($health, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), ENT_QUOTES) . '</pre></section>', $user);
    }

    private function notFound(array $user): void
    {
        http_response_code(404);
        $this->view->render('Nie znaleziono', '<section class="panel"><p>Nie znaleziono ekranu panelu.</p></section>', $user);
    }

    private function metric(string $label, string $value): string
    {
        return '<div class="metric"><span>' . htmlspecialchars($label, ENT_QUOTES) . '</span><b>' . htmlspecialchars($value, ENT_QUOTES) . '</b></div>';
    }

    private function slugify(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/i', '-', $value) ?: 'page';
        return trim($value, '-') ?: 'page';
    }

    private function storeUpload(array $file): void
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
            throw new \RuntimeException('Nie mozna zapisac pliku uploadu.');
        }

        $statement = $this->pdo->prepare('INSERT INTO cms_media (filename, path, mime_type, size) VALUES (?, ?, ?, ?)');
        $statement->execute([
            $originalName,
            '/' . $relativeDir . '/' . $safeName,
            mime_content_type($targetPath) ?: null,
            filesize($targetPath) ?: 0,
        ]);
    }
}
