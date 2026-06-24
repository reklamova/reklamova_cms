<?php

use Reklamova\Cms\Auth\Csrf;

return static function (array $container, PDO $pdo, array $module): array {
    $h = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES);

    $settings = static function () use ($pdo): array {
        $statement = $pdo->prepare('SELECT setting_value FROM cms_settings WHERE setting_key = "mero.calculator"');
        $statement->execute();
        $value = $statement->fetchColumn();
        return is_string($value) ? (json_decode($value, true) ?: []) : [];
    };

    $page = static function (string $slug) use ($pdo): ?array {
        $statement = $pdo->prepare('SELECT title, slug, content, updated_at FROM cms_pages WHERE slug = ? AND status = "published" LIMIT 1');
        $statement->execute([$slug]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    };

    $articles = static function () use ($pdo): array {
        return $pdo->query('SELECT title, slug, excerpt, content, published_at FROM mero_articles WHERE status = "published" ORDER BY published_at DESC, updated_at DESC')->fetchAll();
    };

    $article = static function (string $slug) use ($pdo): ?array {
        $statement = $pdo->prepare('SELECT title, slug, excerpt, content, meta_title, meta_description, published_at FROM mero_articles WHERE slug = ? AND status = "published" LIMIT 1');
        $statement->execute([$slug]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    };

    $submitLead = static function () use ($pdo, $settings): void {
        header('Content-Type: application/json; charset=utf-8');
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            http_response_code(405);
            echo json_encode(['ok' => false, 'message' => 'Nieprawidlowa metoda.']);
            return;
        }

        $data = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($data)) {
            $data = $_POST;
        }

        if (!Csrf::verify($data['_csrf'] ?? ($data['csrf'] ?? null))) {
            http_response_code(419);
            echo json_encode(['ok' => false, 'message' => 'Sesja formularza wygasla. Odswiez strone i sprobuj ponownie.']);
            return;
        }

        if (!empty($data['website'] ?? '')) {
            echo json_encode(['ok' => true, 'redirect' => '/dziekujemy']);
            return;
        }

        $type = preg_replace('/[^a-z0-9_-]/i', '', (string) ($data['type'] ?? 'contact')) ?: 'contact';
        $name = trim((string) ($data['name'] ?? ''));
        $phone = trim((string) ($data['phone'] ?? ''));
        $email = trim((string) ($data['email'] ?? ''));

        $errors = [];
        if ($name === '') {
            $errors['name'] = 'Podaj imie i nazwisko.';
        }
        if (!preg_match('/^[0-9 +()-]{7,20}$/', $phone)) {
            $errors['phone'] = 'Podaj poprawny numer telefonu.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Podaj poprawny adres e-mail.';
        }
        if (empty($data['privacy'])) {
            $errors['privacy'] = 'Potwierdz zapoznanie sie z polityka prywatnosci.';
        }
        if ($errors) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'errors' => $errors]);
            return;
        }

        $statement = $pdo->prepare('INSERT INTO mero_leads (public_id, type, source, status, name, phone, email, location, payload, result, ip_address, user_agent) VALUES (?, ?, ?, "new", ?, ?, ?, ?, ?, ?, ?, ?)');
        $statement->execute([
            bin2hex(random_bytes(8)),
            $type,
            substr((string) ($data['source'] ?? $type), 0, 120),
            $name,
            $phone,
            strtolower($email),
            substr((string) ($data['location'] ?? ''), 0, 190),
            json_encode($data, JSON_UNESCAPED_SLASHES),
            isset($data['result']) ? json_encode($data['result'], JSON_UNESCAPED_SLASHES) : null,
            $_SERVER['REMOTE_ADDR'] ?? '',
            substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
        ]);

        $config = $settings();
        $admin = filter_var($config['admin_email'] ?? '', FILTER_VALIDATE_EMAIL) ?: 'biuro@mero.pl';
        @mail($admin, 'Nowe zapytanie ze strony MERO', "Nowy lead: {$name}\nTelefon: {$phone}\nEmail: {$email}\nTyp: {$type}");

        echo json_encode(['ok' => true, 'redirect' => '/dziekujemy']);
    };

    $calculator = static function (array $config) use ($h): string {
        $settingsJson = $h(json_encode($config, JSON_UNESCAPED_SLASHES));
        return '<section class="mero-card mero-calculator" data-settings="' . $settingsJson . '"><h2>Kalkulator budowy domu</h2><p>Podaj podstawowe parametry, a kalkulator pokaze orientacyjny zakres kosztow.</p>'
            . '<form class="mero-lead-form" data-type="calculator"><input type="hidden" name="_csrf" value="' . $h(Csrf::token()) . '"><input type="hidden" name="type" value="calculator"><input type="hidden" name="website" value="">'
            . '<label>Metraz domu<input type="number" name="area" min="30" max="800" value="120"></label>'
            . '<label>Zakres<select name="scope"><option value="sso">Stan surowy otwarty</option><option value="ssz">Stan surowy zamkniety</option><option value="developer">Stan deweloperski</option><option value="turnkey">Dom pod klucz</option></select></label>'
            . '<label>Dach<select name="roof"><option value="dwuspadowy">dwuspadowy</option><option value="wielospadowy">wielospadowy</option><option value="plaski">plaski</option><option value="inny">inny</option></select></label>'
            . '<label>Garaz<select name="garage"><option value="brak">brak</option><option value="jednostanowiskowy">jednostanowiskowy</option><option value="dwustanowiskowy">dwustanowiskowy</option><option value="w_bryle">w bryle</option><option value="osobny">osobny</option></select></label>'
            . '<div class="mero-result" aria-live="polite"></div>'
            . '<label>Imie i nazwisko<input name="name" required></label><label>Telefon<input name="phone" required></label><label>E-mail<input type="email" name="email" required></label><label>Lokalizacja<input name="location"></label>'
            . '<label class="check"><input type="checkbox" name="privacy" value="1" required> Akceptuje kontakt w sprawie zapytania i polityke prywatnosci.</label><button type="submit">Wyslij zapytanie</button><p class="mero-form-status"></p></form></section>';
    };

    $contact = static function () use ($h): string {
        return '<section class="mero-card"><h2>Kontakt</h2><form class="mero-lead-form" data-type="contact"><input type="hidden" name="_csrf" value="' . $h(Csrf::token()) . '"><input type="hidden" name="type" value="contact"><input type="hidden" name="website" value="">'
            . '<label>Imie i nazwisko<input name="name" required></label><label>Telefon<input name="phone" required></label><label>E-mail<input type="email" name="email" required></label><label>Miejscowosc<input name="location"></label><label>Wiadomosc<textarea name="message"></textarea></label>'
            . '<label class="check"><input type="checkbox" name="privacy" value="1" required> Akceptuje kontakt w sprawie zapytania i polityke prywatnosci.</label><button type="submit">Wyslij</button><p class="mero-form-status"></p></form></section>';
    };

    $render = static function (string $title, string $body, string $description = '') use ($h): void {
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="pl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . $h($title) . ' | MERO</title><meta name="description" content="' . $h($description ?: 'MERO - budowa domow, materialy budowlane i kalkulator kosztow.') . '">'
            . '<style>body{margin:0;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:#172033;background:#f6f3ee}a{color:inherit}.mero-header{display:flex;align-items:center;justify-content:space-between;gap:20px;padding:18px clamp(18px,4vw,54px);background:#fff;border-bottom:1px solid #e3ddd2;position:sticky;top:0;z-index:10}.mero-brand{font-weight:900;letter-spacing:.08em}.mero-nav{display:flex;gap:16px;flex-wrap:wrap;font-size:14px}.mero-hero{padding:72px clamp(18px,4vw,54px);background:#202937;color:#fff}.mero-hero h1{max-width:900px;font-size:clamp(36px,7vw,72px);line-height:.98;margin:0 0 20px;letter-spacing:0}.mero-main{padding:40px clamp(18px,4vw,54px);max-width:1180px;margin:auto}.mero-content{font-size:18px;line-height:1.65}.mero-card{background:#fff;border:1px solid #e3ddd2;border-radius:8px;padding:24px;margin:24px 0}.mero-card form{display:grid;gap:14px;grid-template-columns:repeat(auto-fit,minmax(220px,1fr))}.mero-card label{display:grid;gap:7px;font-weight:700}.mero-card input,.mero-card select,.mero-card textarea{width:100%;padding:12px;border:1px solid #cfc7ba;border-radius:6px;font:inherit}.mero-card textarea{min-height:120px}.mero-card button{min-height:44px;border:0;border-radius:6px;background:#c8522b;color:#fff;font-weight:800;padding:0 18px}.check{grid-column:1/-1;display:flex!important;grid-template-columns:auto 1fr!important;align-items:center}.check input{width:auto}.mero-result{grid-column:1/-1;padding:16px;border-radius:6px;background:#f5eee6;font-weight:800}.mero-footer{padding:32px clamp(18px,4vw,54px);background:#172033;color:#fff}.mero-article-list{display:grid;gap:16px}.mero-article-list article{background:#fff;border:1px solid #e3ddd2;border-radius:8px;padding:20px}</style>'
            . '</head><body><header class="mero-header"><a class="mero-brand" href="/">MERO</a><nav class="mero-nav"><a href="/budowa-domow">Budowa domow</a><a href="/kalkulator-budowy-domu">Kalkulator</a><a href="/hurtownia-materialow-budowlanych">Hurtownia</a><a href="/poradnik">Poradnik</a><a href="/kontakt">Kontakt</a></nav></header>'
            . $body
            . '<footer class="mero-footer">MERO Materialy Budowlane | ul. Kecka 72, 32-651 Bielany | biuro@mero.pl</footer>'
            . '<script>const money=n=>new Intl.NumberFormat("pl-PL",{style:"currency",currency:"PLN",maximumFractionDigits:0}).format(n);document.querySelectorAll(".mero-calculator").forEach(box=>{const s=JSON.parse(box.dataset.settings||"{}");const form=box.querySelector("form");const out=box.querySelector(".mero-result");const calc=()=>{const area=Number(form.area.value||0);const scope=form.scope.value;let total=area*Number((s.base_prices||{})[scope]||0);total*=Number((s.roof_multipliers||{})[form.roof.value]||1);total*=Number((s.garage_multipliers||{})[form.garage.value]||1);const p=Number(s.range_percent||15)/100;out.textContent=area?`Orientacyjnie: ${money(total*(1-p))} - ${money(total*(1+p))}`:"";form.dataset.result=JSON.stringify({area,scope,total_min:Math.round(total*(1-p)),total_max:Math.round(total*(1+p))});};form.addEventListener("input",calc);calc();});document.querySelectorAll(".mero-lead-form").forEach(form=>form.addEventListener("submit",async e=>{e.preventDefault();const data=Object.fromEntries(new FormData(form).entries());data.type=form.dataset.type||data.type;data.result=form.dataset.result?JSON.parse(form.dataset.result):null;const status=form.querySelector(".mero-form-status");status.textContent="Wysylam...";const res=await fetch("/api/mero/lead",{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify(data)});const json=await res.json();if(json.ok){location.href=json.redirect||"/dziekujemy";return;}status.textContent=json.message||"Sprawdz pola formularza."; }));</script></body></html>';
    };

    return [
        'routes' => [
            '/api/lead.php' => $submitLead,
            '/api/mero/lead' => $submitLead,
        ],
        'fallbacks' => [
            static function (string $slug) use ($page, $article, $articles, $settings, $calculator, $contact, $render, $h): bool {
                if (str_starts_with($slug, 'poradnik/')) {
                    $articleSlug = substr($slug, strlen('poradnik/'));
                    $row = $article($articleSlug);
                    if (!$row) {
                        return false;
                    }
                    $paragraphs = array_filter(preg_split('/\R{2,}/', trim((string) $row['content'])) ?: []);
                    $content = '';
                    foreach ($paragraphs as $paragraph) {
                        $content .= '<p>' . nl2br($h($paragraph)) . '</p>';
                    }
                    $body = '<section class="mero-hero"><h1>' . $h($row['title']) . '</h1><p>' . $h($row['excerpt']) . '</p></section><main class="mero-main"><article class="mero-content">' . $content . '</article></main>';
                    $render((string) ($row['meta_title'] ?: $row['title']), $body, (string) ($row['meta_description'] ?: $row['excerpt']));
                    return true;
                }

                $row = $page($slug);
                if (!$row) {
                    return false;
                }

                $content = (string) $row['content'];
                if ($slug === 'kalkulator-budowy-domu' || str_contains($content, 'data-mero-calculator')) {
                    $content = str_replace('<div data-mero-calculator></div>', $calculator($settings()), $content);
                }
                if ($slug === 'kontakt' || str_contains($content, 'data-mero-contact')) {
                    $content = str_replace('<div data-mero-contact></div>', $contact(), $content);
                }
                if ($slug === 'poradnik') {
                    $items = '';
                    foreach ($articles() as $article) {
                        $items .= '<article><h2><a href="/poradnik/' . $h($article['slug']) . '">' . $h($article['title']) . '</a></h2><p>' . $h($article['excerpt']) . '</p></article>';
                    }
                    $content .= '<section class="mero-article-list">' . $items . '</section>';
                }

                $body = '<section class="mero-hero"><h1>' . $h($row['title']) . '</h1></section><main class="mero-main"><article class="mero-content">' . $content . '</article></main>';
                $render((string) $row['title'], $body);
                return true;
            },
        ],
    ];
};
