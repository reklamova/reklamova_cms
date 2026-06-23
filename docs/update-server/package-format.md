# Signed ZIP Package Format

Paczka aktualizacji core:

```text
reklamova-core-0.2.0.zip
  manifest.json
  checksums.json
  files/
    app/core/
    app/migrations/core/
    public/assets/core/
```

Paczka nie moze zawierac:

```text
app/config
app/themes
app/modules/custom
public/uploads
app/storage/backups
app/storage/logs
```

## manifest.json

```json
{
  "package_id": "pkg_core_0_2_0",
  "type": "core",
  "version": "0.2.0",
  "from_versions": [">=0.1.0 <0.2.0"],
  "created_at": "2026-06-23T10:00:00Z",
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

## Weryfikacja

Instalacja akceptuje paczke tylko gdy:

- SHA-256 paczki zgadza sie z API,
- podpis Ed25519 jest poprawny,
- wersja zrodlowa jest zgodna,
- PHP/MySQL spelniaja wymagania,
- paczka nie dotyka protected paths.

## Kolejnosc aktualizacji

1. `update.lock`
2. pobranie ZIP
3. weryfikacja SHA-256
4. weryfikacja podpisu
5. staging w `app/storage/update-staging`
6. backup core i bazy
7. `maintenance.lock`
8. podmiana tylko core paths
9. migracje
10. czyszczenie cache
11. health check
12. raport do update servera
13. rollback przy bledzie

