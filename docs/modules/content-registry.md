# Content Registry

`ContentRegistry` mapuje techniczne moduły i trasy admina na zrozumiałe elementy panelu klienta.

Każdy rekord opisuje:
- `technical_slug`,
- `public_label`,
- `menu_label`,
- `public_description`,
- `empty_state_title`,
- `empty_state_description`,
- `where_it_appears`,
- `preview_url_pattern`,
- `menu_group`,
- `required_permission`,
- `visible_in_client_nav`,
- `visible_in_reklamova_nav`,
- `sort_order`,
- `is_site_specific`,
- `is_core`,
- `is_system`,
- `is_locked`.

## Przykłady mapowania

`business` -> `Strona główna`

`pages` -> `Podstrony`

`leads`, `mero/leads` -> `Zapytania`

`knowledge`, `mero/articles` -> `Poradnik`

`catalog/products` -> `Produkty`

`catalog/categories` -> `Kategorie produktów`

`trust` -> `Opinie i wiarygodność`

`landing` -> `Strony kampanii`

## Zasada

Jeśli moduł nie ma jasnego `where_it_appears`, nie powinien być widoczny w menu klienta. Reklamova może widzieć taki moduł technicznie i zdecydować, czy dodać miejsce w motywie.
