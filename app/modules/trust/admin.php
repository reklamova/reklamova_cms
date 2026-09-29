<?php

declare(strict_types=1);

use Reklamova\Cms\Admin\AdminView;
use Reklamova\Cms\Auth\Csrf;
use Reklamova\Cms\Modules\Trust\TrustRepository;
use Reklamova\Cms\Support\Url;

require_once __DIR__ . '/src/TrustRepository.php';

return static function (array $container, PDO $pdo, array $module): array {
    $repo = new TrustRepository($pdo);
    $h = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    $types = [
        'review' => 'Opinia klienta',
        'google_review' => 'Opinia Google',
        'certificate' => 'Certyfikat',
        'partner' => 'Logotyp klienta',
        'metric' => 'Liczba / statystyka',
        'award' => 'Wyróżnienie',
        'download' => 'Plik do pobrania',
    ];
    $typeDescriptions = [
        'review' => 'Imię lub nazwa klienta, treść opinii i opcjonalna ocena.',
        'google_review' => 'Link do profilu Google lub ręcznie wybrana opinia. Automatyczna integracja może dojść później.',
        'certificate' => 'Nazwa certyfikatu, obraz lub plik oraz krótki opis.',
        'partner' => 'Nazwa klienta, logo i opcjonalny link.',
        'metric' => 'Np. 120 realizacji albo 15 lat doświadczenia.',
        'award' => 'Nagroda, ranking albo branżowy znak jakości.',
        'download' => 'Katalog, certyfikat lub dokument PDF do pobrania.',
    ];
    $placement = is_array($module['placement'] ?? null) ? $module['placement'] : [];
    $hasPlacement = !empty($module['has_theme_placement']);
    $placementWhere = (string) ($placement['where'] ?? '');

    $screen = static function (AdminView $view, array $user) use ($repo, $h, $types, $typeDescriptions, $hasPlacement, $placementWhere): void {
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

        $descriptions = '<div class="field field--wide trust-type-help">';
        foreach ($typeDescriptions as $label => $description) {
            $descriptions .= '<small><b>' . $h($types[$label]) . ':</b> ' . $h($description) . '</small>';
        }
        $descriptions .= '</div>';

        $placementNotice = $hasPlacement
            ? '<section class="panel notice"><h2>Gdzie to się wyświetla?</h2><p>' . $h($placementWhere ?: 'Motyw deklaruje miejsce dla tego modułu.') . '</p></section>'
            : '<section class="panel error-panel"><h2>Brak miejsca w motywie</h2><p>Ten moduł jest aktywny technicznie, ale motyw nie deklaruje miejsca wyświetlania. Klient nie widzi go w menu, dopóki Reklamova nie doda placementu.</p></section>';
        $form = '<section class="panel"><h2>' . ($edit ? 'Edytuj element wiarygodności' : 'Nowy element wiarygodności') . '</h2><p>Dodaj tylko te elementy, które faktycznie mają swoje miejsce w motywie strony.</p><form method="post" class="privacy-settings-grid">' . Csrf::field()
            . ($edit ? '<input type="hidden" name="id" value="' . (int) $edit['id'] . '">' : '')
            . '<label class="field">Typ<select name="type">' . $typeOptions . '</select></label>'
            . $descriptions
            . '<label class="field field--half">Tytuł<input name="title" required value="' . $h($edit['title'] ?? '') . '"></label>'
            . '<label class="field">Podtytuł / podpis<input name="subtitle" value="' . $h($edit['subtitle'] ?? '') . '"></label>'
            . '<label class="field">Wartość / liczba / ocena<input name="value" value="' . $h($edit['value'] ?? '') . '"></label>'
            . '<label class="field field--wide">Opis / treść opinii<textarea name="description" data-content-editor>' . $h($edit['description'] ?? '') . '</textarea></label>'
            . '<label class="field field--half">Obraz albo logo<input name="image" value="' . $h($edit['image'] ?? '') . '"></label>'
            . '<label class="field field--half">Plik PDF / download<input name="file_url" value="' . $h($edit['file_url'] ?? '') . '"></label>'
            . '<label class="field field--half">Link zewnętrzny<input name="external_url" value="' . $h($edit['external_url'] ?? '') . '"></label>'
            . '<label class="field">Kolejność<input type="number" name="sort_order" value="' . $h($edit['sort_order'] ?? 100) . '"></label>'
            . '<label class="field">Status<select name="status"><option value="draft">Szkic</option><option value="published"' . (($edit['status'] ?? '') === 'published' ? ' selected' : '') . '>Opublikowany</option></select></label>'
            . '<label class="field field--switch"><input type="checkbox" name="is_featured" value="1"' . (!empty($edit['is_featured']) ? ' checked' : '') . '> Wyróżniony</label>'
            . '<div class="field field--wide"><button>Zapisz</button></div></form></section>';

        $rows = '';
        foreach ($repo->all(false) as $item) {
            $rows .= '<tr><td>' . $h($types[$item['type']] ?? $item['type']) . '</td><td><b>' . $h($item['title']) . '</b><br><small>' . $h($item['subtitle']) . '</small></td><td>' . $h($item['value']) . '</td><td>' . $h($item['status']) . '</td><td><div class="actions"><a class="button secondary" href="/admin/trust?id=' . (int) $item['id'] . '">Edytuj</a><form method="post" onsubmit="return confirm(\'Usunąć element?\')">' . Csrf::field() . '<input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="' . (int) $item['id'] . '"><button class="secondary">Usuń</button></form></div></td></tr>';
        }
        $list = '<section class="panel"><h2>Lista elementów</h2><table><thead><tr><th>Typ</th><th>Nazwa</th><th>Wartość</th><th>Status</th><th></th></tr></thead><tbody>' . ($rows ?: '<tr><td colspan="5">Nie dodano jeszcze elementów wiarygodności. Dodaj opinię, certyfikat, logotyp lub liczbę, jeśli te elementy mają być widoczne na stronie.</td></tr>') . '</tbody></table></section>';

        $view->render('Opinie i wiarygodność', $placementNotice . $form . $list, $user);
    };

    return [
        'nav' => [
            '/admin/trust' => [
                'label' => 'Opinie i wiarygodność',
                'menu_group' => 'Marketing',
                'permission' => 'manage_reviews_trust',
                'visible_in_client_nav' => true,
                'sort_order' => 170,
            ],
        ],
        'routes' => ['/admin/trust' => $screen],
        'route_permissions' => [
            '/admin/trust' => ['GET' => 'manage_reviews_trust', 'POST' => 'manage_reviews_trust'],
        ],
    ];
};
