# Rozdzielenie CMS, MERO i PowerTech

Data: 2026-08-12

Status: **ZAKOŃCZONE I ZWERYFIKOWANE**

## Docelowy model

Repozytorium `reklamova/reklamova_cms` jest źródłem wspólnego rdzenia CMS.
Każdy klient nadal ma osobną instalację, bazę, konfigurację, motyw, uploady i
backupy.

Warstwy klientów są rozdzielone następująco:

- MERO: `app/modules/custom/mero`,
- PowerTech: `app/modules/custom/powertech` oraz `app/themes/powertech`,
- wspólny core: wyłącznie ścieżki z allowlisty `reklamova.json`.

Moduły i motywy klientów są chronione przez updater. Nie wolno przenosić ich
logiki do `app/core` ani do oficjalnych modułów wspólnych.

## Zweryfikowane instalacje

| Klient | Środowisko | Wersja | Zgodność core z GitHub |
| --- | --- | --- | --- |
| PowerTech | produkcja | 0.8.0 | 108/108 plików |
| PowerTech | staging | 0.8.0, kanał aktualizacji `rc` | 108/108 plików |
| MERO | produkcja | 0.8.0 | 108/108 plików |
| MERO | staging | 0.8.0, kanał aktualizacji `rc` | 108/108 plików |

Porównanie obejmowało wszystkie śledzone pliki wspólnego core z aktualnego
`main`, bez protected paths. Brakowało 0 plików i 0 plików miało inny SHA-256.

## PowerTech

Z instalacji produkcyjnej i stagingowej usunięto wszystkie aktywne ślady MERO:

- katalog `app/modules/custom/mero` nie istnieje,
- `mero` nie występuje w konfiguracji modułów,
- rekord `mero` został usunięty z `cms_modules`,
- stare pliki lokalnego modułu SEO przeniesiono do prywatnych archiwów; w
  aktywnym drzewie pozostał tylko oficjalny `app/modules/seo/module.json`.

Historyczna logika PowerTech była wpisana bezpośrednio do:

- `app/core/Http/Application.php`,
- `app/core/Updates/Updater.php`,
- `app/modules/catalog/public.php`.

Logikę layoutu, katalogu, wyszukiwarki i formularza produktowego przeniesiono do
`app/modules/custom/powertech`. Oryginalne CSS, JavaScript i faviconę zapisano w
`app/themes/powertech/assets`. Trzy nadpisane pliki core przywrócono dokładnie do
stanu z GitHuba; usunięto też lokalną blokadę aktualizatora chroniącą drift.

PowerTech ma aktywne moduły `catalog`, `leads`, `privacy` i `powertech`.

Przy wdrożeniu warstwy PowerTech należy zsynchronizować assety motywu do
faktycznie serwowanego katalogu:

```text
app/themes/powertech/assets/powertech.css -> assets/css/powertech.css
app/themes/powertech/assets/powertech.js  -> assets/js/powertech.js
app/themes/powertech/assets/favicon.svg   -> favicon.svg
app/themes/powertech/assets/reklamova-logo.svg -> assets/img/reklamova-logo.svg
```

Produkcja PowerTech ma historycznie spłaszczony document root, dlatego powyższe
mapowanie jest częścią patcha klienta, nie paczki core.

Od 2026-08-12 produkcyjny CMS działa pod `https://powertechsc.pl`, a domena
`nowastrona.powertechsc.pl` przekierowuje do domeny kanonicznej. Zainfekowany
WordPress i historyczny katalog `_old` zostały przeniesione poza `public_html`
do prywatnej kwarantanny. Szczegóły przełączenia, walidacji i rollbacku opisuje
raport [Przełączenie PowerTech na domenę produkcyjną](powertech-production-cutover-2026-08-12.md).

## MERO

Moduł `app/modules/custom/mero` pozostał wyłącznie na instalacjach MERO. Jego
zatwierdzone pliki produkcyjne pozostały bez zmian.

