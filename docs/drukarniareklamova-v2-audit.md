# Drukarnia Reklamova 2.0 — audyt przedwdrożeniowy

Data audytu: 2026-09-11

Zakres: CMS Reklamova, produkcyjny WordPress/WooCommerce drukarniareklamova.pl, integracje i dane migracyjne

Tryb: odczytowy. W trakcie audytu nie zmieniono konfiguracji, bazy, kodu ani treści produkcji.

## 1. Wniosek wykonawczy

Migracja jest zasadna, ale obecny moduł `catalog` CMS Reklamova nie jest silnikiem sprzedażowym. To katalog ofertowy bez cen, wariantów, stanów, koszyka, zamówień, klientów, płatności i dostaw. Rozbudowę należy wykonać jako zestaw generycznych modułów commerce, bez warunków zależnych od domeny Drukarni.

Sklep ma niewielki, możliwy do kontrolowanego przeniesienia zbiór danych: 62 opublikowane produkty, 297 wariantów, 14 kategorii i 20 zamówień. Krytyczne obszary to model wariantów i cen, natywne ING Pay, dostawy/punkty odbioru, bezpieczne pliki do druku, idempotentny importer oraz zachowanie 84 aktywnych adresów URL.

Przed implementacją commerce trzeba również utwardzić wspólne elementy CMS: sesje, reset hasła, ochronę logowania, uploady, transport maili, obserwowalność i testy automatyczne.

## 2. Źródła i metodologia

Audyt oparto na:

- kodzie prywatnego repozytorium CMS Reklamova, commit `8f0eb55`, gałąź audytowa `codex/drukarnia-reklamova-v2-audit`;
- bezpośrednim, tylko do odczytu, dostępie SSH do obu instalacji;
- zapytaniach do WordPress/WooCommerce przez WP-CLI i bazę danych;
- analizie aktywnego motywu oraz kodu własnych i integracyjnych pluginów;
- kontrolnym odczycie publicznych odpowiedzi HTTP;
- aktualnej dokumentacji ING Pay.

Nie pobierano ani nie zapisywano w raporcie sekretów, haseł, tokenów, pełnych danych osobowych ani danych płatniczych. Publiczny scraping służył wyłącznie jako kontrola.

## 3. CMS Reklamova — stan obecny

### 3.1 Kod i uruchomienie

Repozytorium zawiera CMS w wersji 0.8.0. Produkcyjny panel centralny działa na wersji 0.7.5, co oznacza drift między kodem źródłowym a wdrożeniem. Środowisko produkcyjne udostępnia PHP 8.3.33 oraz MariaDB 10.11.19. Composer wymaga PHP 8.3 i podstawowych rozszerzeń, bez zewnętrznego frameworka PHP. Projekt nie ma zależności NPM ani automatycznego zestawu testów.

Konfiguracja klientów, baza i uploady są oddzielone od core. Pliki konfiguracyjne zawierające sekrety są poza repozytorium; na produkcji mają uprawnienia 0600. Mechanizm aktualizacji korzysta z podpisanych paczek ZIP.

### 3.2 Architektura

- Routing jest lekki i oparty na dokładnych ścieżkach oraz fallbackach modułów.
- Moduły są wykrywane w `app/modules/*` i `app/modules/custom/*`; mogą rejestrować trasy publiczne, administracyjne i uprawnienia.
- Motywy korzystają z `theme.json`; rozszerzenia klienta są chronione przed nadpisaniem.
- Dostęp do DB używa PDO i prepared statements.
- Migracje są transakcyjne, wersjonowane i rejestrowane osobno dla core oraz modułów.
- Istnieją moduły stron, mediów, ustawień, użytkowników, prywatności, aktualizacji i katalogu.
- Produkcyjna baza centralnego CMS ma migrację menedżera instalacji oznaczoną jako wykonaną, ale nie ma oczekiwanej tabeli `cms_installations`. To wymaga naprawy spójności przed poleganiem na centralnym deployment managerze.

### 3.3 Obecny katalog

`catalog_products` przechowuje dane ofertowe: kategorię, nazwę, slug/full path, SKU, markę, model, opisy, specyfikację JSON, galerię, dokumenty, status i meta/schema. Brakuje:

