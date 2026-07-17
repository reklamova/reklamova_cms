<?php

declare(strict_types=1);

use Reklamova\Cms\Modules\Trust\TrustRepository;
use Reklamova\Cms\Support\Config;

require_once __DIR__ . '/src/TrustRepository.php';

return static function (array $container, PDO $pdo, array $module): array {
    $repo = new TrustRepository($pdo);
    $siteName = (string) (new Config($container))->get('app', 'name', 'Reklamova CMS');
    $h = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES);

    $fallback = static function (string $slug) use ($repo, $siteName, $h): bool {
        if ($slug !== 'zaufanie') {
            return false;
        }
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="pl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Opinie i referencje - ' . $h($siteName) . '</title><style>body{margin:0;font-family:Geologica,system-ui,sans-serif;background:#f7f8fb;color:#171c2c;line-height:1.65}.trust{width:min(1120px,calc(100% - 36px));margin:0 auto}.hero{padding:82px 0 32px}.hero h1{font-size:clamp(42px,7vw,80px);line-height:1;margin:0}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:18px;padding-bottom:72px}.card{background:#fff;border:1px solid #e1e7f1;border-radius:8px;padding:24px;box-shadow:0 18px 54px rgba(27,34,54,.07)}.value{font-size:42px;font-weight:800;color:#f2a30f}.muted{color:#667085}</style></head><body><main class="trust"><section class="hero"><h1>Opinie, certyfikaty i referencje</h1><p class="muted">Certyfikaty, partnerzy, liczby, nagrody i dokumenty potwierdzające wiarygodność.</p></section><section class="grid">';
        foreach ($repo->all(true) as $item) {
            echo '<article class="card">' . (($item['value'] ?? '') !== '' ? '<div class="value">' . $h($item['value']) . '</div>' : '') . '<h2>' . $h($item['title']) . '</h2><p class="muted">' . $h($item['subtitle']) . '</p><p>' . $h($item['description']) . '</p>';
            if (($item['file_url'] ?? '') !== '') {
                echo '<a href="' . $h($item['file_url']) . '">Pobierz</a>';
            } elseif (($item['external_url'] ?? '') !== '') {
                echo '<a href="' . $h($item['external_url']) . '">Zobacz</a>';
            }
            echo '</article>';
        }
        echo '</section></main></body></html>';
        return true;
    };

    return ['fallbacks' => [$fallback]];
};
