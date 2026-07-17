# Safe Update Checklist

## Przed aktualizacją

- Sprawdź licencję i odpowiedź update-servera.
- Uruchom `Test aktualizacji` w panelu.
- Potwierdź PHP, rozszerzenia, prawa zapisu i miejsce na backup.
- Sprawdź typ aktualizacji: `security`, `patch`, `minor`, `major`.
- Sprawdź, że paczka nie zawiera protected paths.
- Sprawdź checksum core i podpis paczki.

## Backup

Update tworzy backup w `app/storage/backups`.

Backup zawiera:

- `core-files.zip`,
- `database.sql.gz`,
- `manifest.json`.

Dump bazy:

- najpierw próbuje `mysqldump`,
- gdy nie jest dostępny, używa PDO dump,
- zapisuje hash i rozmiar w manifeście.

## Protected Paths

Core update nie może nadpisywać:

- `app/config`,
- `app/themes`,
- `app/modules/custom`,
- `public/uploads`,
- `app/storage/backups`,
- `app/storage/logs`.

## Rollback

Przy błędzie update:

1. Przywraca pliki core z `core-files.zip`.
2. Próbuje przywrócić bazę z `database.sql.gz`.
3. Usuwa maintenance lock.
4. Zapisuje błąd w `cms_update_log`.

## Test ręczny

1. Sprawdź aktualizacje.
2. Kliknij `Test aktualizacji`.
3. Potwierdź, że raport ma status `ok`.
4. Kliknij realną aktualizację.
5. Sprawdź `cms_update_log`, wersję CMS i publiczną stronę.
6. Potwierdź, że `app/config`, `app/themes`, `app/modules/custom` i `public/uploads` nie zmieniły się po aktualizacji.
7. Wymuś błąd na paczce testowej i potwierdź rollback.
