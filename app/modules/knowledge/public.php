<?php

declare(strict_types=1);

use Reklamova\Cms\Modules\Knowledge\KnowledgeRepository;
use Reklamova\Cms\Support\Config;

require_once __DIR__ . '/src/KnowledgeRepository.php';

return static function (array $container, PDO $pdo, array $module): array {
    $repo = new KnowledgeRepository($pdo);
    $config = new Config($container);
    $siteName = (string) $config->get('app', 'name', 'Reklamova CMS');
    $h = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES);

    $layout = static function (string $title, string $body, array $schema = []) use ($siteName, $h): void {
        header('Content-Type: text/html; charset=utf-8');
        $schemaHtml = $schema ? '<script type="application/ld+json">' . json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>' : '';
        echo '<!doctype html><html lang="pl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . $h($title) . ' - ' . $h($siteName) . '</title>'
            . '<style>body{margin:0;font-family:Geologica,system-ui,sans-serif;color:#171c2c;background:#f7f8fb;line-height:1.7}.kh{width:min(1060px,calc(100% - 36px));margin:0 auto}.kh-hero{padding:76px 0 32px}.kh-hero h1{font-size:clamp(38px,6vw,72px);line-height:1;margin:0 0 18px}.kh-search{display:flex;gap:10px;max-width:680px}.kh-search input{flex:1;padding:14px;border:1px solid #dfe6f1;border-radius:8px}.kh-search button,.kh-card a{padding:14px 18px;border:0;border-radius:8px;background:#f7b51e;color:#171200;font-weight:700;text-decoration:none}.kh-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(270px,1fr));gap:18px;padding:20px 0 70px}.kh-card,.kh-article{background:#fff;border:1px solid #e1e7f1;border-radius:8px;padding:24px;box-shadow:0 18px 54px rgba(27,34,54,.07)}.kh-card h2{margin:0 0 10px}.muted{color:#667085}.kh-article{margin-bottom:70px}.kh-article h1{font-size:clamp(34px,5vw,64px);line-height:1.05}</style>'
            . $schemaHtml . '</head><body><main class="kh">' . $body . '</main></body></html>';
    };

    $fallback = static function (string $slug) use ($repo, $layout, $h): bool {
        if ($slug === 'poradnik') {
            $search = trim((string) ($_GET['szukaj'] ?? ''));
            $body = '<section class="kh-hero"><h1>Poradnik</h1><p class="muted">Praktyczna baza wiedzy, odpowiedzi na pytania klientów i treści wspierające SEO.</p><form class="kh-search" method="get" action="/poradnik"><input name="szukaj" value="' . $h($search) . '" placeholder="Szukaj w poradniku"><button>Szukaj</button></form></section><section class="kh-grid">';
            foreach ($repo->articles(true, $search) as $article) {
                $body .= '<article class="kh-card"><p class="muted">' . $h($article['category_name'] ?? '') . '</p><h2>' . $h($article['title']) . '</h2><p>' . $h($article['excerpt'] ?? '') . '</p><a href="/poradnik/' . $h($article['slug']) . '">Czytaj</a></article>';
            }
            $layout('Poradnik', $body . '</section>');
            return true;
        }

        if (str_starts_with($slug, 'poradnik/')) {
            $article = $repo->articleBySlug(substr($slug, 9));
            if (!$article) {
                return false;
            }
            $title = (string) ($article['meta_title'] ?: $article['title']);
            $schema = [
                '@context' => 'https://schema.org',
                '@type' => 'Article',
                'headline' => $article['title'],
                'description' => $article['excerpt'] ?? '',
                'datePublished' => $article['published_at'] ?? $article['created_at'],
                'author' => ['@type' => 'Person', 'name' => $article['author_name'] ?: 'Reklamova CMS'],
            ];
            $body = '<article class="kh-article"><p class="muted">' . $h($article['category_name'] ?? '') . '</p><h1>' . $h($article['title']) . '</h1><p class="muted">' . $h($article['excerpt'] ?? '') . '</p><hr>' . nl2br($h($article['content'] ?? '')) . '</article>';
            $layout($title, $body, $schema);
            return true;
        }

        return false;
    };

    return ['fallbacks' => [$fallback]];
};
