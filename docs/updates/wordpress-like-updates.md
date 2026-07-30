# Aktualizacje jak WordPress

Reklamova CMS jest self-hosted. Każdy klient ma osobną instalację, bazę, uploady, motyw i konfigurację. Core jest rozwijany centralnie i aktualizowany przez podpisane paczki ZIP.

## Co wysyła instalacja

- `site_id`,
- `site_key`,
- aktualną wersję CMS,
- wersję PHP,
- wersję bazy,
- aktywne moduły,
- motyw,
- checksum core.

## Co zwraca update server

- informację, czy jest aktualizacja,
- wersję docelową,
- typ aktualizacji: security, patch, minor, major,
- changelog,
- wymagania,
- URL paczki,
- checksum,
- podpis.

## Przed aktualizacją

CMS sprawdza:
- wersję PHP,
- rozszerzenia,
- prawa zapisu,
- wolne miejsce,
- protected paths,
- integralność i podpis paczki.

Następnie wykonuje:
- backup bazy,
- backup plików core,
- maintenance mode.

## Aktualizacja może zmieniać

- `app/core`,
- `app/migrations/core`,
- `public/assets/core`,
- ewentualnie `vendor`.

## Aktualizacja nie może zmieniać

- `app/config`,
- `app/themes`,
- `app/modules/custom`,
- `public/uploads`,
- `app/storage/backups`,
- `app/storage/logs`.

## Po aktualizacji

CMS uruchamia migracje, czyści cache, wykonuje health check, zapisuje log i wyłącza maintenance mode. W razie błędu przywraca backup core oraz backup bazy, jeśli migracje zostały wykonane.

Klient widzi prosty komunikat o dostępnej aktualizacji. Reklamova widzi dry run, log, backup, rollback i szczegóły techniczne.
