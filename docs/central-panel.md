# Central Panel Reklamova

Panel centralny jest narzedziem utrzymaniowym. Nie hostuje stron klientow.

## Widoki

- klienci,
- instalacje,
- licencje,
- wersje CMS,
- paczki aktualizacji,
- moduly,
- health reports,
- update logs,
- alerty.

## Lista instalacji

Kolumny:

- domena,
- klient,
- wersja CMS,
- najnowsza wersja,
- PHP,
- MySQL/MariaDB,
- SSL,
- CRON,
- ostatni check,
- ostatni update,
- status licencji,
- status health,
- aktywne moduly.

## Statusy

- `ok`
- `update_available`
- `outdated`
- `license_expired`
- `php_too_old`
- `missing_extension`
- `cron_not_running`
- `ssl_invalid`
- `update_failed`
- `rollback_completed`

## Minimalny schemat centralny

```text
clients
installations
licenses
cms_versions
module_versions
packages
update_checks
update_jobs
health_reports
```

## installations

```text
id
client_id
site_key_hash
domain
cms_version
php_version
mysql_version
ssl_status
cron_status
last_check_at
last_update_at
status
created_at
updated_at
```

