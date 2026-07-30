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

## Clean CMS staging

Po ponownym ustawieniu hasła użytkownika `host379800_staging` potwierdzono
połączenie PDO z MariaDB 10.11.18. Clean staging został następnie zaktualizowany
standardowym updaterem z RC1 do RC2.

- wpisy migracji przed: 17,
- wpisy migracji po: 17,
- migracja `2026_07_28_000008_content_registry_menu_cleanup`: 1 wpis,
- brakujące kolumny wymagane przez `000008`: 0.

Liczby rekordów przed i po ponownym uruchomieniu:

| Tabela | Przed | Po |
| --- | ---: | ---: |
| `cms_pages` | 2 | 2 |
| `cms_media` | 1 | 1 |
| `privacy_consents` | 2 | 2 |

Backup `bkp_20260729_140203` zawiera poprawny manifest, archiwum core i
skompresowany dump bazy. ZIP i GZIP przeszły test integralności. Log aktualizacji
ma status `updated`, bez komunikatu błędu.

## Backup i chronione ścieżki

Wszystkie trzy aktualizacje wykonały backup core i bazy. Archiwa bazy dały się
odczytać, ZIP core przeszedł test integralności, a manifesty były kompletne.
Chronione ścieżki nie zostały objęte paczką RC2.

Status migracji na clean CMS, MERO i PowerTech: **ZALICZONY**.
