# MERO admin 0.8.0-1

Status: wdrożony na produkcji MERO

Data wdrożenia: 2026-07-30

## Zakres

Patch zmienił wyłącznie:

- `app/modules/custom/mero/admin.php`
- `app/modules/custom/mero/module.json`

Patch nie zmienił core Reklamova CMS 0.8.0, publicznego routingu, formularza
kontaktowego, bazy danych, migracji, motywu, konfiguracji ani uploadów.

## Integralność

- `admin.php`: `bf0380929ae04e1fbc7992e45c17d333761e2df74144a0180eb792759c5cdfc8`
- `module.json`: `69dfb7ef4deb1ab944a1aef55d5f1bde2583a2b79c5cd2c55e4390e27529b9ce`
- paczka ZIP: `4cf9f8937f9f3dd88fd62e05f28693d4fd2959847abd62ac62bb748baee9be9d`

Backup produkcyjny modułu:

`app/storage/backups/mero_admin_0_8_0_1_20260730_131708`

## Walidacja

Na stagingu i produkcji sprawdzono:

- istniejący formularz na `/kontakt`,
- bezpieczną walidację formularza,
- `/admin/mero/leads`,
- `/admin/mero/articles`,
- `/admin/mero/calculator`,
- brak duplikatów menu,
- uprawnienia `client_admin` i `reklamova_admin`,
- brak nowych błędów PHP.

Produkcyjny `app/modules/custom/mero/public.php` nie został zmieniony.

