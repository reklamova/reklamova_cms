<?php

declare(strict_types=1);

use Reklamova\Cms\Admin\AdminView;
use Reklamova\Cms\Auth\Csrf;
use Reklamova\Cms\Modules\Knowledge\KnowledgeRepository;
use Reklamova\Cms\Support\Url;

require_once __DIR__ . '/src/KnowledgeRepository.php';

return static function (array $container, PDO $pdo, array $module): array {
    $repo = new KnowledgeRepository($pdo);
    $h = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES);
    $tabs = static function (string $active) use ($h): string {
        $items = [
            '/admin/knowledge' => 'Artykuły',
            '/admin/knowledge/categories' => 'Kategorie',
            '/admin/knowledge/authors' => 'Autorzy',
        ];
        $html = '<div class="privacy-tabs">';
        foreach ($items as $href => $label) {
            $html .= '<a class="button ' . ($href === $active ? '' : 'secondary') . '" href="' . $h($href) . '"' . ($href === $active ? ' aria-current="page"' : '') . '>' . $h($label) . '</a>';
        }
        return $html . '</div>';
    };

    $options = static function (array $items, ?int $selected) use ($h): string {
        $html = '<option value="">-</option>';
        foreach ($items as $item) {
            $html .= '<option value="' . (int) $item['id'] . '"' . ((int) $item['id'] === (int) $selected ? ' selected' : '') . '>' . $h($item['name']) . '</option>';
        }
        return $html;
    };

    $articles = static function (AdminView $view, array $user) use ($repo, $tabs, $options, $h): void {
        $message = '';
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && Csrf::verify($_POST['_csrf'] ?? null)) {
            if (($_POST['action'] ?? '') === 'delete') {
                $repo->delete('articles', (int) ($_POST['id'] ?? 0));
                Url::redirect('/admin/knowledge');
            }
            $repo->saveArticle($_POST, isset($_POST['id']) && $_POST['id'] !== '' ? (int) $_POST['id'] : null);
            $message = '<div class="notice">Artykuł został zapisany.</div>';
        }

        $edit = isset($_GET['id']) ? $repo->find('articles', (int) $_GET['id']) : null;
        $tags = $edit && !empty($edit['tags_json']) ? implode(', ', json_decode((string) $edit['tags_json'], true) ?: []) : '';
        $form = '<section class="panel"><h2>' . ($edit ? 'Edytuj artykuł' : 'Nowy artykuł') . '</h2><form method="post" class="privacy-settings-grid">' . Csrf::field()
            . ($edit ? '<input type="hidden" name="id" value="' . (int) $edit['id'] . '">' : '')
            . '<label class="field field--half">Tytuł<input name="title" required value="' . $h($edit['title'] ?? '') . '"></label>'
            . '<label class="field field--half">Adres URL<input name="slug" value="' . $h($edit['slug'] ?? '') . '"></label>'
            . '<label class="field">Kategoria<select name="category_id">' . $options($repo->categories(), isset($edit['category_id']) ? (int) $edit['category_id'] : null) . '</select></label>'
            . '<label class="field">Autor<select name="author_id">' . $options($repo->authors(), isset($edit['author_id']) ? (int) $edit['author_id'] : null) . '</select></label>'
            . '<label class="field">Status<select name="status"><option value="draft">Szkic</option><option value="published"' . (($edit['status'] ?? '') === 'published' ? ' selected' : '') . '>Opublikowany</option></select></label>'
            . '<label class="field">Data publikacji<input name="published_at" value="' . $h($edit['published_at'] ?? '') . '" placeholder="YYYY-MM-DD HH:MM:SS"></label>'
            . '<label class="field field--wide">Zajawka<textarea name="excerpt">' . $h($edit['excerpt'] ?? '') . '</textarea></label>'
            . '<label class="field field--wide">Treść<textarea name="content">' . $h($edit['content'] ?? '') . '</textarea></label>'
            . '<label class="field field--half">Obraz okładki<input name="cover_image" value="' . $h($edit['cover_image'] ?? '') . '"></label>'
            . '<label class="field field--half">Tagi po przecinku<input name="tags" value="' . $h($tags) . '"></label>'
            . '<label class="field">Powiązana usługa - adres URL<input name="related_service_slug" value="' . $h($edit['related_service_slug'] ?? '') . '"></label>'
            . '<label class="field">Powiązana lokalizacja - adres URL<input name="related_area_slug" value="' . $h($edit['related_area_slug'] ?? '') . '"></label>'
            . '<label class="field field--half">Meta title<input name="meta_title" value="' . $h($edit['meta_title'] ?? '') . '"></label>'
            . '<label class="field field--half">Meta description<textarea name="meta_description">' . $h($edit['meta_description'] ?? '') . '</textarea></label>'
            . '<div class="field field--wide"><button>Zapisz artykul</button></div></form></section>';

        $rows = '';
        foreach ($repo->articles(false) as $article) {
            $rows .= '<tr><td><b>' . $h($article['title']) . '</b><br><small>/poradnik/' . $h($article['slug']) . '</small></td><td>' . $h($article['category_name']) . '</td><td>' . $h($article['author_name']) . '</td><td>' . $h($article['status']) . '</td><td>' . $h($article['published_at']) . '</td><td><div class="actions"><a class="button secondary" href="/admin/knowledge?id=' . (int) $article['id'] . '">Edytuj</a><form method="post" onsubmit="return confirm(\'Usunąć artykuł?\')">' . Csrf::field() . '<input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="' . (int) $article['id'] . '"><button class="secondary">Usuń</button></form></div></td></tr>';
        }
        $list = '<section class="panel"><h2>Artykuły</h2><table><thead><tr><th>Tytuł</th><th>Kategoria</th><th>Autor</th><th>Status</th><th>Publikacja</th><th></th></tr></thead><tbody>' . ($rows ?: '<tr><td colspan="6">Brak artykułów.</td></tr>') . '</tbody></table></section>';
        $view->render('Poradnik', $tabs('/admin/knowledge') . $message . $form . $list, $user);
    };

    $simple = static function (AdminView $view, array $user, string $type) use ($repo, $tabs, $h): void {
        $route = $type === 'categories' ? '/admin/knowledge/categories' : '/admin/knowledge/authors';
        $label = $type === 'categories' ? 'Kategorie' : 'Autorzy';
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && Csrf::verify($_POST['_csrf'] ?? null)) {
            if (($_POST['action'] ?? '') === 'delete') {
                $repo->delete($type, (int) ($_POST['id'] ?? 0));
                Url::redirect($route);
            }
            $type === 'categories'
                ? $repo->saveCategory($_POST, isset($_POST['id']) && $_POST['id'] !== '' ? (int) $_POST['id'] : null)
                : $repo->saveAuthor($_POST, isset($_POST['id']) && $_POST['id'] !== '' ? (int) $_POST['id'] : null);
            Url::redirect($route);
        }
        $edit = isset($_GET['id']) ? $repo->find($type, (int) $_GET['id']) : null;
        $form = '<section class="panel"><h2>' . ($edit ? 'Edytuj' : 'Dodaj') . ' ' . $h(strtolower($label)) . '</h2><form method="post" class="privacy-settings-grid">' . Csrf::field()
            . ($edit ? '<input type="hidden" name="id" value="' . (int) $edit['id'] . '">' : '')
            . '<label class="field field--half">Nazwa<input name="name" required value="' . $h($edit['name'] ?? '') . '"></label>'
            . '<label class="field field--half">Adres URL<input name="slug" value="' . $h($edit['slug'] ?? '') . '"></label>';
        if ($type === 'categories') {
            $form .= '<label class="field field--wide">Opis<textarea name="description">' . $h($edit['description'] ?? '') . '</textarea></label><label class="field">Kolejność<input type="number" name="sort_order" value="' . $h($edit['sort_order'] ?? 100) . '"></label>';
        } else {
            $form .= '<label class="field">Rola<input name="role" value="' . $h($edit['role'] ?? '') . '"></label><label class="field">Zdjęcie<input name="photo" value="' . $h($edit['photo'] ?? '') . '"></label><label class="field field--wide">Bio<textarea name="bio">' . $h($edit['bio'] ?? '') . '</textarea></label>';
        }
        $form .= '<div class="field field--wide"><button>Zapisz</button></div></form></section>';
        $items = $type === 'categories' ? $repo->categories() : $repo->authors();
        $rows = '';
        foreach ($items as $item) {
            $rows .= '<tr><td>' . $h($item['name']) . '</td><td>' . $h($item['slug']) . '</td><td><div class="actions"><a class="button secondary" href="' . $h($route) . '?id=' . (int) $item['id'] . '">Edytuj</a><form method="post" onsubmit="return confirm(\'Usunąć?\')">' . Csrf::field() . '<input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="' . (int) $item['id'] . '"><button class="secondary">Usuń</button></form></div></td></tr>';
        }
        $list = '<section class="panel"><h2>' . $h($label) . '</h2><table><thead><tr><th>Nazwa</th><th>Adres URL</th><th></th></tr></thead><tbody>' . ($rows ?: '<tr><td colspan="3">Brak elementów.</td></tr>') . '</tbody></table></section>';
        $view->render('Poradnik - ' . $label, $tabs($route) . $form . $list, $user);
    };

    return [
        'nav' => ['/admin/knowledge' => 'Poradnik'],
        'routes' => [
            '/admin/knowledge' => $articles,
            '/admin/knowledge/categories' => static fn (AdminView $view, array $user) => $simple($view, $user, 'categories'),
            '/admin/knowledge/authors' => static fn (AdminView $view, array $user) => $simple($view, $user, 'authors'),
        ],
    ];
};