Na produkcji i stagingu wyłączono puste, nieużywane moduły core:

- `business`,
- `catalog`,
- `landing`,
- `leads`,
- `trust`.

Nie usunięto ich tabel ani danych. Aktywne funkcje MERO zapewniają moduły
`mero`, `knowledge` i `privacy`. Nazwa `leads` występująca wewnątrz modułu MERO
oznacza własne zapytania modułu custom, a nie wyłączony moduł core `leads`.

Z aktywnych drzew przeniesiono:

- kopie `*.before-*`,
- stare pliki lokalnego SEO,
- jednorazowe skrypty migracji, metryk, aktualizacji i tymczasowego admina.

Z katalogu `.tmp` usunięto stare buildy i pliki diagnostyczne. Wszystkie pliki
sesji `sess_*` zostały zachowane.

## Backupy i rollback

PowerTech:

- kwarantanna MERO:
  `/home/platne/serwer38522/backups/powertech-mero-quarantine-20260812_095054`,
- rozdzielenie produkcyjnego core i warstwy klienta:
  `/home/platne/serwer38522/backups/powertech-core-reconcile-20260812_101347`,
- wdrożenie i test stagingu:
  `/home/platne/serwer38522/backups/powertech-client-staging-20260812_100931`,
- przełączenie domeny produkcyjnej i kwarantanna starego WordPressa:
  `/home/platne/serwer38522/backups/powertech-cutover-20260812_110931`.

MERO:

- prywatne archiwum operacji:
  `/home/klient.dhosting.pl/merostarzyk/reklamova-archives/mero-cleanup-20260812_102128`,
- świeży backup produkcji: `bkp_20260812_102128`,
- świeży backup stagingu: `bkp_20260812_102131`.

Archiwa zawierają manifesty i SHA-256. Nie zawierają poświadczeń w
repozytorium Git.

## Walidacja końcowa

PowerTech:

- strony: 21,
- media: 1438,
- produkty: 104,
- kategorie: 69,
- front, O nas, Oferta, Kontakt, Pliki do pobrania, panel, wyszukiwarka i 404:
  zaliczone,
- Privacy Center: pojedyncza integracja,
- błędy PHP Fatal/Uncaught w testach: brak.

MERO:

- strony: 28,
- media: 31,
- wpisy Poradnika: 6,
- zgody historyczne MERO: 137,
- leady MERO: 0,
- front, O firmie, Kontakt, Poradnik, Kalkulator, panel i 404: zaliczone,
- formularz kontaktowy, upload i Privacy Center: zaliczone,
- stagingi: HTTP 401, Basic Auth i `X-Robots-Tag: noindex`.

## Świadomie zachowane ograniczenia

Historyczne katalogi `app/storage/update-staging` MERO zawierają pliki należące
do hostowego użytkownika `nobody:nobody`. dhosting odrzuca ich usunięcie i zmianę
nazwy z konta SSH właściciela. Katalogi:

- nie są dostępne publicznie,
- mają zweryfikowane archiwa TAR.GZ,
- mają zapisany manifest właścicieli, uprawnień i ścieżek,
- wymagają zgłoszenia do supportu dhosting, jeśli mają zostać fizycznie usunięte.

W logu MERO obserwowano sporadyczne, historyczne błędy rozwiązywania nazwy hosta
bazy. Bieżące połączenie działa. Nie zastępuj nazwy hosta adresem IP; w razie
powrotu problemu zgłoś go do dhosting z dokładnym czasem zdarzenia.

## Reguła kolejnych wdrożeń

1. Core wdrażaj wyłącznie z aktualnego `main` albo oznaczonego taga stable.
2. MERO i PowerTech wdrażaj osobnymi patchami protected paths.
3. Najpierw wykonaj backup i test na stagingu klienta.
4. Po wdrożeniu ponownie porównaj allowlistę core z repozytorium.
5. Nie dodawaj wyjątków klienta do `Updater.php` i nie patchuj core na serwerze.
