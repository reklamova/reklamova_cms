# Module Management

Panel `/admin/modules` jest ekranem Reklamova i wymaga `manage_modules`.

## Pola modulu

- `enabled`: modul dziala technicznie.
- `visible_in_client_nav`: modul moze byc widoczny w menu klienta.
- `visible_in_admin_nav`: modul moze byc widoczny dla Reklamova.
- `locked`: modulu nie mozna wylaczyc z panelu.
- `client_manageable`: klient moze zarzadzac ustawieniami modulu.
- `settings_json`: bezpieczne ustawienia modulu.

## Zasady

- Modul systemowy moze byc aktywny, ale niewidoczny dla klienta.
- Brak nowych pol w `module.json` nie psuje modulu; `ModuleManager` dopisuje domyslne wartosci.
- Custom moduly w `app/modules/custom` sa chronione przez update core.
- Wylaczenie modulu nie kasuje danych.

## Test reczny

1. Wejdz jako `super_admin` w `/admin/modules`.
2. Wylacz modul nieoznaczony jako systemowy.
3. Sprawdz, ze znika z menu klienta, ale tabele/dane zostaja.
4. Wlacz go ponownie i sprawdz trase admina.