- cen, VAT i historii cen;
- wariantów i uporządkowanych atrybutów/opcji;
- stanów magazynowych i rezerwacji;
- wielu kategorii na produkt;
- koszyka i checkoutu;
- zamówień, pozycji, klientów i adresów;
- płatności, dostaw, kuponów;
- plików związanych z pozycją zamówienia.

SKU nie ma ograniczenia unikalności, a relacje katalogu nie są chronione pełnym zestawem FK. Obecnego schematu nie należy rozszerzać jednym dużym JSON-em ani dopisywać logiki sklepu bezpośrednio do kontrolerów katalogu.

### 3.4 Panel, media, cache, mail i logi

- Panel ma role/uprawnienia, CSRF dla formularzy POST oraz dziennik aktywności.
- Media są zapisywane w publicznym katalogu bez ścisłej allowlisty rozszerzeń i bez globalnego limitu rozmiaru przed zapisem.
- Cache dotyczy głównie aktualizacji; brak cache stron/katalogu, spójnych kluczy i invalidacji po zmianie produktu.
- Mail korzysta z prostego `mail()`, bez transportu SMTP, kolejki, retry, szablonów HTML i korelacji ze zdarzeniem domenowym.
- Logi nie mają wspólnego identyfikatora zamówienia/płatności/klienta ani jawnej polityki maskowania danych commerce.
- Brakuje `.env.example`, komend commerce CLI, `CHANGELOG.md` i dokumentacji commerce.
- Brakuje testów jednostkowych, integracyjnych i E2E.

### 3.5 Security review CMS

Dobre podstawy: `password_hash()`/`password_verify()`, PDO prepared statements, CSRF w panelu, HSTS, `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy` i `Permissions-Policy`.

Elementy wymagające poprawy przed uruchomieniem sklepu:

1. Po zalogowaniu nie jest regenerowany identyfikator sesji.
2. Produkcyjny cookie sesji ma `Secure`, ale nie ma jawnych `HttpOnly` i `SameSite`.
3. Logowanie nie ma odpornego rate limitingu. Limit resetu hasła jest oparty o bieżącą sesję i łatwy do obejścia.
4. Reset hasła wysyła tymczasowe hasło zamiast jednorazowego, wygasającego tokenu.
5. Upload mediów nie ma wymaganej allowlisty MIME/rozszerzeń i limitu przed przeniesieniem do publicznego storage.
6. `PermissionManager::isInternalUser()` uznaje użytkownika za wewnętrznego również na podstawie hosta centralnego CMS. Uprawnienie nie może wynikać z domeny.
7. Brakuje CSP.
8. Log wyjątków może zawierać surowy komunikat PDO; nowe logi commerce muszą maskować PII i sekrety.
9. Commerce musi dodać ochronę IDOR dla zamówień, dokumentów i plików oraz twardą autoryzację webhooków.

## 4. Drukarnia Reklamova — stan obecny

### 4.1 Platforma

- WordPress 7.1; pliki core przechodzą kontrolę checksum.
- WooCommerce 10.9.1.
- Aktywny własny motyw `multi` 3.0 z około 34 override'ami szablonów WooCommerce.
- 23 271 plików, około 462 MB.
- Skan wysokiego sygnału nie wykazał charakterystycznego łańcucha `openssl_decrypt + gzuncompress + eval`.
- Brak plików/katalogów world-writable.
- HPOS WooCommerce jest wyłączony; zamówienia są nadal w `wp_posts`/`wp_postmeta`.

### 4.2 Aktywne komponenty

Aktywne: WooCommerce, ING Pay/imoje, Apaczka, W3 Total Cache, Autoptimize, WP Mail SMTP, Contact Form 7, SVG Support, custom product tabs, `multi-builder`, `multi-builder-pro`, checkbox regulaminu i moduł treści pod koszykiem. Kilka pluginów ma dostępne aktualizacje; Apaczka deklaruje zgodność tylko do WordPress 6.9, podczas gdy sklep działa na 7.1.

