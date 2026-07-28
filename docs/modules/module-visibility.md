# Widoczność modułów

Moduł może być aktywny technicznie, ale niewidoczny dla klienta.

## Pola modułu

- `enabled` - moduł jest aktywny w instalacji,
- `locked` - nie można go wyłączyć z panelu,
- `system` - część core CMS,
- `visible_in_client_nav` - może pojawić się w menu klienta,
- `visible_in_admin_nav` - może pojawić się w menu Reklamova,
- `client_manageable` - klient może obsługiwać ekran modułu,
- `requires` - zależności,
- `permissions` - wymagane uprawnienia,
- `menu_group` - grupa w menu,
- `menu_label` - etykieta w UI,
- `settings_json` - konfiguracja per instalacja.

## Reguły

Klient widzi moduł tylko wtedy, gdy:
- moduł jest aktywny,
- ma `visible_in_client_nav = true`,
- użytkownik ma wymagane uprawnienie,
- moduł ma jasny opis i miejsce wyświetlania,
- moduł nie jest techniczny.

Reklamova widzi moduły techniczne w grupie `Reklamova`.

Historyczne aliasy uprawnień (`manage_privacy`, `manage_themes`, `view_health`) pozostają po to, żeby stare instalacje nie straciły dostępu po aktualizacji.
