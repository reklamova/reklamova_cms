# Test migracji 0.8.0-rc1

Data testu: 2026-07-28

Test dotyczył:

`app/migrations/core/2026_07_28_000008_content_registry_menu_cleanup.php`

## Wykonane testy automatyczne

Migrację uruchomiono na MariaDB 10.11 w izolowanych tabelach z losowym
prefiksem. Nie użyto i nie zmieniono produkcyjnych tabel `cms_*`.

| Scenariusz | Wynik | Weryfikacja |
| --- | --- | --- |
| Brak tabel bazowych | ZALICZONY | Bezpieczne zakończenie bez tworzenia przypadkowych tabel |
| Stara struktura przed `000008` | ZALICZONY | Dodano 7 wymaganych kolumn i zasiano 30 uprawnień |
| Częściowy update | ZALICZONY | Dodano tylko brakujące kolumny |
| Ponowne uruchomienie | ZALICZONY | Liczba rekordów nie zmieniła się |
| Ochrona danych | ZALICZONY | Zachowano własny moduł, nazwę i własne uprawnienie |
| Sprzątanie | ZALICZONY | Wszystkie tabele testowe usunięto |

Dodawane defensywnie kolumny:

- `menu_label`
- `menu_group`
- `sort_order`
- `visible_in_client_nav`
- `visible_in_admin_nav`
- `permissions_json`
- `updated_at`

## Testy wymagane przed merge

Poniższe testy nie zostały wykonane, ponieważ na hostingu Reklamova nie ma
kopii baz klientów:

- pełny ciąg migracji na czystej, pustej bazie,
- pełny ciąg migracji na kopii bazy MERO,
- pełny ciąg migracji na kopii bazy PowerTech.

Do tych testów należy użyć osobnych baz stagingowych. Nie wolno uruchamiać
testowej migracji bezpośrednio na produkcyjnej bazie klienta.

Status całego wymagania migracyjnego: CZĘŚCIOWO ZALICZONY, BLOKADA MERGE.
