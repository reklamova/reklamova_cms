# MERO staging Reklamova CMS 0.8.0-rc1

Data raportu: 2026-07-28

Status: ZABLOKOWANY INFRASTRUKTURALNIE

## Środowisko

Planowany adres: `staging.mero.pl`

DNS kieruje na hosting MERO, ale `/` oraz `/admin/` zwracają 404. Nie
potwierdzono oddzielnego document root, stagingowej bazy ani blokady maili.
Produkcja `mero.pl` nie była modyfikowana.

## Wymagany zakres wdrożenia

Core:

- commit `403534fd0afd1d4261415a0f7446321085f59b03`,
- tylko allowlista core,
- bez `app/modules/custom/**`,
- migracje core,
- kanał `rc`.

Patch MERO:

- backup całego `app/modules/custom/mero`,
- wyłącznie pliki wskazane w
  `docs/releases/mero-custom-patch-0.8.0.md`,
- migracje modułu MERO,
- osobny rollback.

## Kontrole chronionych ścieżek

Przed i po core update należy porównać sumy dla:

- `app/config`,
- `app/themes`,
- `app/modules/custom`,
- `public/uploads`.

Zmiana którejkolwiek z tych ścieżek przez core update oznacza NIEZALICZENIE.
Zmiany patcha MERO porównuje się osobno do jego manifestu.

## Panel klienta

- [ ] `/admin`
- [ ] `/admin/pages`
- [ ] `/admin/pages/edit`
- [ ] `/admin/media`
- [ ] `/admin/business`
- [ ] `/admin/mero/leads`
- [ ] `/admin/mero/articles`
- [ ] `/admin/mero/calculator`
- [ ] `/admin/catalog/products`
- [ ] `/admin/catalog/categories`
- [ ] `/admin/privacy`
- [ ] `/admin/updates`
- [ ] `/admin/system` jako Reklamova Admin

## Architektura informacji i uprawnienia

- [ ] brak pozycji `MERO Leady`,
- [ ] brak osobnej pozycji `Leady`,
- [ ] jest jedna pozycja `Zapytania`,
- [ ] brak pozycji `MERO Poradnik`,
- [ ] jest jedna pozycja `Poradnik`,
- [ ] brak pozycji `Strona firmowa`,
- [ ] jest pozycja `Strona główna`,
- [ ] Trust jest widoczny klientowi tylko z placementem,
- [ ] Reklamova Admin widzi informację o orphaned placement,
- [ ] klient nie widzi surowego JSON zapytań,
- [ ] Reklamova Admin widzi JSON tylko w `Dane techniczne`,
- [ ] Poradnik ma SEO w zamkniętym akordeonie,
- [ ] Kalkulator ma poprawne polskie etykiety,
- [ ] GET Zapytania działa z `view_leads`,
- [ ] zapis Zapytania wymaga `manage_inquiries`.

## Front

- [ ] strona główna,
- [ ] podstrony,
- [ ] poradnik i pojedynczy wpis,
- [ ] kalkulator,
- [ ] formularze bez realnej wysyłki e-mail,
- [ ] testowe zapytanie zapisane w stagingowej bazie,
- [ ] katalog, kategorie i produkty,
- [ ] Privacy Center nie uruchamia trackerów,
- [ ] zgoda cookies nie wraca po przejściu na podstronę,
- [ ] brak błędów PHP w logu.

## Wynik

NIEURUCHOMIONY. Domenę trzeba przypisać do oddzielnego katalogu, utworzyć i
zaimportować oddzielną bazę, skopiować uploady oraz włączyć Basic Auth,
`noindex`, blokadę maili i blokadę trackingów. Dopiero potem można wdrożyć core
RC1 i osobny patch MERO.