Nieaktywne: Advanced Product Fields, `order-plugin` i `ukryj-platnosci`. W bazie i plikach są także pozostałości po nieaktywnych narzędziach, m.in. Elementor, Google Listings, RevSlider, TrustIndex, WPForms i YITH.

### 4.3 Katalog i ceny

| Obszar | Stan |
|---|---:|
| Produkty opublikowane | 62 |
| Produkty robocze | 1 |
| Produkty wariantowe | 47 |
| Produkty proste | 16, łącznie z roboczym |
| Warianty opublikowane | 297 |
| Kategorie | 14 |
| Globalne atrybuty | 5 |
| Rekordy produkt/wariant ze SKU | 46 z 360 |
| Produkty bez ceny | 0 |
| Produkty bez zdjęcia głównego | 0 |
| Produkty bez krótkiego opisu | 16 |
| Produkty z galerią | 32 |
| Zakres ceny bieżącej | 2,00–12 177,00 PLN |
| Ceny promocyjne | 0 |

Atrybuty globalne: format plakatu, nadruk, nakład, rozmiar banera i szerokość taśmy. Wszystkie rekordy produktów/wariantów raportują `instock`. Nie wykryto duplikatów istniejących SKU.

36 produktów używa danych własnych zakładek, a w bazie istnieją dwa obiekty pól WAPF mimo nieaktywnej wtyczki. Importer musi rozpoznać, które z tych treści są biznesowo istotne, zamiast kopiować serializowane dane pluginów.

### 4.4 Kategorie

Największe zbiory to Plakaty (25), Gotowe banery (20) i Materiały reklamowe (15). Kategorie Reklama i Ulotki są puste. Struktura zawiera zagnieżdżone adresy, np. `/kategoria/plakaty/natura/`.

### 4.5 Zamówienia i klienci

- 20 zamówień: 14 zakończonych, 5 anulowanych, 1 w realizacji.
- 16 zamówień gościnnych i 4 powiązane z kontem.
- Zakres dat: 2024-01-21 do 2026-09-10.
- 3 zarejestrowanych klientów; 14 rekordów klientów gościnnych.
- 1 kupon procentowy.
- Brak błędnych zadań Action Scheduler; 16 oczekuje, 1230 zakończono.

Liczba danych jest mała, dlatego warto przenieść historię zamówień i klientów, ale konta powinny otrzymać bezpieczny reset hasła. Nie należy przenosić hashy WordPress do mechanizmu obniżającego bezpieczeństwo CMS.

### 4.6 Podatki i checkout

- Waluta PLN, ceny z podatkiem, 2 miejsca dziesiętne.
- Polska stawka VAT 23%, także dla wysyłki.
- Zakupy gościnne i tworzenie konta przy checkout są włączone.
- Kupony i zarządzanie stanem są włączone.
- Jednostki: kg i cm.
- Rezerwacja zapasu: 60 minut.

### 4.7 Płatności

Aktywne są metody ING Pay/imoje: paywall, BLIK, płatność odroczona, karty i PBL, wszystkie w trybie produkcyjnym. Włączone są też przelew bankowy i pobranie. Dane dostępowe są skonfigurowane w ustawieniach gateway WooCommerce; nie zostały odczytane ani zapisane.

Nowa integracja ma korzystać z `PaymentProviderInterface` i `IngPayProvider`, oddzielnych konfiguracji sandbox/production i kwot w najmniejszych jednostkach waluty. Powrót klienta nie może ustawiać opłacenia. Decyzję o płatności podejmuje dopiero zweryfikowana notyfikacja lub bezpieczne potwierdzenie backendowe. Obsługa musi:

- zweryfikować `X-Imoje-Signature` na surowym body;
- porównać merchant/service, transaction/order ID, kwotę i walutę;
- przechować unikalny identyfikator zdarzenia/transakcji;
- bezpiecznie ignorować duplikaty i niedozwolone przejścia statusu;
- rejestrować wynik bez sekretów i pełnych danych klienta;
- obsłużyć retry, timeout, anulowanie, odrzucenie, ponowienie płatności i refund status.

### 4.8 Dostawa i Apaczka

Strefa Polska ma:

