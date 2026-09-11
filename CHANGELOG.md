# Changelog

Wszystkie istotne zmiany CMS Reklamova są dokumentowane w tym pliku.

## Unreleased

### Added

- Przedwdrożeniowy audyt Drukarnia Reklamova 2.0 i mapa migracji SEO.
- Opcjonalny, generyczny moduł Commerce ze wspólnymi typami pieniędzy, VAT, kalkulacją koszyka, statusami i kontraktem providerów płatności.
- Minimalny runner testów uruchamiany przez `composer test`.
- Idempotentny importer WooCommerce DB z trybem `dry-run`, raportem konfliktów i kopiowaniem mediów z kontrolą SHA-256.

### Security

- Bezpieczne parametry cookies sesyjnych i rotacja identyfikatora po logowaniu/wylogowaniu.
- Uprawnienia wewnętrzne wynikają wyłącznie z roli, nigdy z nazwy hosta.
- Walidacja MIME, rozszerzenia i rozmiaru uploadów mediów przed zapisem.
