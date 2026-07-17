# Aktualizacje core jak WordPress

Reklamova CMS jest self-hosted. Każdy klient ma osobną instalację, ale core jest rozwijany centralnie przez Reklamova.

## Co może zmienić update core

- `reklamova.json`
- `app/bootstrap.php`
- `app/core`
- `app/migrations/core`
- `app/modules` z wyłączeniem `app/modules/custom`
- `public/index.php`
- `public/admin`
- `public/assets/core`

## Czego update nie może zmienić

- `app/config`
- `app/themes`
- `app/modules/custom`
- `public/uploads`
- `app/storage/backups`
- `app/storage/logs`

## Check update

Instalacja wysyła do `updates.reklamova.pl` site ID, bearer site key, domenę, obecną wersję CMS, PHP, wersję bazy, aktywne moduły, motyw, checksum core i health check.

Update server zwraca informację o dostępności aktualizacji, wersję docelową, typ aktualizacji, changelog, wymagania, URL paczki, checksum i podpis.

## Przebieg aktualizacji

1. Sprawdzenie środowiska.
2. Pobranie paczki.
3. Weryfikacja checksum i podpisu.
4. Dry run protected paths.
5. Backup bazy i core.
6. Maintenance mode.
7. Podmiana tylko core paths.
8. Migracje.
9. Cache clear.
10. Health check.
11. Wyłączenie maintenance mode.
12. Update log.

Klient widzi prosty komunikat. Reklamova widzi szczegóły techniczne, dry run, backup, rollback i log.