- darmową dostawę od 49 PLN;
- DPD Pickup 12,19 PLN i pobranie 14,63 PLN;
- InPost Paczkomat 12,19 PLN i pobranie 16,26 PLN;
- InPost Kurier 13,00 PLN i pobranie 15,45 PLN.

Nie ma jawnej metody zapasowej poza Polską. Apaczka ma skonfigurowane app ID i secret. Metadane Apaczki są obecne dla wszystkich 20 zamówień; dane punktu odbioru występują w 6 zamówieniach. Nowy moduł dostaw musi przechować wybór punktu jako osobną, zwalidowaną strukturę, a nie niejawny meta-field pluginu.

### 4.9 Pliki do druku

Katalogi `uploads/orders` i `product_uploads` są puste; `woocommerce_uploads` zawiera dwa pliki. Nieaktywny `order-plugin` pokazuje dawną intencję kalkulatora i uploadu, ale jego kod nie nadaje się do migracji: brak nonce, walidacja głównie po rozszerzeniu, zaufanie do MIME klienta, brak limitu rozmiaru i publiczne pliki w `wp-content/uploads/orders`.

Należy wdrożyć nowe `OrderFiles` od zera: prywatny storage poza webrootem, losowy klucz, allowlista rozszerzeń i wykrytego MIME, limit rozmiaru/liczby, brak wykonania, kontrolowany download, autoryzacja klient/admin, audit trail i opcjonalne skanowanie antywirusowe.

### 4.10 Mail, SEO, analityka, cron i webhooki

- WooCommerce używa standardowych maili transakcyjnych, a transport wspiera WP Mail SMTP.
- Nie znaleziono aktywnego pluginu SEO ani meta Yoast/RankMath/AIOSEO.
- WordPress generuje treść core sitemap, ale `/wp-sitemap.xml` i child sitemap odpowiadają HTTP 404. To bieżący defekt SEO.
- `robots.txt` odpowiada 200 i wskazuje niedziałającą sitemapę.
- Nie wykryto aktywnego GA4/GTM ani innego markera trackingu w kodzie i publicznym HTML. Przed cutover trzeba potwierdzić to także w panelach usług.
- Na homepage renderuje się surowy shortcode TrustIndex, co wskazuje na osieroconą treść po nieaktywnym pluginie.
- Nie ma rekordów WooCommerce webhooks ani REST API keys. ING Pay używa własnego callbacku WC API.
- Cron systemowy zawiera zadania innych serwisów, ale nie dedykowane zadanie Drukarni. WooCommerce opiera zadania na WP-Cron/Action Scheduler.
- Nie wykryto aktywnej integracji fakturowej. Pole NIP i przyszłe dokumenty należy zaprojektować w nowym checkout, a wybór dostawcy faktur pozostaje decyzją biznesową.

## 5. Funkcje do migracji

### MUST HAVE

- pełny model produktów prostych, wariantowych i konfigurowalnych;
- wiele kategorii, atrybuty, opcje, media, ceny, VAT, dostępność i stany;
- trwały koszyk oraz serwerowo wyliczany checkout gościnny/kontowy;
- zamówienia z osobnym `payment_status` i `order_status`;
- klienci, adresy, konto, historia, reset hasła i bezpieczna autoryzacja zasobów;
- ING Pay jako natywny provider, przelew i pobranie;
- dostawy DPD/InPost, warianty COD i punkty odbioru;
- bezpieczne `OrderFiles`;
- maile transakcyjne z retry i korelacją;
- idempotentny importer DB/API z `dry-run`, external ID i raportem zgodności;
- zachowanie URL, canonical, sitemap, robots, OpenGraph i schema.org;
- panel produktów, zamówień, płatności, dostaw, klientów i plików;
- testy finansów, checkoutu, webhooków, uprawnień, uploadów, importera i E2E;
- staging noindex oraz odwracalny cutover.

### SHOULD HAVE

