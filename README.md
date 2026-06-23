# Reklamova CMS

Self-hosted CMS instalowany osobno na hostingach klientow, utrzymywany centralnie przez Reklamova.

To repozytorium zawiera core CMS, kontrakty modulow i motywow, instalator, updater oraz dokumentacje centralnego update servera.

## Zalozenia

- PHP 8.3+
- MySQL albo MariaDB
- kompatybilnosc z hostingami wspoldzielonymi
- dzialanie bez SSH
- aktualizacje przez podpisane paczki ZIP
- opcjonalny tryb Git/Composer dla lepszych hostingow
- panel admina pod `/admin`
- kazda instalacja ma wlasna baze, pliki, uploady i konfiguracje

## Granice aktualizacji

Core update moze zmieniac:

- `app/core`
- `app/migrations/core`
- `public/assets/core`
- `vendor`, jezeli paczka tego wymaga

Core update nie moze zmieniac:

- `app/config`
- `app/themes`
- `app/modules/custom`
- `public/uploads`
- `app/storage/backups`
- `app/storage/logs`

## Lokalna struktura

```text
public/
  index.php
  admin/index.php
  assets/core/
  uploads/
app/
  core/
  modules/
  themes/
  storage/
  config/
  migrations/core/
docs/
  update-server/
  hostido/
tools/
```

## Pierwszy etap implementacji

Ten szkielet zawiera:

- bootstrapping aplikacji,
- prosta odpowiedz frontu i panelu admina,
- kontrakt instalatora,
- health check hostingu,
- klienta update API,
- weryfikator paczek ZIP,
- updater z protected paths,
- backup manager,
- migrator bazy,
- zasady dla modulow i motywow.

## Hostido

Docelowy tryb wdrozenia na Hostido:

1. Wgranie paczki ZIP przez FTP albo manager plikow.
2. Ustawienie document root na `public`.
3. Wejscie na domene i uruchomienie instalatora.
4. Wprowadzenie danych MySQL/MariaDB.
5. Wklejenie site key wygenerowanego w panelu Reklamova.
6. Automatyczny health check i rejestracja instalacji w `updates.reklamova.pl`.

