# Środowiska stagingowe Reklamova CMS

Stan bazowy: 2026-07-30. Ostatnia ponowna walidacja: 2026-08-12.

Oba stagingi klientów mają stabilny rdzeń 0.8.0 zgodny 108/108 plików z
aktualnym `main`. Kanał `rc` pozostaje ustawieniem przyszłych aktualizacji, a
nie oznaczeniem aktualnie zainstalowanego core. Szczegóły:
[`client-separation-2026-08-12.md`](./client-separation-2026-08-12.md).

## Stały staging CMS

`cms-rc1.reklamova.pl` jest stałym środowiskiem testowym Reklamova CMS.

- Basic Auth: aktywny,
- `X-Robots-Tag`: `noindex, nofollow, noarchive`,
- kanał aktualizacji: `rc`,
- produkcyjne trackery w konfiguracji i motywie: nie znaleziono,
- realna poczta: blokowana przez stagingowy `auto_prepend_file`,
- pliki i baza: zachowane.

Zmiana kanału `stable` na `rc` i blokada poczty zostały wykonane wyłącznie na
stagingu. Backup manifestu znajduje się poza document root w:

`staging-operations/20260730-cleanup-080`.

## Staging MERO

`staging.mero.pl` pozostaje odizolowany od produkcji.

- Basic Auth: aktywny,
- `X-Robots-Tag`: `noindex, nofollow, noarchive`,
- kanał aktualizacji: `rc`,
- osobna baza: zachowana,
- produkcyjne trackery w konfiguracji i motywie: nie znaleziono,
- realna poczta core i formularza MERO: blokowana,
- lint stagingowego bridge poczty: zaliczony na PHP 8.5.
- core stable 0.8.0: zgodny 108/108 plików z GitHub,
- aktywne funkcje: `mero`, `knowledge`, `privacy`,
- puste moduły biznesowe: wyłączone bez usuwania tabel.

Backup pliku custom sprzed dodania hostowego warunku blokującego pocztę znajduje
się poza document root w:

`staging-operations/20260730-cleanup-080`.

## Staging PowerTech

`staging.powertechsc.pl` pozostaje aktywny.

- Basic Auth: aktywny,
- `X-Robots-Tag`: `noindex, nofollow, noarchive`,
- osobna baza: potwierdzona podczas walidacji RC,
- blokada realnej poczty: potwierdzona podczas walidacji RC,
- blokada realnej poczty: ponownie potwierdzona 2026-08-12,
- core stable 0.8.0: zgodny 108/108 plików z GitHub,
- warstwa klienta: `app/modules/custom/powertech` i `app/themes/powertech`,
- moduł MERO: nieobecny w plikach, konfiguracji i bazie.

Staging nie jest obecnie usuwany. Najwcześniejszy termin przeglądu pod kątem
archiwizacji to 2026-08-06. Brak automatycznego usuwania w tej dacie.

## Procedura przyszłej archiwizacji

Przed usunięciem dowolnego stagingu klienta:

1. wykonaj backup plików i dump osobnej bazy,
2. zapisz SHA-256 archiwów,
3. sprawdź integralność TAR.GZ i GZIP,
4. przenieś archiwa poza document root,
5. zachowaj archiwa przez minimum 30 dni,
6. dopiero potem usuń vhost, pliki i bazę.

Stały staging CMS nie jest objęty planem usunięcia.
