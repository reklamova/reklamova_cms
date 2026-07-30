# PHP lint 0.8.0-rc1

Data testu: 2026-07-28

Środowisko:

- PHP CLI 8.3.31
- Linux, hosting Reklamova
- rozszerzenia wymagane przez updater: `pdo_mysql`, `zip`, `sodium`
- izolowany katalog poza document root

Polecenie:

```bash
find . -name "*.php" -not -path "./vendor/*" -print0 \
  | xargs -0 -n1 php -l
```

Wynik:

- sprawdzone pliki PHP: 114
- błędy składni: 0
- status: ZALICZONY

Dodatkowe kontrole:

- `module.json`: 13 plików, 0 błędów JSON i 0 brakujących pól
  `slug`, `name`, `version`
- walidacja zakresu core przez `--validate-only`: ZALICZONA
- negatywny test z `app/modules/custom/mero`: generator przerwał pracę
- updater zaakceptował wyłącznie `app/config/placements.example.php`
- updater odrzucił `app/config/app.php` i `app/modules/custom/mero/admin.php`
- produkcyjna paczka ZIP nie została zbudowana

Lint należy powtórzyć po każdej zmianie kodu przed merge do `main`.
