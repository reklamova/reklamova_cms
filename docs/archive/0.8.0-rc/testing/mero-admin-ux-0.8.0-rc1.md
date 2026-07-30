# MERO admin UX 0.8.0-rc1

Data przeglądu: 2026-07-28

## Zweryfikowane w kodzie

- custom MERO używa `nav_key: leads` dla Zapytania,
- custom MERO używa `nav_key: knowledge` dla Poradnika,
- manager modułów deduplikuje po `nav_key` i preferuje moduł custom,
- menu używa nazw `Zapytania`, `Poradnik` i `Kalkulator budowy`,
- `GET /admin/mero/leads` wymaga `view_leads`,
- `POST /admin/mero/leads` wymaga `manage_inquiries`,
- Kalkulator wymaga `manage_products`,
- Poradnik używa polskich statusów i akordeonu SEO,
- klient nie widzi surowego payloadu Zapytania,
- Reklamova Admin może rozwinąć `Dane techniczne`,
- Trust jest ukryty klientowi bez placementu.

## Manualny test MERO staging

Do sprawdzenia po wdrożeniu osobnego patcha MERO:

- `/admin`
- `/admin/pages`
- `/admin/pages/edit`
- `/admin/media`
- `/admin/business`
- `/admin/mero/leads`
- `/admin/mero/articles`
- `/admin/mero/calculator`
- `/admin/catalog/products`
- `/admin/catalog/categories`
- `/admin/privacy`
- `/admin/updates`
- `/admin/system` jako Reklamova Admin

Kryteria:

- jedna pozycja `Zapytania`, bez `MERO Leady` i `Leady`,
- jedna pozycja `Poradnik`, bez `MERO Poradnik`,
- `Strona główna`, bez `Strona firmowa`,
- Trust widoczny klientowi wyłącznie z placementem i uprawnieniem,
- brak błędów PHP i ostrzeżeń przed wysłaniem nagłówków,
- zapis formularzy i przekierowania działają poprawnie.

Manualny staging nie został wykonany, ponieważ nie udostępniono oddzielnej
instalacji MERO staging z kopią bazy. Nie należy wdrażać RC bezpośrednio na
produkcyjne `mero.pl`.

Status: PRZEGLĄD KODU ZALICZONY, MANUALNY STAGING OCZEKUJE.
