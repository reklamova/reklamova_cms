# Format podpisanej paczki ZIP

Paczka aktualizacji core może zawierać wyłącznie:

```text
reklamova-core-0.8.0-rc2.zip
  manifest.json
  checksums.json
  files/
    app/core/
    app/migrations/core/
    app/modules/business/
    app/modules/catalog/
    app/modules/forms/
    app/modules/knowledge/
    app/modules/landing/
    app/modules/leads/
    app/modules/media/
    app/modules/pages/
    app/modules/privacy/
    app/modules/seo/
    app/modules/trust/
    app/modules/updates/
    public/assets/core/
    docs/
    reklamova.json
    app/config/placements.example.php
```

Paczka nie może zawierać:

```text
app/config
app/themes
app/modules/custom
public/uploads
app/storage/backups
app/storage/logs
```

`app/config/placements.example.php` jest jedynym dozwolonym wyjątkiem w
chronionym katalogu `app/config`. Jest to przykład konfiguracji, a nie aktywna
konfiguracja instalacji.

## Manifest

```json
{
  "package_id": "pkg_core_0_8_0_rc2",
  "type": "core",
  "version": "0.8.0-rc2",
  "channel": "rc",
  "from_versions": [">=0.1.0 <0.8.0-rc2"],
  "created_at": "2026-07-28T10:00:00Z",
  "requires": {
    "php": ">=8.3",
    "mysql": ">=8.0 || mariadb >=10.6"
  },
  "protected_paths": [
    "app/config",
    "app/themes",
    "app/modules/custom",
    "public/uploads",
    "app/storage/backups",
    "app/storage/logs"
  ]
}
```

## Walidacja zakresu

Przed podpisaniem uruchom:

```bash
php tools/build-update-package.php --version=0.8.0-rc2 --channel=rc --package-id=pkg_core_0_8_0_rc2 --validate-only
```

Generator ma przerwać pracę, jeśli `core_paths` zawiera ścieżkę spoza
allowlisty albo jakikolwiek katalog `app/modules/custom`.

## Weryfikacja instalacji

Instalacja akceptuje paczkę tylko wtedy, gdy:

- SHA-256 paczki zgadza się z API,
- podpis Ed25519 jest poprawny,
- wersja źródłowa jest zgodna,
- PHP i baza spełniają wymagania,
- paczka nie dotyka chronionych ścieżek poza jawnym plikiem przykładowym.

## Kolejność aktualizacji

1. Utworzenie `update.lock`.
2. Pobranie ZIP.
3. Weryfikacja SHA-256.
4. Weryfikacja podpisu.
5. Rozpakowanie do `app/storage/update-staging`.
6. Backup core i bazy.
7. Włączenie `maintenance.lock`.
8. Podmiana wyłącznie ścieżek z allowlisty core.
9. Migracje.
10. Czyszczenie cache.
11. Health check.
12. Raport do update servera.
13. Rollback przy błędzie.
