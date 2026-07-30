# PHP lint 0.8.0-rc3

Data testu: 2026-07-30

Artefakt:

- wersja: `0.8.0-rc3`,
- commit źródłowy: `47743a75efad095435dec4e0a1b005354dc12f7f`,
- package ID: `pkg_core_0_8_0_rc3`.

## Wyniki

| Zakres | PHP CLI | Pliki PHP | Błędy |
| --- | --- | ---: | ---: |
| Artefakt RC3 na update serverze | 8.3.31 | 90 | 0 |
| Clean CMS po aktualizacji | 8.3.31 | 114 | 0 |
| MERO staging po aktualizacji | 8.4.20 | 114 | 0 |
| PowerTech staging po aktualizacji | 8.4.18 | 127 | 0 |

Na stagingach lint objął wszystkie pliki `*.php` poza prywatnymi kopiami w
`app/storage` oraz zależnościami `vendor`. Użyto natywnego interpretera PHP
wybranego dla danego hostingu.

Kontrola ostatnio modyfikowanych logów PHP po aktualizacji nie wykazała
`Fatal error`, `Uncaught`, `Parse error`, `Warning` ani `stack trace`.
Kontrolowany test niedostępnej bazy zapisywał szczegół wyłącznie w izolowanym,
tymczasowym logu, który został usunięty po teście.

Status lint: **ZALICZONY**.