- kupony/proste promocje;
- historia zmian ceny/statusu i audit trail;
- wyszukiwarka, filtry i czytelne listy admin;
- warstwa zdarzeń commerce oraz standardowe eventy GA4 dataLayer;
- cache katalogu/stron z precyzyjną invalidacją;
- responsive images i warianty WebP/AVIF;
- kolejka maili i zadań okresowych;
- import historii zamówień i klientów;
- retry płatności, pobieranie dokumentów i mechanizm statusu wysyłki;
- benchmarki i budżety wydajności.

### NICE TO HAVE

- cenniki B2B;
- rozbudowane kalkulatory m²/nakład/materiał/wykończenie;
- integracja fakturowa po wyborze dostawcy;
- automatyczne etykiety i tracking Apaczka;
- zaawansowane reguły promocji;
- skanowanie antywirusowe plików jako usługa;
- panel jakości/preflight pliku do druku.

### Stare lub zbędne

- kod i schemat danych nieaktywnego `order-plugin`;
- override'y i shortcody właściwe dla WooCommerce;
- `multi-builder`, theme-specific widgety i surowe shortcode TrustIndex;
- pozostałości Elementor/RevSlider/WPForms/YITH/Google Listings;
- kopiowanie ustawień cache WordPress;
- serializowane meta pluginów, jeśli nie reprezentują danych biznesowych;
- utrzymywanie adresów pustych kategorii wyłącznie z przyczyn technicznych.

## 6. Macierz integracji

| Integracja | Cel i stan obecny | Konfiguracja obecna | Migracja | Docelowo w CMS |
|---|---|---|---|---|
| ING Pay/imoje | Płatności paywall, BLIK, karty, PBL, pay later; production | Woo gateway options, sekrety obecne | Tak, obowiązkowo | `PaymentProviderInterface` + `IngPayProvider`, ENV, sandbox/prod, podpisane i idempotentne notyfikacje |
| Przelew | Płatność manualna | Woo BACS | Tak | Wbudowany provider offline + dane rachunku w bezpiecznej konfiguracji |
| Pobranie | Płatność przy dostawie | Woo COD | Tak | Provider offline powiązany z dozwolonymi taryfami COD |
| Apaczka | Nadania, etykiety, tracking; dane na 20 zamówieniach | WordPress options, app ID/secret | Etap 2 | Adapter fulfillment; ENV, webhook/polling, audyt i retry |
| DPD/InPost | Kurier, pickup, paczkomat, COD | Woo shipping zones/instances | Tak | `ShippingMethodInterface`, taryfy, ograniczenia i osobna encja pickup point |
| WP Mail SMTP | Transport maili | Plugin WordPress | Nie 1:1 | Transport SMTP/API poza repo, kolejka, retry, status wysyłki |
| W3TC/Autoptimize | Cache/minifikacja | Pluginy WordPress | Nie | Cache HTTP/aplikacyjny, invalidacja domenowa, asset pipeline |
| Custom tabs | Treści dodatkowe 36 produktów | post meta pluginu | Treść warunkowo | Uporządkowane sekcje treści produktu |
| WAPF | Dwa residual field groups, plugin nieaktywny | custom posts/meta | Tylko po potwierdzeniu użycia | Generyczne definicje opcji produktu/pricing rules |
| TrustIndex | Opinie; shortcode wyświetla się jako tekst | Osierocona treść | Nie bez decyzji | Jawny komponent opinii lub usunąć shortcode |
| GA4/GTM | Nie wykryto aktywnej implementacji | Brak potwierdzonej konfiguracji | Nowa implementacja | Event bus commerce + adapter dataLayer, consent-aware |
| SEO | WP core, bez aktywnego pluginu; sitemap 404 | WordPress settings | Tak | SEO module, działająca sitemap, canonical, OG, JSON-LD |
| Fakturowanie | Nie wykryto integracji | Brak | Decyzja później | Stabilny interfejs dokumentów/faktur |
| Woo REST/Webhooks | Brak kluczy i rekordów webhooków | Brak | Nie | Import bezpośrednio z DB lub czasowy scoped credential |

## 7. Docelowa architektura commerce

Rekomendowany podział zgodny z aktualnym systemem modułów:

