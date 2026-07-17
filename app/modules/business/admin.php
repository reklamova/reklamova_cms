<?php

declare(strict_types=1);

use Reklamova\Cms\Admin\AdminView;
use Reklamova\Cms\Auth\Csrf;
use Reklamova\Cms\Modules\Business\BusinessRepository;
use Reklamova\Cms\Support\Url;

require_once __DIR__ . '/src/BusinessRepository.php';

return static function (array $container, PDO $pdo, array $module): array {
    $repo = new BusinessRepository($pdo);
    $h = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES);

    $sections = [
        'services' => [
            'route' => '/admin/business/services',
            'label' => 'Usługi',
            'singular' => 'usługę',
            'title_field' => 'title',
            'columns' => ['title' => 'Nazwa', 'slug' => 'URL', 'status' => 'Status'],
            'fields' => [
                'title' => ['label' => 'Nazwa usługi', 'type' => 'text', 'required' => true],
                'slug' => ['label' => 'Adres URL', 'type' => 'text'],
                'summary' => ['label' => 'Krótki opis', 'type' => 'textarea'],
                'description' => ['label' => 'Pełny opis', 'type' => 'textarea'],
                'icon' => ['label' => 'Ikona / identyfikator', 'type' => 'text'],
                'featured_image' => ['label' => 'Obraz wyróżniający', 'type' => 'text'],
                'meta_title' => ['label' => 'Meta title', 'type' => 'text'],
                'meta_description' => ['label' => 'Meta description', 'type' => 'textarea'],
                'status' => ['label' => 'Status', 'type' => 'status'],
                'sort_order' => ['label' => 'Kolejność', 'type' => 'number'],
            ],
        ],
        'areas' => [
            'route' => '/admin/business/areas',
            'label' => 'Lokalizacje',
            'singular' => 'lokalizację',
            'title_field' => 'name',
            'columns' => ['name' => 'Nazwa', 'region' => 'Region', 'slug' => 'URL', 'status' => 'Status'],
            'fields' => [
                'name' => ['label' => 'Miejscowość / obszar', 'type' => 'text', 'required' => true],
                'slug' => ['label' => 'Adres URL', 'type' => 'text'],
                'region' => ['label' => 'Region', 'type' => 'text'],
                'summary' => ['label' => 'Krótki opis lokalny', 'type' => 'textarea'],
                'description' => ['label' => 'Treść lokalnej podstrony', 'type' => 'textarea'],
                'meta_title' => ['label' => 'Meta title', 'type' => 'text'],
                'meta_description' => ['label' => 'Meta description', 'type' => 'textarea'],
                'status' => ['label' => 'Status', 'type' => 'status'],
                'sort_order' => ['label' => 'Kolejność', 'type' => 'number'],
            ],
        ],
        'cases' => [
            'route' => '/admin/business/cases',
            'label' => 'Realizacje',
            'singular' => 'realizację',
            'title_field' => 'title',
            'columns' => ['title' => 'Tytuł', 'client_name' => 'Klient', 'industry' => 'Branża', 'status' => 'Status'],
            'fields' => [
                'title' => ['label' => 'Tytuł realizacji / case study', 'type' => 'text', 'required' => true],
                'slug' => ['label' => 'Adres URL', 'type' => 'text'],
                'client_name' => ['label' => 'Klient', 'type' => 'text'],
                'industry' => ['label' => 'Branża', 'type' => 'text'],
                'summary' => ['label' => 'Krótki opis', 'type' => 'textarea'],
                'challenge' => ['label' => 'Wyzwanie', 'type' => 'textarea'],
                'solution' => ['label' => 'Rozwiązanie', 'type' => 'textarea'],
                'result' => ['label' => 'Efekt / rezultat', 'type' => 'textarea'],
                'cover_image' => ['label' => 'Obraz okładki', 'type' => 'text'],
                'meta_title' => ['label' => 'Meta title', 'type' => 'text'],
                'meta_description' => ['label' => 'Meta description', 'type' => 'textarea'],
                'status' => ['label' => 'Status', 'type' => 'status'],
                'sort_order' => ['label' => 'Kolejność', 'type' => 'number'],
            ],
        ],
        'testimonials' => [
            'route' => '/admin/business/testimonials',
            'label' => 'Opinie',
            'singular' => 'opinię',
            'title_field' => 'author',
            'columns' => ['author' => 'Autor', 'company' => 'Firma', 'rating' => 'Ocena', 'status' => 'Status'],
            'fields' => [
                'author' => ['label' => 'Autor', 'type' => 'text', 'required' => true],
                'company' => ['label' => 'Firma', 'type' => 'text'],
                'role' => ['label' => 'Stanowisko / opis', 'type' => 'text'],
                'quote' => ['label' => 'Treść opinii', 'type' => 'textarea', 'required' => true],
                'rating' => ['label' => 'Ocena 1-5', 'type' => 'number'],
                'source_url' => ['label' => 'Link źródłowy', 'type' => 'text'],
                'is_featured' => ['label' => 'Wyróżniona opinia', 'type' => 'checkbox'],
                'status' => ['label' => 'Status', 'type' => 'status'],
                'sort_order' => ['label' => 'Kolejność', 'type' => 'number'],
            ],
        ],
        'faqs' => [
            'route' => '/admin/business/faqs',
            'label' => 'FAQ',
            'singular' => 'pytanie',
            'title_field' => 'question',
            'columns' => ['question' => 'Pytanie', 'scope_type' => 'Zakres', 'scope_slug' => 'Adres URL', 'status' => 'Status'],
            'fields' => [
                'question' => ['label' => 'Pytanie', 'type' => 'text', 'required' => true],
                'answer' => ['label' => 'Odpowiedź', 'type' => 'textarea', 'required' => true],
                'scope_type' => ['label' => 'Zakres: global/service/area/case', 'type' => 'text'],
                'scope_slug' => ['label' => 'Adres URL elementu zakresu', 'type' => 'text'],
                'status' => ['label' => 'Status', 'type' => 'status'],
                'sort_order' => ['label' => 'Kolejność', 'type' => 'number'],
            ],
        ],
        'team' => [
            'route' => '/admin/business/team',
            'label' => 'Zespół',
            'singular' => 'osobę',
            'title_field' => 'name',
            'columns' => ['name' => 'Imię i nazwisko', 'role' => 'Rola', 'status' => 'Status'],
            'fields' => [
                'name' => ['label' => 'Imię i nazwisko', 'type' => 'text', 'required' => true],
                'role' => ['label' => 'Rola', 'type' => 'text'],
                'bio' => ['label' => 'Bio', 'type' => 'textarea'],
                'photo' => ['label' => 'Zdjęcie', 'type' => 'text'],
                'email' => ['label' => 'E-mail', 'type' => 'text'],
                'phone' => ['label' => 'Telefon', 'type' => 'text'],
                'status' => ['label' => 'Status', 'type' => 'status'],
                'sort_order' => ['label' => 'Kolejność', 'type' => 'number'],
            ],
        ],
        'ctas' => [
            'route' => '/admin/business/ctas',
            'label' => 'CTA',
            'singular' => 'wezwanie do działania',
            'title_field' => 'name',
            'columns' => ['name' => 'Nazwa', 'placement' => 'Miejsce', 'headline' => 'Nagłówek', 'status' => 'Status'],
            'fields' => [
                'name' => ['label' => 'Nazwa wewnętrzna', 'type' => 'text', 'required' => true],
                'placement' => ['label' => 'Miejsce: global/service/area/case', 'type' => 'text'],
                'headline' => ['label' => 'Nagłówek', 'type' => 'text', 'required' => true],
                'text' => ['label' => 'Tekst', 'type' => 'textarea'],
                'button_label' => ['label' => 'Tekst przycisku', 'type' => 'text'],
                'button_url' => ['label' => 'Adres przycisku', 'type' => 'text'],
                'status' => ['label' => 'Status', 'type' => 'status'],
                'sort_order' => ['label' => 'Kolejność', 'type' => 'number'],
            ],
        ],
    ];

    $tabs = static function (string $active) use ($sections, $h): string {
        $html = '<div class="privacy-tabs business-tabs"><a class="button ' . ($active === 'dashboard' ? '' : 'secondary') . '" href="/admin/business">Dashboard</a>';
        foreach ($sections as $type => $section) {
            $html .= '<a class="button ' . ($active === $type ? '' : 'secondary') . '" href="' . $h($section['route']) . '"' . ($active === $type ? ' aria-current="page"' : '') . '>' . $h($section['label']) . '</a>';
        }

        return $html . '</div>';
    };

    $fieldHtml = static function (string $name, array $field, array $item = []) use ($h): string {
        $value = $item[$name] ?? ($name === 'status' ? 'draft' : ($name === 'sort_order' ? '100' : ''));
        $required = !empty($field['required']) ? ' required' : '';
        $label = $h($field['label']);
        if ($field['type'] === 'textarea') {
            return '<label class="field field--wide">' . $label . '<textarea name="' . $h($name) . '"' . $required . '>' . $h($value) . '</textarea></label>';
        }
        if ($field['type'] === 'status') {
            return '<label class="field">Status<select name="' . $h($name) . '"><option value="draft"' . ((string) $value === 'draft' ? ' selected' : '') . '>Szkic</option><option value="published"' . ((string) $value === 'published' ? ' selected' : '') . '>Opublikowane</option></select></label>';
        }
        if ($field['type'] === 'checkbox') {
            return '<label class="field field--switch"><input type="checkbox" name="' . $h($name) . '" value="1"' . (!empty($value) ? ' checked' : '') . '> ' . $label . '</label>';
        }

        return '<label class="field">' . $label . '<input type="' . ($field['type'] === 'number' ? 'number' : 'text') . '" name="' . $h($name) . '" value="' . $h($value) . '"' . $required . '></label>';
    };

    $renderSection = static function (AdminView $view, array $user, string $type) use ($repo, $sections, $tabs, $fieldHtml, $h): void {
        $section = $sections[$type];
        $message = '';
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && Csrf::verify($_POST['_csrf'] ?? null)) {
            $action = (string) ($_POST['action'] ?? 'save');
            if ($action === 'delete') {
                $repo->delete($type, (int) ($_POST['id'] ?? 0));
                Url::redirect($section['route']);
            }

            $id = isset($_POST['id']) && $_POST['id'] !== '' ? (int) $_POST['id'] : null;
            $repo->save($type, $_POST, $id);
            $message = '<div class="notice">Zapisano ' . $h($section['singular']) . '.</div>';
        }

        $edit = isset($_GET['id']) ? $repo->find($type, (int) $_GET['id']) : null;
        $form = '<section class="panel"><h2>' . ($edit ? 'Edytuj' : 'Dodaj') . ' ' . $h($section['singular']) . '</h2><form method="post" class="privacy-settings-grid">' . Csrf::field();
        if ($edit) {
            $form .= '<input type="hidden" name="id" value="' . (int) $edit['id'] . '">';
        }
        foreach ($section['fields'] as $name => $field) {
            $form .= $fieldHtml($name, $field, $edit ?: []);
        }
        $form .= '<div class="field field--wide"><button>Zapisz</button></div></form></section>';

        $rows = '';
        foreach ($repo->all($type) as $item) {
            $rows .= '<tr>';
            foreach ($section['columns'] as $field => $label) {
                $rows .= '<td>' . $h($item[$field] ?? '') . '</td>';
            }
            $rows .= '<td><div class="actions"><a class="button secondary" href="' . $h($section['route']) . '?id=' . (int) $item['id'] . '">Edytuj</a>'
                . '<form method="post" onsubmit="return confirm(\'Usunąć ten element?\')">' . Csrf::field() . '<input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="' . (int) $item['id'] . '"><button class="secondary">Usuń</button></form></div></td></tr>';
        }

        $head = '<tr>';
        foreach ($section['columns'] as $label) {
            $head .= '<th>' . $h($label) . '</th>';
        }
        $head .= '<th></th></tr>';
        $list = '<section class="panel"><h2>' . $h($section['label']) . '</h2><table><thead>' . $head . '</thead><tbody>' . ($rows ?: '<tr><td colspan="' . (count($section['columns']) + 1) . '">Brak elementów.</td></tr>') . '</tbody></table></section>';

        $view->render('Strona główna - ' . $section['label'], $tabs($type) . $message . $form . $list, $user);
    };

    $dashboard = static function (AdminView $view, array $user) use ($repo, $tabs, $h): void {
        $metrics = [
            'Usługi' => count($repo->all('services')),
            'Lokalizacje' => count($repo->all('areas')),
            'Realizacje' => count($repo->all('cases')),
            'Opinie' => count($repo->all('testimonials')),
            'FAQ' => count($repo->all('faqs')),
            'CTA' => count($repo->all('ctas')),
        ];
        $grid = '<div class="grid">';
        foreach ($metrics as $label => $value) {
            $grid .= '<div class="metric"><span>' . $h($label) . '</span><b>' . (int) $value . '</b></div>';
        }
        $grid .= '</div>';
        $content = $tabs('dashboard') . '<section class="panel system-hero"><div><span class="eyebrow">Treści strony głównej</span><h2>Sekcje i elementy strony firmowej</h2><p>Zarządzaj usługami, lokalnymi podstronami, realizacjami, opiniami, FAQ, zespołem i wezwaniami do działania. To treści, które motyw klienta może wykorzystać na stronie głównej, landing pageach i podstronach SEO.</p></div><a class="button" href="/admin/business/services">Dodaj usługę</a></section>' . $grid;
        $view->render('Strona główna', $content, $user);
    };

    $routes = [
        '/admin/business' => $dashboard,
    ];
    foreach (array_keys($sections) as $type) {
        $routes[$sections[$type]['route']] = static fn (AdminView $view, array $user) => $renderSection($view, $user, $type);
    }

    return [
        'nav' => ['/admin/business' => 'Strona główna'],
        'routes' => $routes,
    ];
};
