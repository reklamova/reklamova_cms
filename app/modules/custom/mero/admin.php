<?php

use Reklamova\Cms\Admin\AdminView;
use Reklamova\Cms\Auth\Csrf;
use Reklamova\Cms\Support\Url;

return static function (array $container, PDO $pdo, array $module): array {
    $h = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES);

    $settings = static function () use ($pdo): array {
        $statement = $pdo->prepare('SELECT setting_value FROM cms_settings WHERE setting_key = "mero.calculator"');
        $statement->execute();
        $value = $statement->fetchColumn();
        return is_string($value) ? (json_decode($value, true) ?: []) : [];
    };

    $saveSettings = static function (array $data) use ($pdo, $settings): void {
        $current = $settings();
        $current['range_percent'] = max(1, min(40, (int) ($data['range_percent'] ?? 15)));
        $current['admin_email'] = filter_var($data['admin_email'] ?? '', FILTER_VALIDATE_EMAIL) ?: ($current['admin_email'] ?? 'biuro@mero.pl');
        foreach (['sso', 'ssz', 'developer', 'turnkey'] as $key) {
            $current['base_prices'][$key] = max(0, (int) ($data['base_' . $key] ?? 0));
        }

        $statement = $pdo->prepare('UPDATE cms_settings SET setting_value = ? WHERE setting_key = "mero.calculator"');
        $statement->execute([json_encode($current, JSON_UNESCAPED_SLASHES)]);
    };

    $slugify = static function (string $value): string {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/i', '-', $value) ?: 'article';
        return trim($value, '-') ?: 'article';
    };

    return [
        'nav' => [
            '/admin/mero/calculator' => 'MERO Kalkulator',
            '/admin/mero/leads' => 'MERO Leady',
            '/admin/mero/articles' => 'MERO Poradnik',
        ],
        'routes' => [
            '/admin/mero/calculator' => static function (AdminView $view, array $user) use ($h, $settings, $saveSettings): void {
                if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && Csrf::verify($_POST['_csrf'] ?? null)) {
                    $saveSettings($_POST);
                    Url::redirect('/admin/mero/calculator?saved=1');
                }

                $config = $settings();
                $base = $config['base_prices'] ?? [];
                $saved = isset($_GET['saved']) ? '<div class="notice">Ustawienia kalkulatora zostaly zapisane.</div>' : '';
                $content = $saved . '<section class="panel"><p>Kalkulator publiczny korzysta z tych stawek do orientacyjnego wyniku. To nie jest publiczny cennik MERO.</p>'
                    . '<form method="post">' . Csrf::field()
                    . '<label>Stan surowy otwarty, zl/m2<input type="number" name="base_sso" value="' . $h($base['sso'] ?? 0) . '"></label>'
                    . '<label>Stan surowy zamkniety, zl/m2<input type="number" name="base_ssz" value="' . $h($base['ssz'] ?? 0) . '"></label>'
                    . '<label>Stan deweloperski, zl/m2<input type="number" name="base_developer" value="' . $h($base['developer'] ?? 0) . '"></label>'
                    . '<label>Dom pod klucz, zl/m2<input type="number" name="base_turnkey" value="' . $h($base['turnkey'] ?? 0) . '"></label>'
                    . '<label>Widelki wyniku, +/- %<input type="number" name="range_percent" value="' . $h($config['range_percent'] ?? 15) . '"></label>'
                    . '<label>E-mail powiadomien<input type="email" name="admin_email" value="' . $h($config['admin_email'] ?? 'biuro@mero.pl') . '"></label>'
                    . '<button>Zapisz kalkulator</button></form></section>';

                $view->render('MERO Kalkulator', $content, $user);
            },
            '/admin/mero/leads' => static function (AdminView $view, array $user) use ($pdo, $h): void {
                if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && Csrf::verify($_POST['_csrf'] ?? null)) {
                    $statement = $pdo->prepare('UPDATE mero_leads SET status = ?, notes = ? WHERE id = ?');
                    $statement->execute([
                        trim((string) ($_POST['status'] ?? 'new')),
                        trim((string) ($_POST['notes'] ?? '')),
                        (int) ($_POST['id'] ?? 0),
                    ]);
                    Url::redirect('/admin/mero/leads?saved=1');
                }

                $rows = $pdo->query('SELECT * FROM mero_leads ORDER BY created_at DESC LIMIT 200')->fetchAll();
                $body = '';
                foreach ($rows as $row) {
                    $payload = json_decode((string) ($row['payload'] ?? '{}'), true) ?: [];
                    $body .= '<tr><td><b>' . $h($row['name']) . '</b><br>' . $h($row['phone']) . '<br>' . $h($row['email']) . '</td>'
                        . '<td>' . $h($row['type']) . '<br><small>' . $h($row['source']) . '</small></td>'
                        . '<td>' . $h($row['created_at']) . '<br><small>' . $h($row['location']) . '</small></td>'
                        . '<td><pre>' . $h(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . '</pre></td>'
                        . '<td><form method="post">' . Csrf::field() . '<input type="hidden" name="id" value="' . (int) $row['id'] . '">'
                        . '<label>Status<input name="status" value="' . $h($row['status']) . '"></label>'
                        . '<label>Notatki<textarea name="notes">' . $h($row['notes'] ?? '') . '</textarea></label><button>Zapisz</button></form></td></tr>';
                }

                $saved = isset($_GET['saved']) ? '<div class="notice">Lead zostal zaktualizowany.</div>' : '';
                $content = $saved . '<table><thead><tr><th>Kontakt</th><th>Typ</th><th>Data</th><th>Dane</th><th>Status</th></tr></thead><tbody>' . $body . '</tbody></table>';
                $view->render('MERO Leady', $content, $user);
            },
            '/admin/mero/articles' => static function (AdminView $view, array $user) use ($pdo, $h, $slugify): void {
                $edit = null;
                if (isset($_GET['id'])) {
                    $statement = $pdo->prepare('SELECT * FROM mero_articles WHERE id = ?');
                    $statement->execute([(int) $_GET['id']]);
                    $edit = $statement->fetch() ?: null;
                }

                if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && Csrf::verify($_POST['_csrf'] ?? null)) {
                    if (($_POST['action'] ?? '') === 'delete') {
                        $statement = $pdo->prepare('DELETE FROM mero_articles WHERE id = ?');
                        $statement->execute([(int) ($_POST['id'] ?? 0)]);
                        Url::redirect('/admin/mero/articles?deleted=1');
                    }

                    $id = (int) ($_POST['id'] ?? 0);
                    $title = trim((string) ($_POST['title'] ?? ''));
                    $slug = trim((string) ($_POST['slug'] ?? '')) ?: $slugify($title);
                    $values = [
                        $title,
                        $slug,
                        trim((string) ($_POST['excerpt'] ?? '')),
                        trim((string) ($_POST['content'] ?? '')),
                        in_array($_POST['status'] ?? 'draft', ['draft', 'published'], true) ? $_POST['status'] : 'draft',
                        trim((string) ($_POST['category'] ?? 'Poradnik inwestora')),
                        trim((string) ($_POST['cover_image'] ?? '')),
                        trim((string) ($_POST['meta_title'] ?? '')),
                        trim((string) ($_POST['meta_description'] ?? '')),
                        trim((string) ($_POST['published_at'] ?? date('Y-m-d'))) ?: date('Y-m-d'),
                    ];

                    if ($id > 0) {
                        $statement = $pdo->prepare('UPDATE mero_articles SET title = ?, slug = ?, excerpt = ?, content = ?, status = ?, category = ?, cover_image = ?, meta_title = ?, meta_description = ?, published_at = ? WHERE id = ?');
                        $statement->execute([...$values, $id]);
                    } else {
                        $statement = $pdo->prepare('INSERT INTO mero_articles (title, slug, excerpt, content, status, category, cover_image, meta_title, meta_description, published_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                        $statement->execute($values);
                    }
                    Url::redirect('/admin/mero/articles?saved=1');
                }

                $rows = $pdo->query('SELECT id, title, slug, status, published_at, updated_at FROM mero_articles ORDER BY updated_at DESC')->fetchAll();
                $list = '';
                foreach ($rows as $row) {
                    $list .= '<tr><td>' . $h($row['title']) . '</td><td>/poradnik/' . $h($row['slug']) . '</td><td>' . $h($row['status']) . '</td><td>' . $h($row['published_at']) . '</td><td><div class="actions"><a class="button secondary" href="/admin/mero/articles?id=' . (int) $row['id'] . '">Edytuj</a><form method="post" onsubmit="return confirm(\'Usunac ten wpis?\')">' . Csrf::field() . '<input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="' . (int) $row['id'] . '"><button class="secondary">Usun</button></form></div></td></tr>';
                }

                $article = $edit ?: ['id' => 0, 'title' => '', 'slug' => '', 'excerpt' => '', 'content' => '', 'status' => 'draft', 'category' => 'Poradnik inwestora', 'cover_image' => '', 'meta_title' => '', 'meta_description' => '', 'published_at' => date('Y-m-d')];
                $saved = isset($_GET['saved']) ? '<div class="notice">Wpis zostal zapisany.</div>' : '';
                $content = $saved . '<section class="panel"><h2>' . ($edit ? 'Edytuj wpis' : 'Nowy wpis') . '</h2><form method="post">' . Csrf::field()
                    . '<input type="hidden" name="id" value="' . (int) $article['id'] . '">'
                    . '<label>Tytul<input name="title" value="' . $h($article['title']) . '" required></label>'
                    . '<label>Slug<input name="slug" value="' . $h($article['slug']) . '"></label>'
                    . '<label>Status<select name="status"><option value="draft">draft</option><option value="published"' . ($article['status'] === 'published' ? ' selected' : '') . '>published</option></select></label>'
                    . '<label>Data publikacji<input type="date" name="published_at" value="' . $h($article['published_at']) . '"></label>'
                    . '<label>Kategoria<input name="category" value="' . $h($article['category']) . '"></label>'
                    . '<label>Obraz wyrozniajacy<input name="cover_image" value="' . $h($article['cover_image']) . '"></label>'
                    . '<label>Zajawka<textarea name="excerpt">' . $h($article['excerpt']) . '</textarea></label>'
                    . '<label>Tresc<textarea name="content">' . $h($article['content']) . '</textarea></label>'
                    . '<label>Meta title<input name="meta_title" value="' . $h($article['meta_title']) . '"></label>'
                    . '<label>Meta description<textarea name="meta_description">' . $h($article['meta_description']) . '</textarea></label>'
                    . '<button>Zapisz wpis</button></form></section>'
                    . '<table><thead><tr><th>Tytul</th><th>Adres</th><th>Status</th><th>Publikacja</th><th></th></tr></thead><tbody>' . $list . '</tbody></table>';

                $view->render('MERO Poradnik', $content, $user);
            },
        ],
    ];
};