- `commerce`: wspólne typy Money, Tax, identyfikatory, zdarzenia i transakcje;
- `catalog`: produkty, kategorie, warianty, atrybuty, opcje i media;
- `pricing`: price lists, VAT, promocje, kupony oraz rozszerzalne reguły kalkulatorów;
- `cart`: koszyk anonimowy/kontowy, wersjonowanie i snapshot ceny;
- `checkout`: orkiestracja danych, zgód, dostawy, płatności i atomowego utworzenia zamówienia;
- `orders`: zamówienie, pozycje, snapshot produktu/ceny/podatku/adresu oraz historia statusów;
- `customers`: konto, adresy, reset hasła i ownership policies;
- `payments`: provider interface, attempts, transactions, callbacks i refund state;
- `shipping`: metody, taryfy, pickup points, shipments i tracking;
- `order-files`: wymagania pliku, prywatny storage, autoryzowany download i audit;
- `notifications`: szablony, outbox/queue, retry i transport;
- `seo`: metadata, canonical, sitemap, redirects i schema;
- `analytics`: zdarzenia domenowe i adaptery, bez zależności domeny od Google;
- `commerce-admin`: ergonomiczne ekrany i uprawnienia.

Moduły muszą być opt-in. Instalacje niehandlowe nie powinny uruchamiać migracji, tras ani UI commerce. Specyfika Drukarni trafia do konfiguracji klienta i ewentualnego modułu custom, nigdy do core przez sprawdzanie domeny.

### 7.1 Zasady modelu danych

- Kwoty przechowujemy jako integer minor units albo `DECIMAL` o jednoznacznej skali; nigdy `FLOAT`.
- Każda pozycja zamówienia przechowuje immutable snapshot nazwy, SKU, opcji, ceny, stawki i kwoty VAT.
- Zmiana produktu nie modyfikuje historycznych zamówień.
- Tabele mają `created_at`, `updated_at`, uzasadnione FK, indeksy i unique constraints.
- SKU może być nullable, ale niepuste SKU musi być unikalne w obrębie sklepu.
- Importowane encje mają unikalne `source_system + source_type + external_id`.
- Płatności i webhooki mają klucze idempotencji oraz dozwolone przejścia stanu.
- Status zamówienia, płatności, fulfillmentu i plików są rozdzielone.

## 8. Strategia migracji

1. Utworzyć staging z osobną bazą i prywatnym storage; wymusić auth oraz `noindex,nofollow`.
2. Zbudować reader Woo DB jako adapter źródła i stabilne DTO, bez zależności domeny commerce od WordPress.
3. Dodać `dry-run`, walidację oraz transakcyjne upsert po external ID.
4. Importować kategorie/atrybuty, produkty, warianty, media i zależności w tej kolejności.
5. Zachować slugi i adresy; wykrywać konflikt slug/SKU przed zapisem.
6. Importować klientów/adresy i 20 zamówień z historycznymi snapshotami. Konta oznaczyć jako wymagające resetu hasła.
7. Importować kupon, dane punktów odbioru i istotne dane fulfillment; nie importować sekretów.
8. Wygenerować raport źródło → cel dla liczników, cen, plików, braków i konfliktów.
9. Przed cutover wykonać pełny import, testy, a następnie przy krótkim oknie zapisu finalny delta import.
10. Zachować WordPress w trybie read-only jako rollback do czasu formalnego zamknięcia migracji.

## 9. SEO i adresy

Plik `docs/seo-migration-map.csv` zawiera 86 wykrytych publicznych adresów:

- 84 proponowane do zachowania bez zmiany i odpowiedzi 200;
- 2 puste kategorie proponowane do usunięcia odpowiedzią 410 po akceptacji biznesowej;
- 0 planowanych przekierowań na homepage.

Jeśli podczas implementacji adres musi się zmienić, mapa ma wskazać jeden konkretny cel 301. Przed cutover trzeba ponowić crawl z canonical, statusami, title, description, H1, alt i porównać sitemapę. Staging musi być technicznie zablokowany przed indeksacją.

## 10. Wydajność i obserwowalność

Kontrolny request wykonany z hosta produkcyjnego zwracał TTFB około 0,09–0,10 s dla homepage/shop, ale HTML miał około 126–135 KB, a koszyk około 366 KB. Zewnętrzny pomiar był blokowany przez warstwę antybot i nie jest wiarygodnym benchmarkiem użytkownika.

