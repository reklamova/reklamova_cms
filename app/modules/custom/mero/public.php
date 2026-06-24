<?php

use Reklamova\Cms\Auth\Csrf;

return static function (array $container, PDO $pdo, array $module): array {
    $h = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES);

    $brand = [
        'name' => 'MERO',
        'email' => 'biuro@mero.pl',
        'phone_display' => '+48 720 446 446',
        'phone_tel' => 'tel:720446446',
        'address' => 'ul. Kecka 72, 32-651 Bielany',
        'facebook' => 'https://www.facebook.com/merobielany',
        'privacy_version' => '2026-06-24',
        'cookie_version' => '2026-06-24',
    ];

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

    $articles = static function (string $query = '') use ($pdo): array {
        if ($query !== '') {
            $statement = $pdo->prepare('SELECT title, slug, excerpt, content, published_at FROM mero_articles WHERE status = "published" AND (title LIKE ? OR excerpt LIKE ? OR content LIKE ?) ORDER BY published_at DESC, updated_at DESC');
            $like = '%' . $query . '%';
            $statement->execute([$like, $like, $like]);
            return $statement->fetchAll();
        }

        return $pdo->query('SELECT title, slug, excerpt, content, published_at FROM mero_articles WHERE status = "published" ORDER BY published_at DESC, updated_at DESC')->fetchAll();
    };

    $article = static function (string $slug) use ($pdo): ?array {
        $statement = $pdo->prepare('SELECT title, slug, excerpt, content, meta_title, meta_description, published_at FROM mero_articles WHERE slug = ? AND status = "published" LIMIT 1');
        $statement->execute([$slug]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    };

    $jsonResponse = static function (array $payload, int $status = 200): void {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    };

    $submitConsent = static function () use ($pdo, $brand, $jsonResponse): void {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            $jsonResponse(['ok' => false, 'message' => 'Nieprawidlowa metoda.'], 405);
            return;
        }

        $data = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($data)) {
            $data = [];
        }

        try {
            $statement = $pdo->prepare('INSERT INTO mero_cookie_consents (consent_version, privacy_policy_version, necessary, functional, analytics, marketing, source, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $statement->execute([
                $brand['cookie_version'],
                $brand['privacy_version'],
                1,
                !empty($data['functional']) ? 1 : 0,
                !empty($data['analytics']) ? 1 : 0,
                !empty($data['marketing']) ? 1 : 0,
                substr((string) ($data['source'] ?? 'cookie-popup'), 0, 80),
                $_SERVER['REMOTE_ADDR'] ?? '',
                substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
            ]);
        } catch (Throwable) {
            // Consent UX should keep working even before the optional table is migrated.
        }

        $jsonResponse(['ok' => true]);
    };

    $submitLead = static function () use ($pdo, $settings, $brand, $jsonResponse): void {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            $jsonResponse(['ok' => false, 'message' => 'Nieprawidlowa metoda.'], 405);
            return;
        }

        $data = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($data)) {
            $data = $_POST;
        }

        if (!Csrf::verify($data['_csrf'] ?? ($data['csrf'] ?? null))) {
            $jsonResponse(['ok' => false, 'message' => 'Sesja formularza wygasla. Odswiez strone i sprobuj ponownie.'], 419);
            return;
        }

        if (!empty($data['website'] ?? '')) {
            $jsonResponse(['ok' => true, 'redirect' => '/dziekujemy']);
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
            $errors['privacy'] = 'Potwierdz zapoznanie sie z Polityka prywatnosci i cookies.';
        }
        if ($errors) {
            $jsonResponse(['ok' => false, 'errors' => $errors, 'message' => 'Sprawdz wymagane pola formularza.'], 422);
            return;
        }

        $data['privacy_policy_version'] = (string) ($data['privacy_policy_version'] ?? $brand['privacy_version']);
        $data['privacy_accepted_at'] = gmdate('c');
        $data['cookie_consent_snapshot'] = (string) ($data['cookie_consent_snapshot'] ?? '');

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
        $admin = filter_var($config['admin_email'] ?? '', FILTER_VALIDATE_EMAIL) ?: $brand['email'];
        $mail = "Nowy lead: {$name}\nTelefon: {$phone}\nEmail: {$email}\nTyp: {$type}\nZrodlo: " . ((string) ($data['source'] ?? $type));
        @mail($admin, 'Nowe zapytanie ze strony MERO', $mail);

        $jsonResponse(['ok' => true, 'redirect' => '/dziekujemy']);
    };

    $privacyFields = static function () use ($h, $brand): string {
        return '<input type="hidden" name="privacy_policy_version" value="' . $h($brand['privacy_version']) . '">'
            . '<input type="hidden" name="cookie_consent_snapshot" value="" data-cookie-consent-field>'
            . '<div class="mero-consents">'
            . '<label class="check"><input type="checkbox" name="privacy" value="1" required> <span>Zapoznalem/am sie z <a href="/polityka-prywatnosci" target="_blank" rel="noopener">Polityka prywatnosci i cookies</a> oraz akceptuje kontakt w celu obslugi zapytania.</span></label>'
            . '<label class="check optional"><input type="checkbox" name="marketing_email" value="1"> <span>Zgadzam sie na kontakt mailowy w sprawach ofertowych MERO.</span></label>'
            . '<label class="check optional"><input type="checkbox" name="marketing_phone" value="1"> <span>Zgadzam sie na kontakt telefoniczny w sprawach ofertowych MERO.</span></label>'
            . '</div>';
    };

    $calculator = static function (array $config) use ($h, $privacyFields): string {
        $settingsJson = $h(json_encode($config, JSON_UNESCAPED_SLASHES));
        return '<section class="mero-card mero-calculator" data-settings="' . $settingsJson . '"><div class="section-kicker">Kalkulator</div><h2>Kalkulator budowy domu</h2><p>Podaj podstawowe parametry, a kalkulator pokaze orientacyjny zakres kosztow. Wynik pomaga zaczac rozmowe, nie jest finalna oferta.</p>'
            . '<form class="mero-lead-form" data-type="calculator"><input type="hidden" name="_csrf" value="' . $h(Csrf::token()) . '"><input type="hidden" name="type" value="calculator"><input type="hidden" name="source" value="kalkulator"><input type="hidden" name="website" value="">'
            . '<label>Metraz domu<input type="number" name="area" min="30" max="800" value="120"></label>'
            . '<label>Zakres<select name="scope"><option value="sso">Stan surowy otwarty</option><option value="ssz">Stan surowy zamkniety</option><option value="developer">Stan deweloperski</option><option value="turnkey">Dom pod klucz</option></select></label>'
            . '<label>Dach<select name="roof"><option value="dwuspadowy">dwuspadowy</option><option value="wielospadowy">wielospadowy</option><option value="plaski">plaski</option><option value="inny">inny</option></select></label>'
            . '<label>Garaz<select name="garage"><option value="brak">brak</option><option value="jednostanowiskowy">jednostanowiskowy</option><option value="dwustanowiskowy">dwustanowiskowy</option><option value="w_bryle">w bryle</option><option value="osobny">osobny</option></select></label>'
            . '<div class="mero-result" aria-live="polite"></div>'
            . '<label>Imie i nazwisko<input name="name" autocomplete="name" required></label><label>Telefon<input name="phone" autocomplete="tel" required></label><label>E-mail<input type="email" name="email" autocomplete="email" required></label><label>Lokalizacja<input name="location" autocomplete="address-level2"></label>'
            . $privacyFields() . '<button type="submit">Wyslij zapytanie</button><p class="mero-form-status" aria-live="polite"></p></form></section>';
    };

    $contact = static function () use ($h, $privacyFields, $brand): string {
        return '<section class="contact-layout"><div class="mero-card contact-card"><div class="section-kicker">Kontakt</div><h2>Porozmawiajmy o budowie lub materialach</h2><p>Opisz zakres prac, lokalizacje i termin. MERO pomoze uporzadkowac zapytanie, dobrac nastepny krok i sprawdzic mozliwosci dostawy.</p>'
            . '<div class="contact-points"><a href="' . $h($brand['phone_tel']) . '"><b>Telefon</b><span>' . $h($brand['phone_display']) . '</span></a><a href="mailto:' . $h($brand['email']) . '"><b>E-mail</b><span>' . $h($brand['email']) . '</span></a><a href="' . $h($brand['facebook']) . '" target="_blank" rel="noopener"><b>Facebook</b><span>MERO Bielany</span></a><span><b>Adres</b><span>' . $h($brand['address']) . '</span></span></div>'
            . '<form class="mero-lead-form" data-type="contact"><input type="hidden" name="_csrf" value="' . $h(Csrf::token()) . '"><input type="hidden" name="type" value="contact"><input type="hidden" name="source" value="kontakt"><input type="hidden" name="website" value="">'
            . '<label>Imie i nazwisko<input name="name" autocomplete="name" required></label><label>Telefon<input name="phone" autocomplete="tel" required></label><label>E-mail<input type="email" name="email" autocomplete="email" required></label><label>Miejscowosc<input name="location" autocomplete="address-level2"></label><label class="full">Wiadomosc<textarea name="message"></textarea></label>'
            . $privacyFields() . '<button type="submit">Wyslij</button><p class="mero-form-status" aria-live="polite"></p></form></div>'
            . '<div class="map-card" aria-label="Mapa dojazdu MERO"><iframe title="Mapa dojazdu do MERO Bielany" loading="lazy" referrerpolicy="no-referrer-when-downgrade" src="https://www.google.com/maps?q=MERO%20Bielany%20ul.%20Kecka%2072&output=embed"></iframe></div></section>';
    };

    $render = static function (string $title, string $body, string $description = '') use ($h, $brand): void {
        header('Content-Type: text/html; charset=utf-8');

        $host = (string) ($_SERVER['HTTP_HOST'] ?? 'mero.pl');
        $uri = strtok((string) ($_SERVER['REQUEST_URI'] ?? '/'), '?') ?: '/';
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'https';
        $canonical = $scheme . '://' . $host . $uri;
        $metaDescription = $description ?: 'MERO - budowa domow, materialy budowlane, transport HDS i kalkulator kosztow w Bielanach, Ketach, Oswiecimiu i okolicy.';
        $fullTitle = $title . ' | MERO';

        $schema = [
            '@context' => 'https://schema.org',
            '@graph' => [
                [
                    '@type' => 'ConstructionBusiness',
                    '@id' => $scheme . '://' . $host . '/#business',
                    'name' => 'MERO',
                    'url' => $scheme . '://' . $host . '/',
                    'email' => $brand['email'],
                    'telephone' => $brand['phone_display'],
                    'address' => [
                        '@type' => 'PostalAddress',
                        'streetAddress' => 'ul. Kecka 72',
                        'postalCode' => '32-651',
                        'addressLocality' => 'Bielany',
                        'addressCountry' => 'PL',
                    ],
                    'areaServed' => ['Bielany', 'Kety', 'Oswiecim', 'Bielsko-Biala', 'Kozy', 'Wilamowice', 'Osiek', 'Malec', 'Leki', 'Pisarzowice'],
                    'sameAs' => [$brand['facebook']],
                ],
                [
                    '@type' => 'WebSite',
                    '@id' => $scheme . '://' . $host . '/#website',
                    'url' => $scheme . '://' . $host . '/',
                    'name' => 'MERO',
                    'potentialAction' => [
                        '@type' => 'SearchAction',
                        'target' => $scheme . '://' . $host . '/poradnik?szukaj={search_term_string}',
                        'query-input' => 'required name=search_term_string',
                    ],
                ],
                [
                    '@type' => 'WebPage',
                    '@id' => $canonical . '#webpage',
                    'url' => $canonical,
                    'name' => $fullTitle,
                    'description' => $metaDescription,
                    'isPartOf' => ['@id' => $scheme . '://' . $host . '/#website'],
                ],
            ],
        ];

        echo '<!doctype html><html lang="pl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . $h($fullTitle) . '</title><meta name="description" content="' . $h($metaDescription) . '"><link rel="canonical" href="' . $h($canonical) . '"><link rel="icon" type="image/svg+xml" href="/favicon.svg">'
            . '<meta name="referrer" content="strict-origin-when-cross-origin"><meta name="geo.region" content="PL-12"><meta name="geo.country" content="PL"><meta name="author" content="MERO"><meta name="DC.date.modified" content="2026-06-24">'
            . '<meta property="og:type" content="website"><meta property="og:title" content="' . $h($fullTitle) . '"><meta property="og:description" content="' . $h($metaDescription) . '"><meta property="og:url" content="' . $h($canonical) . '"><meta property="og:site_name" content="MERO">'
            . '<meta name="twitter:card" content="summary_large_image"><meta name="twitter:title" content="' . $h($fullTitle) . '"><meta name="twitter:description" content="' . $h($metaDescription) . '">'
            . '<script type="application/ld+json">' . json_encode($schema, JSON_UNESCAPED_SLASHES) . '</script>'
            . '<style>:root{--ink:#172033;--muted:#657084;--line:#e3ddd2;--paper:#fff;--bg:#f6f3ee;--accent:#f15a22;--dark:#172033}*{box-sizing:border-box}body{margin:0;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:var(--ink);background:var(--bg)}a{color:inherit}.skip-link{position:absolute;left:18px;top:-80px;background:var(--accent);color:#fff;padding:10px 14px;border-radius:6px;z-index:2000}.skip-link:focus{top:18px}.mero-header{display:flex;align-items:center;justify-content:space-between;gap:20px;padding:18px clamp(18px,4vw,54px);background:#fff;border-bottom:1px solid var(--line);position:sticky;top:0;z-index:10}.mero-brand{display:inline-flex;align-items:center;text-decoration:none}.mero-brand img{display:block;width:148px;max-width:38vw;height:auto}.footer-logo{display:block;width:132px;max-width:100%;height:auto;margin-bottom:12px;filter:brightness(0) invert(1)}.mero-nav{display:flex;gap:16px;flex-wrap:wrap;font-size:14px;align-items:center}.mero-nav a{text-decoration:none}.mero-nav .phone{font-weight:900;color:var(--accent)}.mero-hero{padding:72px clamp(18px,4vw,54px);background:linear-gradient(135deg,#172033,#293445);color:#fff}.mero-hero h1{max-width:900px;font-size:clamp(34px,6vw,64px);line-height:1.02;margin:0 0 20px;letter-spacing:0}.mero-hero p{max-width:760px;font-size:clamp(17px,2vw,22px);line-height:1.55;color:#e8edf4}.mero-main{padding:40px clamp(18px,4vw,54px);max-width:1180px;margin:auto}.mero-content{font-size:18px;line-height:1.65}.section-kicker{color:var(--accent);font-weight:900;text-transform:uppercase;letter-spacing:.12em;font-size:12px}.mero-card{background:var(--paper);border:1px solid var(--line);border-radius:8px;padding:clamp(20px,3vw,32px);margin:24px 0;box-shadow:0 18px 50px rgba(23,32,51,.08)}.mero-card h2{font-size:clamp(28px,4vw,44px);line-height:1.08;margin:6px 0 12px}.mero-card form{display:grid;gap:14px;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));margin-top:22px}.mero-card label{display:grid;gap:7px;font-weight:800}.mero-card input,.mero-card select,.mero-card textarea{width:100%;padding:12px;border:1px solid #cfc7ba;border-radius:6px;font:inherit;background:#fff}.mero-card textarea{min-height:120px}.mero-card button{min-height:48px;border:0;border-radius:6px;background:var(--accent);color:#fff;font-weight:900;padding:0 20px;cursor:pointer}.full,.mero-consents,.mero-form-status{grid-column:1/-1}.check{display:flex!important;gap:10px;align-items:flex-start;font-weight:700;line-height:1.45}.check input{width:auto;margin-top:4px}.check.optional{font-weight:600;color:var(--muted)}.mero-result{grid-column:1/-1;padding:16px;border-radius:6px;background:#fff3ed;border:1px solid #ffd2c1;font-weight:900}.contact-layout{display:grid;grid-template-columns:minmax(0,1.15fr) minmax(320px,.85fr);gap:24px}.contact-points{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin:18px 0}.contact-points a,.contact-points span{display:grid;gap:4px;padding:14px;border:1px solid var(--line);border-radius:8px;background:#faf8f4;text-decoration:none}.contact-points b{color:var(--accent)}.map-card{min-height:420px;border-radius:8px;overflow:hidden;border:1px solid var(--line);box-shadow:0 18px 50px rgba(23,32,51,.08);background:#ddd}.map-card iframe{width:100%;height:100%;min-height:420px;border:0}.mero-footer{padding:32px clamp(18px,4vw,54px);background:var(--dark);color:#fff}.mero-footer-inner{display:flex;gap:18px;justify-content:space-between;flex-wrap:wrap;align-items:center}.mero-footer a{color:#fff}.mero-article-list{display:grid;gap:16px;margin-top:24px}.mero-article-list article{background:#fff;border:1px solid var(--line);border-radius:8px;padding:20px}.search-box{display:flex;gap:10px;flex-wrap:wrap;margin:20px 0}.search-box input{flex:1;min-width:220px;padding:12px;border:1px solid #cfc7ba;border-radius:6px}.search-box button{border:0;border-radius:6px;background:var(--accent);color:#fff;font-weight:900;padding:0 18px}.cookie-backdrop{position:fixed;inset:0;background:rgba(23,32,51,.58);display:none;align-items:center;justify-content:center;padding:18px;z-index:1000}.cookie-backdrop.is-visible{display:flex}.cookie-modal{width:min(640px,100%);background:#fff;border-radius:10px;padding:24px;box-shadow:0 30px 90px rgba(0,0,0,.35)}.cookie-modal h2{margin:0 0 10px;font-size:28px}.cookie-options{display:none;gap:10px;margin:14px 0}.cookie-options.is-visible{display:grid}.cookie-actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:18px}.cookie-actions button{border:0;border-radius:6px;min-height:44px;padding:0 16px;font-weight:900;cursor:pointer}.cookie-actions .primary{background:var(--accent);color:#fff}.cookie-actions .secondary{background:#eef1f5;color:var(--ink)}.cookie-settings{position:fixed;right:18px;bottom:18px;z-index:20;border:0;border-radius:999px;background:#fff;color:var(--ink);box-shadow:0 8px 30px rgba(23,32,51,.16);padding:10px 14px;font-weight:900;cursor:pointer}@media(max-width:820px){.mero-header{align-items:flex-start}.mero-nav{width:100%}.contact-layout{grid-template-columns:1fr}.mero-card h2{font-size:30px}}</style>'
            . '</head><body><a class="skip-link" href="#main-content">Przejdz do tresci</a><header class="mero-header"><a class="mero-brand" href="/"><img src="/assets/client/mero-logo.svg" alt="MERO"></a><nav class="mero-nav"><a href="/budowa-domow">Budowa domow</a><a href="/kalkulator-budowy-domu">Kalkulator</a><a href="/hurtownia-materialow-budowlanych">Hurtownia</a><a href="/poradnik">Poradnik</a><a href="/kontakt">Kontakt</a><a class="phone" href="' . $h($brand['phone_tel']) . '">' . $h($brand['phone_display']) . '</a></nav></header>'
            . $body
            . '<footer class="mero-footer"><div class="mero-footer-inner"><div><img class="footer-logo" src="/assets/client/mero-logo.svg" alt="MERO"><b>MERO Materialy Budowlane</b><br>' . $h($brand['address']) . '<br><a href="' . $h($brand['phone_tel']) . '">' . $h($brand['phone_display']) . '</a> | <a href="mailto:' . $h($brand['email']) . '">' . $h($brand['email']) . '</a></div><div><a href="' . $h($brand['facebook']) . '" target="_blank" rel="noopener">Facebook MERO Bielany</a><br><a href="/polityka-prywatnosci">Polityka prywatnosci i cookies</a><br>Realizacja: Reklamova</div><div>&copy; ' . date('Y') . ' MERO</div></div></footer>'
            . '<button class="cookie-settings" type="button" data-cookie-open>Cookies</button><div class="cookie-backdrop" data-cookie-modal role="dialog" aria-modal="true" aria-labelledby="cookie-title"><div class="cookie-modal"><h2 id="cookie-title">Prywatnosc i cookies</h2><p>Uzywamy niezbednych cookies do dzialania strony. Dodatkowe zgody pomagaja analizowac ruch i prowadzic komunikacje marketingowa. Mozesz zaakceptowac wszystkie albo ustawic wybrane zgody.</p><div class="cookie-options" data-cookie-options><label class="check"><input type="checkbox" checked disabled> <span>Niezbedne cookies</span></label><label class="check"><input type="checkbox" data-cookie-functional> <span>Funkcjonalne</span></label><label class="check"><input type="checkbox" data-cookie-analytics> <span>Analityczne</span></label><label class="check"><input type="checkbox" data-cookie-marketing> <span>Marketingowe</span></label></div><div class="cookie-actions"><button class="primary" type="button" data-cookie-accept>Akceptuje wszystkie</button><button class="secondary" type="button" data-cookie-save>Zapisz wybor</button><button class="secondary" type="button" data-cookie-custom>Dostosuj</button></div></div></div>'
            . '<script>const money=n=>new Intl.NumberFormat("pl-PL",{style:"currency",currency:"PLN",maximumFractionDigits:0}).format(n);const consentKey="mero_cookie_consent_v1";const readConsent=()=>{try{return JSON.parse(localStorage.getItem(consentKey)||"null")}catch(e){return null}};const writeConsent=async data=>{const payload={necessary:true,functional:!!data.functional,analytics:!!data.analytics,marketing:!!data.marketing,source:data.source||"cookie-popup",saved_at:new Date().toISOString()};localStorage.setItem(consentKey,JSON.stringify(payload));document.querySelectorAll("[data-cookie-consent-field]").forEach(input=>input.value=JSON.stringify(payload));try{await fetch("/api/mero/consent",{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify(payload)})}catch(e){}return payload};const syncConsent=()=>{const c=readConsent();if(c){document.querySelectorAll("[data-cookie-consent-field]").forEach(input=>input.value=JSON.stringify(c));}return c};const modal=document.querySelector("[data-cookie-modal]");const options=document.querySelector("[data-cookie-options]");if(!syncConsent()&&modal){modal.classList.add("is-visible");document.body.style.overflow="hidden"}document.querySelectorAll("[data-cookie-open]").forEach(btn=>btn.addEventListener("click",()=>{const c=syncConsent()||{};document.querySelector("[data-cookie-functional]").checked=!!c.functional;document.querySelector("[data-cookie-analytics]").checked=!!c.analytics;document.querySelector("[data-cookie-marketing]").checked=!!c.marketing;options.classList.add("is-visible");modal.classList.add("is-visible");document.body.style.overflow="hidden"}));document.querySelector("[data-cookie-custom]")?.addEventListener("click",()=>options.classList.toggle("is-visible"));document.querySelector("[data-cookie-accept]")?.addEventListener("click",async()=>{await writeConsent({functional:true,analytics:true,marketing:true,source:"accept-all"});modal.classList.remove("is-visible");document.body.style.overflow=""});document.querySelector("[data-cookie-save]")?.addEventListener("click",async()=>{await writeConsent({functional:document.querySelector("[data-cookie-functional]").checked,analytics:document.querySelector("[data-cookie-analytics]").checked,marketing:document.querySelector("[data-cookie-marketing]").checked,source:"custom"});modal.classList.remove("is-visible");document.body.style.overflow=""});document.querySelectorAll(".mero-calculator").forEach(box=>{const s=JSON.parse(box.dataset.settings||"{}");const form=box.querySelector("form");const out=box.querySelector(".mero-result");const calc=()=>{const area=Number(form.area.value||0);const scope=form.scope.value;let total=area*Number((s.base_prices||{})[scope]||0);total*=Number((s.roof_multipliers||{})[form.roof.value]||1);total*=Number((s.garage_multipliers||{})[form.garage.value]||1);const p=Number(s.range_percent||15)/100;out.textContent=area?`Orientacyjnie: ${money(total*(1-p))} - ${money(total*(1+p))}`:"";form.dataset.result=JSON.stringify({area,scope,total_min:Math.round(total*(1-p)),total_max:Math.round(total*(1+p))});};form.addEventListener("input",calc);calc();});document.querySelectorAll(".mero-lead-form").forEach(form=>form.addEventListener("submit",async e=>{e.preventDefault();syncConsent();const data=Object.fromEntries(new FormData(form).entries());data.type=form.dataset.type||data.type;data.result=form.dataset.result?JSON.parse(form.dataset.result):null;const status=form.querySelector(".mero-form-status");status.textContent="Wysylam...";try{const res=await fetch("/api/mero/lead",{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify(data)});const json=await res.json();if(json.ok){location.href=json.redirect||"/dziekujemy";return;}status.textContent=json.message||"Sprawdz pola formularza.";}catch(err){status.textContent="Nie udalo sie wyslac formularza. Sprobuj ponownie albo zadzwon do MERO.";}}));</script></body></html>';
    };

    return [
        'routes' => [
            '/api/lead.php' => $submitLead,
            '/api/mero/lead' => $submitLead,
            '/api/mero/consent' => $submitConsent,
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
                    $body = '<section class="mero-hero"><h1>' . $h($row['title']) . '</h1><p>' . $h($row['excerpt']) . '</p></section><main id="main-content" class="mero-main"><article class="mero-content">' . $content . '</article></main>';
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
                    $query = trim((string) ($_GET['szukaj'] ?? ''));
                    $items = '';
                    foreach ($articles($query) as $articleRow) {
                        $items .= '<article><h2><a href="/poradnik/' . $h($articleRow['slug']) . '">' . $h($articleRow['title']) . '</a></h2><p>' . $h($articleRow['excerpt']) . '</p></article>';
                    }
                    $search = '<form class="search-box" method="get" action="/poradnik"><input name="szukaj" value="' . $h($query) . '" placeholder="Szukaj w poradniku"><button>Szukaj</button></form>';
                    $content .= $search . '<section class="mero-article-list">' . ($items ?: '<p>Brak wpisow dla podanej frazy.</p>') . '</section>';
                }

                $body = '<section class="mero-hero"><h1>' . $h($row['title']) . '</h1></section><main id="main-content" class="mero-main"><article class="mero-content">' . $content . '</article></main>';
                $render((string) $row['title'], $body);
                return true;
            },
        ],
    ];
};
