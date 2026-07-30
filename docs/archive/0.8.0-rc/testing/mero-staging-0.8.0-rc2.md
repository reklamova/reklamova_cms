# MERO staging 0.8.0-rc2

Data testu: 2026-07-29

Adres: `https://staging.mero.pl`

## Aktualizacja

- wersja po aktualizacji: `0.8.0-rc2`,
- aktualizacja przez standardowy updater: HTTP 302 i `updated=1`,
- backup core i bazy: poprawny,
- chronione ścieżki: bez zmian,
- update API `rc`: HTTP 200, brak nowszej wersji.

## Trasy

HTTP 200 zwracają:

- front,
- `/admin/`,
- `/admin/pages`,
- `/admin/business`,
- `/admin/mero/leads`,
- `/admin/mero/articles`,
- `/admin/mero/calculator`,
- `/poradnik`,
- `/kalkulator-budowy-domu`,
- `/ustawienia-prywatnosci`,
- `/api/privacy/settings`.

## Role i panel

- `client_admin` nie widzi modułów, motywów, systemu, aktualizacji ani health,
- `reklamova_admin` widzi techniczne menu,
- klient widzi jeden Kalkulator oraz po jednej pozycji Zapytania i Poradnik
  w menu i szybkich akcjach,
- Zapytania są czytelne dla klienta,
- surowy payload jest dostępny tylko dla Reklamova Admin pod
  `Dane techniczne`,
- Poradnik ma polskie statusy i SEO w akordeonie.

## Front i Privacy Center

- jeden CSS, JS, root i config Privacy Center,
- brak starego popupu i starego endpointu MERO,
- zgoda domyślna jest ustawiana przed managerem,
- formularz kalkulatora zapisał poprawny snapshot zgody,
- rekord formularza testowego został usunięty,
- brak nowych błędów PHP.

## Dane

Po aktualizacji i ponownych migracjach zachowano:

- 28 stron,
- 31 mediów,
- 6 artykułów,
- 1 istniejący lead,
- 1 istniejącą decyzję Privacy Center.

Status techniczny: **ZALICZONY**.

Pozostaje ręczny test trwałości cookies w przeglądarce.
