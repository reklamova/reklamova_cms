# MERO staging Reklamova CMS 0.8.0-rc1

Data testu: 2026-07-28, aktualizacja: 2026-07-29

Status: ZALICZONY TECHNICZNIE I PUBLICZNIE

## Środowisko

- adres: `staging.mero.pl`,
- staging root:
  `/home/klient.dhosting.pl/merostarzyk/staging.mero.pl-rc1`,
- oddzielna baza: 60 tabel, kopia danych MERO,
- PHP CLI: 8.5.6,
- PHP WWW: 8.4.20,
- MariaDB: 10.5.24,
- kanał aktualizacji: `rc`,
- osobna licencja stagingowa,
- Basic Auth, `noindex` i blokada skryptów zewnętrznych,
- wysyłka prawdziwych formularzy nie była wykonywana.

Publiczna domena wskazuje katalog
`staging.mero.pl-rc1/public_html`. Certyfikat SSL jest prawidłowy, Basic Auth
zwraca HTTP 401 bez uwierzytelnienia, a publiczne odpowiedzi zawierają
`X-Robots-Tag: noindex, nofollow, noarchive`. Produkcja `mero.pl` nie była
modyfikowana.

## Backup i wdrożenie

Przed klonowaniem wykonano backup plików i bazy produkcyjnej wraz z manifestem
oraz sumami SHA-256. Core wdrożono wyłącznie z allowlisty. Porównanie
chronionych ścieżek potwierdziło brak zmian w konfiguracji, motywie, custom
modules i uploadach, z wyjątkiem jawnie dozwolonego pliku przykładowych
placementów.

Patch MERO wdrożono osobno zgodnie z
`docs/archive/0.8.0-rc/mero-custom-patch-0.8.0.md`. Jego backup i sumy są niezależne od
core.

## Migracje i lint

- migracje core: 8,
- migracje modułów oficjalnych: business, catalog, knowledge, landing, leads,
  privacy i trust,
- migracja MERO: 1,
- ponowne uruchomienie nie zmieniło liczby migracji,
- PHP lint: 105 plików, 0 błędów.

## Panel

Wszystkie wymagane ekrany zwróciły HTTP 200:

- `/admin`,
- `/admin/pages`,
- `/admin/media`,
- `/admin/business`,
- `/admin/mero/leads`,
- `/admin/mero/articles`,
- `/admin/mero/calculator`,
- `/admin/catalog/products`,
- `/admin/catalog/categories`,
- `/admin/privacy`,
- `/admin/updates`.

`client_admin` otrzymuje HTTP 404 dla `/admin/system`, a
`reklamova_admin` HTTP 200.

Potwierdzono:

- brak `MERO Leady` i osobnego `Leady`,
- jedna pozycja `Zapytania`,
- brak `MERO Poradnik`,
- jedna pozycja `Poradnik`,
- brak `Strona firmowa`,
- widoczną `Stronę główną`,
- SEO Poradnika w akordeonie,
- polskie etykiety Kalkulatora, w tym `zł/m²` i `Widełki`,
- brak surowego JSON zapytania dla klienta,
- payload wyłącznie w `Dane techniczne` dla Reklamova Admin,
- ukrycie Trust przed klientem bez placementu,
- informację `Brak miejsca w motywie` dla Reklamova Admin.

## Front i Privacy Center

HTTP 200 potwierdzono dla:

- strony głównej,
- `/o-firmie`,
- `/budowa-domow`,
- `/poradnik`,
- `/kalkulator-budowy-domu`,
- `/kontakt`,
- `/nasza-oferta`,
- `/api/privacy/settings`.

Testowe zapytanie zapisano bezpośrednio w stagingowej bazie, bez uruchamiania
funkcji wysyłki e-mail. Klient widzi czytelne pola, a administrator Reklamova
może rozwinąć dane techniczne.

RC1 ujawnił, że pełnoekranowy renderer MERO omijał hooki Privacy Center i
renderował stary popup. Poprawka post-RC1:

- wstrzykuje Consent Mode default przed managerem,
- wstrzykuje root i konfigurację Privacy Center,
- usuwa stary modal i klucz `mero_cookie_consent_v1`,
- pozostawia endpoint i tabelę MERO dla kompatybilności,
- używa jednego `consent-manager.js`,
- obsługuje canonical `/ustawienia-prywatnosci` i stary alias.

Po poprawce wszystkie kontrole przeszły, a log serwera zawiera 0 błędów PHP.

## Dane kontrolne

- 28 podstron,
- 31 plików Media,
- 6 wpisów Poradnika,
- 1 testowe zapytanie,
- 1 testowy log zgody,
- katalog MERO jest aktywny, ale nie zawiera produktów ani kategorii.

## Publiczny test końcowy

Po przypisaniu vhosta HTTP 200 potwierdzono publicznie dla:

- strony głównej,
- panelu logowania,
- O firmie,
- Poradnika,
- Kalkulatora budowy,
- Kontaktu,
- Privacy Center API,
- Ustawień prywatności.

Obie role logują się przez publiczny HTTPS. `client_admin` nie widzi menu
technicznego i otrzymuje HTTP 404 dla `/admin/system`.
`reklamova_admin` otrzymuje HTTP 200 dla Modułów strony, Aktualizacji CMS i
Stanu systemu. Test update servera na kanale `rc` zakończył się HTTP 200 bez
błędu licencji.

Pozostaje ręczny test trwałości decyzji cookies w zwykłej przeglądarce.
