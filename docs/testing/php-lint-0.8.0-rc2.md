# PHP lint 0.8.0-rc2

Data testu: 2026-07-29

Artefakt:

- wersja: `0.8.0-rc2`
- commit źródłowy: `b73c63127101166e35259736bf7d1db67c45e78f`
- package ID: `pkg_core_0_8_0_rc2`

## Wyniki

| Zakres | PHP CLI | Pliki PHP | Błędy | Ostrzeżenia |
| --- | --- | ---: | ---: | ---: |
| Źródło artefaktu RC2 na Hostido | 8.3.31 | 114 | 0 | 0 |
| Clean CMS staging | 8.3.31 | 114 | 0 | 0 |
| MERO staging po aktualizacji | 8.5.6 | 114 | 0 | 0 |
| PowerTech staging po aktualizacji | 8.3.30 | 127 | 0 | 0 |

Lint obejmował wszystkie pliki `*.php` poza katalogiem `vendor`.

Przykładowe polecenie:

```bash
find . -type f -name '*.php' -not -path './vendor/*'
php -l path/to/file.php
```

Dodatkowo sprawdzono:

- manifesty `module.json`,
- manifest i sumy kontrolne ZIP,
- zakres ścieżek paczki,
- brak `app/modules/custom/**`,
- negatywny test generatora z `app/modules/custom/mero`.

Generator przerwał budowę po wykryciu modułu custom. Artefakt RC2 nie zawiera
chronionych ścieżek.

Status lint: **ZALICZONY**.
