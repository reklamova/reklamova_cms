<?php

declare(strict_types=1);

use Reklamova\Cms\Admin\AdminView;
use Reklamova\Cms\Auth\Csrf;
use Reklamova\Cms\Modules\Trust\TrustRepository;
use Reklamova\Cms\Support\Url;

require_once __DIR__ . '/src/TrustRepository.php';

return static function (array $container, PDO $pdo, array $module): array {
    $repo = new TrustRepository($pdo);
    $h = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES);
    $types = [
        'metric' => 'Liczba',
        'certificate' => 'Certyfikat',
        'partner' => 'Partner',
        'award' => 'Nagroda',
        'download' => 'Plik',
    ];

    $screen = static function (AdminView $view, array $user) use ($repo, $h, $types): void {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && Csrf::verify($_POST['_csrf'] ?? null)) {
            if (($_POST['action'] ?? '') === 'delete') {
                $repo->delete((int) ($_POST['id'] ?? 0));
                Url::redirect('/admin/trust');
            }
            $repo->save($_POST, isset($_POST['id']) && $_POST['id'] !== '' ? (int) $_POST['id'] : null);
            Url::redirect('/admin/trust');
        }
        $edit = isset($_GET['id']) ? $repo->find((int) $_GET['id']) : null;
        $typeOptions = '';
        foreach ($types as $value => $label) {
            $typeOptions .= '<option value="' . $h($value) . '"' . (($edit['type'] ?? '') === $value ? ' selected' : '') . '>' . $h($label) . '</option>';
        }
        $form = '<section class="panel"><h2>' . ($edit ? 'Edytuj opinię, certyfikat lub atut' : 'Nowa opinia, certyfikat lub atut') . '</h2><form method="post" class="privacy-settings-grid">' . Csrf::field()
            . ($edit ? '<input type="hidden" name="id" value="' . (int) $edit['id'] . '">' : '')
            . '<label class="field">Typ<select name="type">' . $typeOptions . '</select></label>'
            . '<label class="field field--half">Tytuł<input name="title" required value="' . $h($edit['title'] ?? '') . '"></label>'
            . '<label class="field">Podtytuł<input name="subtitle" value="' . $h($edit['subtitle'] ?? '') . '"></label>'
            . '<label class="field">Wartość/liczba<input name="value" value="' . $h($edit['value'] ?? '') . '"></label>'
            . '<label class="field field--wide">Opis<textarea name="description">' . $h($edit['description'] ?? '') . '</textarea></label>'
            . '<label class="field field--half">Obraz/logo<input name="image" value="' . $h($edit['image'] ?? '') . '"></label>'
            . '<label class="field field--half">Plik PDF / download<input name="file_url" value="' . $h($edit['file_url'] ?? '') . '"></label>'
            . '<label class="field field--half">Link zewnetrzny<input name="external_url" value="' . $h($edit['external_url'] ?? '') . '"></label>'
            . '<label class="field">Kolejność<input type="number" name="sort_order" value="' . $h($edit['sort_order'] ?? 100) . '"></label>'
            . '<label class="field">Status<select name="status"><option value="draft">Szkic</option><option value="published"' . (($edit['status'] ?? '') === 'published' ? ' selected' : '') . '>Opublikowany</option></select></label>'
            . '<label class="field field--switch"><input type="checkbox" name="is_featured" value="1"' . (!empty($edit['is_featured']) ? ' checked' : '') . '> Wyrozniony</label>'
            . '<div class="field field--wide"><button>Zapisz</button></div></form></section>';
        $rows = '';
        foreach ($repo->all(false) as $item) {
            $rows .= '<tr><td>' . $h($types[$item['type']] ?? $item['type']) . '</td><td><b>' . $h($item['title']) . '</b><br><small>' . $h($item['subtitle']) . '</small></td><td>' . $h($item['value']) . '</td><td>' . $h($item['status']) . '</td><td><div class="actions"><a class="button secondary" href="/admin/trust?id=' . (int) $item['id'] . '">Edytuj</a><form method="post" onsubmit="return confirm(\'Usunąć element?\')">' . Csrf::field() . '<input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="' . (int) $item['id'] . '"><button class="secondary">Usuń</button></form></div></td></tr>';
        }
        $list = '<section class="panel"><h2>Opinie, certyfikaty i atuty</h2><table><thead><tr><th>Typ</th><th>Nazwa</th><th>Wartość</th><th>Status</th><th></th></tr></thead><tbody>' . ($rows ?: '<tr><td colspan="5">Brak elementów.</td></tr>') . '</tbody></table></section>';
        $view->render('Opinie i referencje', $form . $list, $user);
    };

    return [
        'nav' => ['/admin/trust' => 'Opinie i referencje'],
        'routes' => ['/admin/trust' => $screen],
    ];
};
