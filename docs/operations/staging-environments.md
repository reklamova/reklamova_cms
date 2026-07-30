# Środowiska stagingowe Reklamova CMS

Stan na 2026-07-30.

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

Backup pliku custom sprzed dodania hostowego warunku blokującego pocztę znajduje
się poza document root w:

`staging-operations/20260730-cleanup-080`.

## Staging PowerTech

`staging.powertechsc.pl` pozostaje aktywny.

- Basic Auth: aktywny,
- `X-Robots-Tag`: `noindex, nofollow, noarchive`,
- osobna baza: potwierdzona podczas walidacji RC,
- blokada realnej poczty: potwierdzona podczas walidacji RC,
- bieżąca ponowna kontrola plików: oczekuje na autoryzowany dostęp SSH do LH.

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
