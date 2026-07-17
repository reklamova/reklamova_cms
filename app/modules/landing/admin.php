<?php

declare(strict_types=1);

use Reklamova\Cms\Admin\AdminView;
use Reklamova\Cms\Auth\Csrf;
use Reklamova\Cms\Modules\Landing\LandingRepository;
use Reklamova\Cms\Support\Url;

require_once __DIR__ . '/src/LandingRepository.php';

return static function (array $container, PDO $pdo, array $module): array {
    $repo = new LandingRepository($pdo);
    $h = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES);
    $text = static fn (?string $json): string => implode("\n", json_decode((string) $json, true) ?: []);
    $sectionsText = static function (?string $json): string {
        $chunks = [];
        foreach (json_decode((string) $json, true) ?: [] as $section) {
            $chunks[] = trim(($section['title'] ?? '') . "\n" . ($section['text'] ?? ''));
        }
        return implode("\n\n", $chunks);
    };
    $faqText = static function (?string $json): string {
        $chunks = [];
        foreach (json_decode((string) $json, true) ?: [] as $item) {
            $chunks[] = trim(($item['question'] ?? '') . "\n" . ($item['answer'] ?? ''));
        }
        return implode("\n\n", $chunks);
    };

    $screen = static function (AdminView $view, array $user) use ($repo, $h, $text, $sectionsText, $faqText): void {
        $message = '';
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && Csrf::verify($_POST['_csrf'] ?? null)) {
            if (($_POST['action'] ?? '') === 'delete') {
                $repo->delete((int) ($_POST['id'] ?? 0));
                Url::redirect('/admin/landing-pages');
            }
            $repo->save($_POST, isset($_POST['id']) && $_POST['id'] !== '' ? (int) $_POST['id'] : null);
            $message = '<div class="notice">Strona kampanii została zapisana.</div>';
        }

        $edit = isset($_GET['id']) ? $repo->find((int) $_GET['id']) : null;
        $form = '<section class="panel"><h2>' . ($edit ? 'Edytuj stronę kampanii' : 'Nowa strona kampanii') . '</h2><form method="post" class="privacy-settings-grid">' . Csrf::field()
            . ($edit ? '<input type="hidden" name="id" value="' . (int) $edit['id'] . '">' : '')
            . '<label class="field field--half">Nazwa wewnętrzna<input name="name" required value="' . $h($edit['name'] ?? '') . '"></label>'
            . '<label class="field field--half">Adres URL<input name="slug" value="' . $h($edit['slug'] ?? '') . '"></label>'
            . '<label class="field">Źródło kampanii<input name="campaign_source" value="' . $h($edit['campaign_source'] ?? '') . '"></label>'
            . '<label class="field">Wariant<input name="template_variant" value="' . $h($edit['template_variant'] ?? 'lead') . '"></label>'
            . '<label class="field">Status<select name="status"><option value="draft">Szkic</option><option value="published"' . (($edit['status'] ?? '') === 'published' ? ' selected' : '') . '>Opublikowany</option></select></label>'
            . '<label class="field">Data publikacji<input name="published_at" value="' . $h($edit['published_at'] ?? '') . '"></label>'
            . '<label class="field field--half">Nagłówek hero<input name="hero_title" required value="' . $h($edit['hero_title'] ?? '') . '"></label>'
            . '<label class="field field--half">Obraz hero<input name="hero_image" value="' . $h($edit['hero_image'] ?? '') . '"></label>'
            . '<label class="field field--wide">Tekst hero<textarea name="hero_text">' . $h($edit['hero_text'] ?? '') . '</textarea></label>'
            . '<label class="field field--wide">Benefity - jeden na linię<textarea name="benefits">' . $h($text($edit['benefits_json'] ?? null)) . '</textarea></label>'
            . '<label class="field field--wide">Sekcje - blok: tytuł i tekst, oddzielone pustą linią<textarea name="sections">' . $h($sectionsText($edit['sections_json'] ?? null)) . '</textarea></label>'
            . '<label class="field field--wide">FAQ - blok: pytanie i odpowiedź, oddzielone pustą linią<textarea name="faq">' . $h($faqText($edit['faq_json'] ?? null)) . '</textarea></label>'
            . '<label class="field field--switch"><input type="checkbox" name="form_enabled" value="1"' . (!isset($edit['form_enabled']) || (int) $edit['form_enabled'] === 1 ? ' checked' : '') . '> Formularz leadowy aktywny</label>'
            . '<label class="field">Tytuł formularza<input name="form_title" value="' . $h($edit['form_title'] ?? 'Zapytaj o ofertę') . '"></label>'
            . '<label class="field">Tekst przycisku<input name="cta_label" value="' . $h($edit['cta_label'] ?? 'Wyslij zapytanie') . '"></label>'
            . '<label class="field field--wide">Komunikat po wysyłce<textarea name="thank_you_message">' . $h($edit['thank_you_message'] ?? 'Dziękujemy. Skontaktujemy się z Tobą.') . '</textarea></label>'
            . '<label class="field field--half">Meta title<input name="meta_title" value="' . $h($edit['meta_title'] ?? '') . '"></label>'
            . '<label class="field field--half">Meta description<textarea name="meta_description">' . $h($edit['meta_description'] ?? '') . '</textarea></label>'
            . '<div class="field field--wide"><button>Zapisz landing page</button></div></form></section>';

        $rows = '';
        foreach ($repo->all(false) as $page) {
            $rows .= '<tr><td><b>' . $h($page['name']) . '</b><br><small>/lp/' . $h($page['slug']) . '</small></td><td>' . $h($page['campaign_source']) . '</td><td>' . $h($page['status']) . '</td><td>' . $h($page['updated_at']) . '</td><td><div class="actions"><a class="button secondary" href="/admin/landing-pages?id=' . (int) $page['id'] . '">Edytuj</a><form method="post" onsubmit="return confirm(\'Usunąć stronę kampanii?\')">' . Csrf::field() . '<input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="' . (int) $page['id'] . '"><button class="secondary">Usuń</button></form></div></td></tr>';
        }
        $list = '<section class="panel"><h2>Landing pages</h2><table><thead><tr><th>Nazwa</th><th>Kampania</th><th>Status</th><th>Aktualizacja</th><th></th></tr></thead><tbody>' . ($rows ?: '<tr><td colspan="5">Brak stron kampanii.</td></tr>') . '</tbody></table></section>';

        $view->render('Landing pages', $message . $form . $list, $user);
    };

    return [
        'nav' => ['/admin/landing-pages' => 'Landing pages'],
        'routes' => ['/admin/landing-pages' => $screen],
    ];
};
