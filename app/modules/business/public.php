<?php

declare(strict_types=1);

use Reklamova\Cms\Modules\Business\BusinessRepository;
use Reklamova\Cms\Support\Config;

require_once __DIR__ . '/src/BusinessRepository.php';

return static function (array $container, PDO $pdo, array $module): array {
    $repo = new BusinessRepository($pdo);
    $config = new Config($container);
    $siteName = (string) $config->get('app', 'name', 'Reklamova CMS');
    $h = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES);

    $layout = static function (string $title, string $body, array $schema = []) use ($siteName, $h): void {
        header('Content-Type: text/html; charset=utf-8');
        $schemaHtml = $schema ? '<script type="application/ld+json">' . json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>' : '';
        $privacyHead = function_exists('privacy_head') ? privacy_head() : '';
        $privacyStart = function_exists('privacy_body_start') ? privacy_body_start() : '';
        $privacyEnd = function_exists('privacy_body_end') ? privacy_body_end() : '';
        $privacyFooter = function_exists('privacy_footer_link') ? privacy_footer_link() : '';

        echo '<!doctype html><html lang="pl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . $h($title) . ' - ' . $h($siteName) . '</title>'
            . '<style>body{margin:0;font-family:Geologica,system-ui,sans-serif;color:#171c2c;background:#f7f8fb;line-height:1.65}a{color:inherit}.biz-wrap{width:min(1120px,calc(100% - 36px));margin:0 auto}.biz-hero{padding:86px 0 46px}.biz-hero h1{font-size:clamp(38px,6vw,76px);line-height:1;margin:0 0 18px}.biz-hero p{max-width:760px;color:#596276;font-size:18px}.biz-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:18px;padding:0 0 56px}.biz-card{display:block;padding:24px;border:1px solid #e1e7f1;border-radius:8px;background:#fff;text-decoration:none;box-shadow:0 18px 54px rgba(27,34,54,.07)}.biz-card h2,.biz-card h3{margin:0 0 10px}.biz-card p,.biz-muted{color:#667085}.biz-content{padding:0 0 64px}.biz-content section{padding:28px;margin-bottom:18px;border:1px solid #e1e7f1;border-radius:8px;background:#fff}.biz-cta{margin:22px 0 64px;padding:28px;border-radius:8px;background:#111522;color:#fff}.biz-cta a{display:inline-flex;margin-top:14px;padding:12px 18px;border-radius:8px;background:#f7b51e;color:#171200;text-decoration:none;font-weight:700}.biz-faq details{padding:16px 0;border-bottom:1px solid #e8edf5}.biz-faq summary{cursor:pointer;font-weight:700}</style>'
            . $privacyHead . $schemaHtml . '</head><body>' . $privacyStart
            . '<main class="biz-wrap">' . $body . '</main>'
            . '<footer class="biz-wrap" style="padding:30px 0;color:#667085">' . $privacyFooter . '</footer>'
            . $privacyEnd . '</body></html>';
    };

    $cta = static function (string $placement) use ($repo, $h): string {
        $item = $repo->publishedCta($placement);
        if (!$item) {
            return '';
        }

        return '<section class="biz-cta"><h2>' . $h($item['headline']) . '</h2><p>' . $h($item['text'] ?? '') . '</p>'
            . (($item['button_label'] ?? '') !== '' ? '<a href="' . $h($item['button_url'] ?? '#') . '">' . $h($item['button_label']) . '</a>' : '') . '</section>';
    };

    $faq = static function (string $scopeType = 'global', ?string $scopeSlug = null) use ($repo, $h): string {
        $items = $repo->publishedFaqs($scopeType, $scopeSlug);
        if (!$items) {
            return '';
        }
        $html = '<section class="biz-faq"><h2>Najczęstsze pytania</h2>';
        foreach ($items as $item) {
            $html .= '<details><summary>' . $h($item['question']) . '</summary><p>' . nl2br($h($item['answer'])) . '</p></details>';
        }

        return $html . '</section>';
    };

    $card = static function (array $item, string $href, string $titleField = 'title') use ($h): string {
        return '<a class="biz-card" href="' . $h($href) . '"><h2>' . $h($item[$titleField] ?? '') . '</h2><p>' . $h($item['summary'] ?? '') . '</p></a>';
    };

    $renderList = static function (string $title, string $intro, array $items, string $base, string $titleField = 'title') use ($layout, $card, $h): void {
        $body = '<section class="biz-hero"><h1>' . $h($title) . '</h1><p>' . $h($intro) . '</p></section><section class="biz-grid">';
        foreach ($items as $item) {
            $body .= $card($item, $base . '/' . $item['slug'], $titleField);
        }
        $body .= $items ? '</section>' : '<p class="biz-muted">Ta sekcja nie ma jeszcze opublikowanych elementów.</p></section>';
        $layout($title, $body);
    };

    $fallback = static function (string $slug) use ($repo, $layout, $renderList, $cta, $faq, $h): bool {
        if ($slug === 'uslugi') {
            $renderList('Usługi', 'Poznaj ofertę, specjalizacje i obszary, w których firma realnie pomaga klientom.', $repo->all('services', true), '/uslugi');
            return true;
        }
        if (str_starts_with($slug, 'uslugi/')) {
            $item = $repo->findBySlug('services', substr($slug, 7));
            if (!$item) {
                return false;
            }
            $body = '<section class="biz-hero"><h1>' . $h($item['title']) . '</h1><p>' . $h($item['summary'] ?? '') . '</p></section>'
                . '<article class="biz-content"><section>' . nl2br($h($item['description'] ?? '')) . '</section>' . $faq('service', $item['slug']) . $cta('service') . '</article>';
            $layout((string) ($item['meta_title'] ?: $item['title']), $body, ['@context' => 'https://schema.org', '@type' => 'Service', 'name' => $item['title'], 'description' => $item['summary'] ?? '']);
            return true;
        }
        if ($slug === 'realizacje') {
            $renderList('Realizacje', 'Zobacz przykłady prac, efektów i historii klientów.', $repo->all('cases', true), '/realizacje');
            return true;
        }
        if (str_starts_with($slug, 'realizacje/')) {
            $item = $repo->findBySlug('cases', substr($slug, 11));
            if (!$item) {
                return false;
            }
            $body = '<section class="biz-hero"><h1>' . $h($item['title']) . '</h1><p>' . $h($item['summary'] ?? '') . '</p></section><article class="biz-content">'
                . '<section><h2>Wyzwanie</h2><p>' . nl2br($h($item['challenge'] ?? '')) . '</p></section>'
                . '<section><h2>Rozwiązanie</h2><p>' . nl2br($h($item['solution'] ?? '')) . '</p></section>'
                . '<section><h2>Efekt</h2><p>' . nl2br($h($item['result'] ?? '')) . '</p></section>' . $cta('case') . '</article>';
            $layout((string) ($item['meta_title'] ?: $item['title']), $body);
            return true;
        }
        if (str_starts_with($slug, 'lokalizacje/')) {
            $item = $repo->findBySlug('areas', substr($slug, 12));
            if (!$item) {
                return false;
            }
            $body = '<section class="biz-hero"><h1>' . $h($item['name']) . '</h1><p>' . $h($item['summary'] ?? '') . '</p></section>'
                . '<article class="biz-content"><section>' . nl2br($h($item['description'] ?? '')) . '</section>' . $faq('area', $item['slug']) . $cta('area') . '</article>';
            $layout((string) ($item['meta_title'] ?: $item['name']), $body);
            return true;
        }
        if ($slug === 'zespol') {
            $body = '<section class="biz-hero"><h1>Zespół</h1><p>Ludzie, którzy stoją za jakością obsługi, doradztwa i realizacji.</p></section><section class="biz-grid">';
            foreach ($repo->all('team', true) as $person) {
                $body .= '<article class="biz-card"><h2>' . $h($person['name']) . '</h2><p><b>' . $h($person['role'] ?? '') . '</b></p><p>' . $h($person['bio'] ?? '') . '</p></article>';
            }
            $layout('Zespół', $body . '</section>');
            return true;
        }

        return false;
    };

    return [
        'fallbacks' => [$fallback],
    ];
};
