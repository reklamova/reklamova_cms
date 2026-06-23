# Update Server API

Centralny update server dziala pod:

```text
https://updates.reklamova.pl
```

Nie hostuje stron klientow. Utrzymuje licencje, paczki, status instalacji i health reports.

## POST /api/v1/register-installation

Rejestruje nowa instalacje po zakonczeniu instalatora.

```json
{
  "site_id": "uuid",
  "domain": "klient.pl",
  "cms_version": "0.1.0",
  "php_version": "8.3.0",
  "site_key": "rklv_live_xxx"
}
```

## POST /api/v1/check-update

Instalacja cyklicznie pyta o aktualizacje.

Headers:

```http
Authorization: Bearer rklv_live_xxx
Content-Type: application/json
```

Request:

```json
{
  "site_id": "uuid",
  "domain": "klient.pl",
  "cms_version": "0.1.0",
  "php_version": "8.3.4",
  "mysql_version": "10.6.18-MariaDB",
  "active_modules": {
    "seo": "1.2.0"
  },
  "capabilities": {
    "zip": true,
    "openssl": true,
    "cron": true,
    "composer": false,
    "git": false
  }
}
```

Response:

```json
{
  "update_available": true,
  "current_version": "0.1.0",
  "latest_version": "0.2.0",
  "minimum_php": "8.3",
  "package": {
    "id": "pkg_core_0_2_0",
    "type": "core",
    "url": "https://updates.reklamova.pl/api/v1/packages/pkg_core_0_2_0/download",
    "sha256": "abc123",
    "signature": "base64-ed25519-signature",
    "signature_algorithm": "ed25519"
  }
}
```

## GET /api/v1/packages/{packageId}/download

Zwraca ZIP tylko dla aktywnej licencji i zgodnej instalacji.

## POST /api/v1/report-health

Instalacja raportuje status hostingu.

```json
{
  "site_id": "uuid",
  "cms_version": "0.1.0",
  "php": {
    "version": "8.3.4",
    "status": "ok"
  },
  "database": {
    "driver": "mysql",
    "version": "10.6.18-MariaDB",
    "status": "ok"
  },
  "ssl": {
    "enabled": true,
    "valid": true
  },
  "cron": {
    "enabled": true,
    "last_run_at": "2026-06-23T12:00:00+02:00"
  },
  "extensions": {
    "pdo_mysql": true,
    "openssl": true,
    "curl": true,
    "zip": true,
    "mbstring": true,
    "json": true,
    "fileinfo": true,
    "sodium": true
  }
}
```

## POST /api/v1/report-update-started

## POST /api/v1/report-update-finished

## POST /api/v1/report-update-failed

Te endpointy zasilaja panel centralny Reklamova statusem aktualizacji i rollbacku.
