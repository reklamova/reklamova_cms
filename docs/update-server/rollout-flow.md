# Rollout aktualizacji Reklamova CMS

Reklamova CMS jest systemem self-hosted. Każdy klient ma osobne pliki i bazę,
ale aktualizacje core są publikowane centralnie przez `updates.reklamova.pl`.

## Role systemów

- GitHub `reklamova/reklamova_cms` jest źródłem prawdy dla core.
- `updates.reklamova.pl` przechowuje licencje, statusy i podpisane paczki.
- Instalacja klienta cyklicznie pyta o nową wersję.
- Aktualizacja chroni motyw, konfigurację, uploady i moduły custom.

## Przepływ dla wielu klientów

1. Reklamova kończy i testuje zmianę core.
2. Generator waliduje ścisłą allowlistę ścieżek.
3. Zmiany `app/modules/custom/**` otrzymują osobny patch klienta.
4. Paczka core jest podpisywana kluczem Ed25519.
5. Update server publikuje paczkę na wybranym kanale.
6. Cron instalacji zapisuje status w `app/storage/cache/update-status.json`.
7. Panel pokazuje informację o nowej wersji.
8. Uprawniony administrator uruchamia aktualizację.
9. Instalacja weryfikuje SHA-256 i podpis.
10. Instalacja wykonuje backup core i bazy.
11. Updater podmienia wyłącznie dozwolone ścieżki.
12. Migracje, cache i health check kończą proces.
13. Błąd powoduje automatyczny rollback.

## Zakres core

- `app/core`
- `app/migrations/core`
- jawnie wymienione moduły oficjalne z `app/modules/{slug}`
- `public/assets/core`
- `docs`
- `reklamova.json`
- `app/config/placements.example.php`

Pełna lista znajduje się w `reklamova.json` oraz
`docs/update-server/package-format.md`.

## Chronione dane instalacji

- `app/config`
- `app/themes`
- `app/modules/custom`
- `public/uploads`
- `app/storage/backups`
- `app/storage/logs`

## Walidacja przed buildem

```bash
php tools/build-update-package.php \
  --version=0.8.0-rc1 \
  --validate-only
```

To polecenie nie tworzy ZIP i nie wymaga prywatnego klucza.

## Budowanie po zaliczeniu RC

```bash
REKLAMOVA_UPDATE_PRIVATE_KEY_B64=... php tools/build-update-package.php \
  --version=0.8.0-rc1 \
  --channel=rc \
  --base-url=https://updates.reklamova.pl \
  --out=build/update-packages
```

Prywatny klucz pozostaje poza repozytorium. Wpis
`index-entry-pkg_core_0_8_0_rc1.json` można opublikować dopiero po zatwierdzeniu
wyników stagingu.

## Update server MVP

Endpointy:

- `POST /api/v1/check-update`
- `GET /api/v1/packages/{packageId}/download`
- `POST /api/v1/report-update-started`
- `POST /api/v1/report-update-finished`
- `POST /api/v1/report-update-failed`
- `POST /api/v1/report-health`

RC musi być publikowany na kanale `rc`, nigdy na `stable`.
