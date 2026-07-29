# Test migracji 0.8.0-rc2

Data testu: 2026-07-29

## MERO staging

Migrator core i aktywnych modułów uruchomiono ponownie po aktualizacji RC2.

- wpisy migracji przed: 18,
- wpisy migracji po: 18,
- migracja `2026_07_28_000008_content_registry_menu_cleanup`: 1 wpis,
- brakujące kolumny wymagane przez `000008`: 0.

Liczby rekordów przed i po:

| Tabela | Przed | Po |
| --- | ---: | ---: |
| `cms_pages` | 28 | 28 |
| `cms_media` | 31 | 31 |
| `mero_articles` | 6 | 6 |
| `mero_leads` | 1 | 1 |
| `privacy_consents` | 1 | 1 |

## PowerTech staging

- wpisy migracji przed: 17,
- wpisy migracji po: 17,
- migracja `2026_07_28_000008_content_registry_menu_cleanup`: 1 wpis,
- brakujące kolumny wymagane przez `000008`: 0.

Liczby rekordów przed i po:

| Tabela | Przed | Po |
| --- | ---: | ---: |
| `cms_pages` | 21 | 21 |
| `cms_media` | 1438 | 1438 |
| `catalog_products` | 104 | 104 |
| `catalog_categories` | 69 | 69 |
| `privacy_consents` | 16 | 16 |

## Backup i chronione ścieżki

Obie aktualizacje wykonały backup core i bazy. Archiwa bazy dały się odczytać,
ZIP core przeszedł test integralności, a manifest backupu był kompletny.
Hash chronionych ścieżek przed i po aktualizacji był identyczny.

## Clean CMS

Pełny ciąg migracji na tej bazie przeszedł wcześniej dla RC1. Powtórzenie testu
RC2 jest zablokowane przez odrzucenie danych MySQL:

- baza i użytkownik: `host379800_staging`,
- host: `localhost`,
- błąd PDO: `1045`,
- front i `/admin` po Basic Auth: HTTP 500.

Hasło jest zapisane w konfiguracji, ale serwer MySQL go nie akceptuje. Do
zamknięcia testu trzeba ponownie ustawić hasło użytkownika w Hostido i wpisać tę
samą wartość do `app/config/database.php`.

Status migracji:

- MERO: **ZALICZONY**,
- PowerTech: **ZALICZONY**,
- clean CMS RC2: **ZABLOKOWANY PRZEZ DOSTĘP DB**.
