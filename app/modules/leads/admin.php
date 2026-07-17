<?php

declare(strict_types=1);

use Reklamova\Cms\Admin\AdminView;
use Reklamova\Cms\Auth\Csrf;
use Reklamova\Cms\Modules\Leads\LeadRepository;
use Reklamova\Cms\Support\Url;

require_once __DIR__ . '/src/LeadRepository.php';

return static function (array $container, PDO $pdo, array $module): array {
    $repo = new LeadRepository($pdo);
    $h = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES);
    $statuses = [
        'new' => 'Nowy',
        'in_progress' => 'W toku',
        'won' => 'Wygrany',
        'lost' => 'Przegrany',
        'spam' => 'Spam',
        'archived' => 'Archiwum',
    ];

    $inbox = static function (AdminView $view, array $user) use ($repo, $h, $statuses): void {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && Csrf::verify($_POST['_csrf'] ?? null)) {
            $repo->updateStatus((int) ($_POST['id'] ?? 0), (string) ($_POST['status'] ?? 'new'), (int) $user['id'], (string) ($_POST['note'] ?? ''));
            Url::redirect('/admin/leads');
        }

        $rows = '';
        foreach ($repo->all() as $lead) {
            $statusOptions = '';
            foreach ($statuses as $value => $label) {
                $statusOptions .= '<option value="' . $h($value) . '"' . ((string) $lead['status'] === $value ? ' selected' : '') . '>' . $h($label) . '</option>';
            }
            $rows .= '<tr><td><b>' . $h($lead['name'] ?: 'Bez nazwy') . '</b><br>' . $h($lead['email']) . '<br>' . $h($lead['phone']) . '</td>'
                . '<td>' . $h($lead['form_type']) . '<br><small>' . $h($lead['source']) . '</small></td>'
                . '<td>' . nl2br($h($lead['message'])) . '</td>'
                . '<td>' . $h($lead['created_at']) . '</td>'
                . '<td><form method="post" class="lead-status-form">' . Csrf::field() . '<input type="hidden" name="id" value="' . (int) $lead['id'] . '"><select name="status">' . $statusOptions . '</select><textarea name="note" placeholder="Notatka">' . $h($lead['note']) . '</textarea><button>Zapisz</button></form></td></tr>';
        }

        $content = '<section class="panel system-hero"><div><span class="eyebrow">Reklamova Leads</span><h2>Skrzynka zapytań</h2><p>Jedno miejsce na formularze kontaktowe, kalkulatory, landing page i kampanie. Statusy pomagają prowadzić obsługę zapytań bez arkuszy i chaosu.</p></div></section>'
            . '<table><thead><tr><th>Kontakt</th><th>Źródło</th><th>Wiadomość</th><th>Data</th><th>Status</th></tr></thead><tbody>' . ($rows ?: '<tr><td colspan="5">Brak leadów.</td></tr>') . '</tbody></table>';

        $view->render('Formularze', $content, $user);
    };

    return [
        'nav' => ['/admin/leads' => 'Formularze'],
        'routes' => ['/admin/leads' => $inbox],
    ];
};
