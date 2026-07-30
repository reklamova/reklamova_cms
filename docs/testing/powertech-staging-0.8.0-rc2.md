# PowerTech staging 0.8.0-rc2

Data testu: 2026-07-29

Adres: `https://staging.powertechsc.pl`

## Aktualizacja

- wersja po aktualizacji: `0.8.0-rc2`,
- standardowy updater zakończył się przekierowaniem `updated=1`,
- backup core i bazy przeszedł test integralności,
- chronione ścieżki mają identyczny hash przed i po,
- update API `rc`: HTTP 200, brak nowszej wersji.

Staging ma historycznie spłaszczony document root. Dodano wyłącznie na stagingu
symlink `public_html -> .`, aby updater mapował `public/assets/core` do katalogu
faktycznie obsługiwanego przez vhost.

## Regresja

HTTP 200 zwracają:

- front,
- `/o-nas/`,
- `/nasza-oferta/`,
- `/admin/`,
- `/admin/pages`,
- `/admin/media`,
- `/admin/catalog/products`,
- `/admin/catalog/categories`,
- `/admin/privacy`,
- `/ustawienia-prywatnosci`,
- `/api/privacy/settings`.

`client_admin` nie widzi menu technicznego. `reklamova_admin` widzi moduły,
motyw, stan systemu i health. Produkty oraz Kategorie produktów występują w
menu po jednym razie.

## Katalog i Privacy Center

- 104 produkty i 69 kategorii zostały zachowane,
- lista, filtry, szkice i osobny ekran kategorii działają,
- test `Powiel` przeszedł i został posprzątany,
- Privacy Center ma po jednym root, config, CSS i JS,
- Consent Mode default jest przed managerem,
- brak trackerów w HTML przed zgodą,
- brak nowych błędów PHP.

## Custom modules

Na stagingu nie ma `app/modules/custom/mero` ani innych modułów custom.
Aktualizacja core nie usuwała katalogów custom. Plan osobnego sprzątania
produkcji znajduje się w
`docs/releases/powertech-custom-cleanup-plan.md`.

Status techniczny: **ZALICZONY**.

Pozostaje ręczny test trwałości cookies w przeglądarce.