Nowy baseline musi być wykonywany powtarzalnym skryptem dla starego i stagingu, z zimnym/ciepłym cache. Raportować TTFB, LCP, CLS, INP, liczbę requestów i transfer. Dodać query budget/N+1 checks dla listingu, produktu, koszyka i admina.

Logi commerce powinny być strukturalne i wyszukiwalne po `order_id`, `payment_id`, `customer_id`, `request_id`, bez pełnych danych osobowych, surowych tokenów i treści plików. Krytyczne procesy wymagają outbox/retry oraz alertu po wyczerpaniu prób.

## 11. Plan etapów i bramki jakości

### Etap A — fundament i hardening

- sesje/cookies, auth, reset hasła, rate limiting;
- prywatny storage i bezpieczny upload;
- test harness, konfiguracja środowiska, `.env.example`;
- migracje i wspólne typy commerce.

Bramka: regresja obecnych funkcji CMS, testy bezpieczeństwa i instalacja modułów opt-in.

### Etap B — katalog, ceny i importer

- nowy schemat katalogu/variant/options;
- pricing/VAT/stock;
- idempotentny importer z dry-run i raportem;
- SEO metadata oraz zachowanie URL.

Bramka: pełna zgodność 62 produktów, 297 wariantów, 14 kategorii i cen.

### Etap C — koszyk, checkout, orders i customers

- koszyk, snapshoty, kupony, dostawy, konto, statusy;
- panel operacyjny;
- OrderFiles i notifications.

Bramka: testy jednostkowe/integracyjne oraz pełny checkout bez płatności online.

### Etap D — ING Pay i fulfillment

- provider, sandbox, callbacks, retry;
- przelew/COD;
- DPD/InPost/pickup; adapter Apaczka później lub równolegle.

Bramka: E2E sandbox oraz test duplikatu, błędnej kwoty, anulowania i ponowienia.

### Etap E — frontend, analytics i performance

- SSR homepage/listing/product/cart/checkout/account;
- event bus/dataLayer i consent;
- responsive media, cache, benchmarki i dostępność.

Bramka: mobile/desktop, budgets, WCAG checks i brak podwójnego `purchase`.

### Etap F — cutover

- backup plików/DB, pełny import, finalna delta;
- płatności, maile, SEO/redirecty, monitoring;
- przełączenie domeny i udokumentowany rollback.

Bramka: podpisana checklista produkcyjna; WordPress pozostaje read-only.

## 12. Otwarte decyzje

Nie blokują rozpoczęcia fundamentu, ale wymagają rozstrzygnięcia przed odpowiednim etapem:

- docelowy dostawca faktur i wymagane dokumenty;
- czy integrować Apaczkę od pierwszego cutover, czy uruchomić ręczną obsługę wysyłek na krótki okres;
- biznesowe znaczenie dwóch grup WAPF oraz custom tabs;
- los pustych kategorii Reklama i Ulotki (propozycja: 410);
- wymagane formaty, liczby i limity plików dla konkretnych produktów;
- docelowe statusy produkcyjne Drukarni i SLA;
- identyfikatory GA4/GTM, jeśli mają być użyte;
- polityka wysyłki poza Polską;
- finalny projekt wizualny i treści 2.0.

## 13. Kryterium przejścia do implementacji

Audyt potwierdza, że implementację można rozpocząć bez zmiany produkcji. Pierwszy commit kodowy powinien objąć wyłącznie wspólny test harness, hardening i instalowalny fundament commerce. Import danych, ING Pay i cutover muszą pozostać oddzielnymi etapami/commitami z własnymi testami oraz raportami.

## 14. Referencje zewnętrzne

- ING Pay — centrum pomocy: https://www.ing.pl/bramka-platnicza-ing-pay/centrum-pomocy
- ING Pay API: https://bump.sh/pgw/doc/imoje-api/
- ING Pay — notyfikacje i weryfikacja podpisu: https://bump.sh/pgw/doc/imoje-api-en/topic/topic-notifications
