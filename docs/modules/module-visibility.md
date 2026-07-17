# Widoczność modułów

Moduł w Reklamova CMS jest funkcją instalacji klienta, a nie automatyczną pozycją menu.

## Pola modułu

- `slug`
- `name`
- `description`
- `version`
- `source`: `core`, `official`, `custom`
- `enabled`
- `locked`
- `system`
- `visible_in_client_nav`
- `visible_in_admin_nav`
- `client_manageable`
- `requires`
- `permissions`
- `menu_group`
- `menu_label`
- `sort_order`
- `settings_json`

## Domyślne zasady

- `pages`, `media`, `privacy`, `updates` są systemowe.
- `catalog`, `business`, `knowledge`, `landing`, `trust`, `leads` są domyślnie wyłączone dla nowych instalacji, chyba że instalator albo konfiguracja strony je aktywuje.
- Wyłączenie modułu nie usuwa jego tabel ani danych.

## Test ręczny

1. Strona bez katalogu nie pokazuje `Produkty` ani `Kategorie produktów`.
2. Strona z katalogiem pokazuje obie pozycje.
3. `client_admin` nie widzi grupy `Reklamova / techniczne`.
4. `reklamova_admin` widzi moduły i może zmieniać status funkcji.
