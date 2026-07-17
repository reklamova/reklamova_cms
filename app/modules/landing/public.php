<?php

declare(strict_types=1);

use Reklamova\Cms\Modules\Landing\LandingRepository;
use Reklamova\Cms\Support\Config;

require_once __DIR__ . '/src/LandingRepository.php';

return static function (array $container, PDO $pdo, array $module): array {
    $repo = new LandingRepository($pdo);
    $siteName = (string) (new Config($container))->get('app', 'name', 'Reklamova CMS');
    $h = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES);

    $fallback = static function (string $slug) use ($repo, $siteName, $h): bool {
        if (!str_starts_with($slug, 'lp/')) {
            return false;
        }
        $page = $repo->findBySlug(substr($slug, 3));
        if (!$page) {
            return false;
        }
        $benefits = json_decode((string) $page['benefits_json'], true) ?: [];
        $sections = json_decode((string) $page['sections_json'], true) ?: [];
        $faq = json_decode((string) $page['faq_json'], true) ?: [];
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="pl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . $h($page['meta_title'] ?: $page['hero_title']) . ' - ' . $h($siteName) . '</title>'
            . '<style>body{margin:0;font-family:Geologica,system-ui,sans-serif;color:#171c2c;background:#f7f8fb;line-height:1.65}.lp{width:min(1120px,calc(100% - 36px));margin:0 auto}.hero{padding:86px 0 42px;display:grid;grid-template-columns:minmax(0,1.1fr) minmax(320px,.7fr);gap:34px;align-items:center}.hero h1{font-size:clamp(42px,7vw,82px);line-height:.96;margin:0 0 18px}.card{background:#fff;border:1px solid #e1e7f1;border-radius:8px;padding:24px;box-shadow:0 20px 70px rgba(27,34,54,.08)}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:18px;margin:20px 0 46px}.benefit{padding:18px;border-radius:8px;background:#fff;border:1px solid #e1e7f1}.form input,.form textarea{width:100%;padding:13px;border:1px solid #dfe6f1;border-radius:8px;margin-bottom:10px}.btn{display:inline-flex;border:0;border-radius:8px;background:#f7b51e;color:#171200;padding:14px 18px;font-weight:800}.faq details{padding:14px 0;border-bottom:1px solid #e1e7f1}@media(max-width:800px){.hero{grid-template-columns:1fr}}</style></head><body><main class="lp">';
        echo '<section class="hero"><div><h1>' . $h($page['hero_title']) . '</h1><p>' . $h($page['hero_text']) . '</p></div>';
        if ((int) $page['form_enabled'] === 1) {
            echo '<form class="card form" method="post" action="/api/leads"><h2>' . $h($page['form_title'] ?: 'Zapytaj o oferte') . '</h2><input type="hidden" name="source" value="' . $h($page['campaign_source'] ?: 'landing') . '"><input type="hidden" name="form_type" value="landing"><input type="hidden" name="page_url" value="/lp/' . $h($page['slug']) . '"><input name="website" tabindex="-1" autocomplete="off" style="position:absolute;left:-9999px"><input name="name" placeholder="Imie i nazwisko"><input name="email" type="email" placeholder="E-mail"><input name="phone" placeholder="Telefon"><textarea name="message" placeholder="Wiadomosc"></textarea><button class="btn">' . $h($page['cta_label'] ?: 'Wyslij zapytanie') . '</button></form>';
        }
        echo '</section><section class="grid">';
        foreach ($benefits as $benefit) {
            echo '<div class="benefit">' . $h($benefit) . '</div>';
        }
        echo '</section>';
        foreach ($sections as $section) {
            echo '<section class="card"><h2>' . $h($section['title'] ?? '') . '</h2><p>' . nl2br($h($section['text'] ?? '')) . '</p></section>';
        }
        if ($faq) {
            echo '<section class="card faq"><h2>Najczestsze pytania</h2>';
            foreach ($faq as $item) {
                echo '<details><summary>' . $h($item['question'] ?? '') . '</summary><p>' . nl2br($h($item['answer'] ?? '')) . '</p></details>';
            }
            echo '</section>';
        }
        echo '</main></body></html>';
        return true;
    };

    return ['fallbacks' => [$fallback]];
};
