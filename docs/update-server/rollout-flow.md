# Rollout aktualizacji Reklamova CMS

Reklamova CMS jest self-hosted, wiec kazdy klient ma osobne pliki i baze. To nie oznacza recznego uploadu do kazdego hostingu. Aktualizacja core idzie przez centralny update server i panel klienta.

## Role

- Repozytorium GitHub `reklamova/reklamova_cms` jest zrodlem prawdy dla core.
- `updates.reklamova.pl` trzyma licencje, status instalacji i podpisane paczki ZIP.
- Instalacja klienta cyklicznie pyta update server o nowa wersje.
- Klient albo Reklamova klika `Zaktualizuj` w `/admin/updates`.

## Przeplyw dla 100 klientow

1. Reklamova konczy zmiane core, np. Privacy Center.
2. Reklamova buduje paczke `reklamova-core-x.y.z.zip`.
3. Paczka zawiera tylko core paths:
   - `app/bootstrap.php`
   - `app/core`
   - `app/migrations/core`
   - `app/modules` bez `app/modules/custom`
   - `public/.htaccess`
   - `public/index.php`
   - `public/admin`
   - `public/assets/core`
4. Paczka jest podpisana kluczem Ed25519.
5. Update server publikuje metadane paczki dla wybranej wersji/kanalu.
6. Cron instalacji zapisuje status w `app/storage/cache/update-status.json`.
7. Dashboard pokazuje komunikat o nowej wersji.
8. Administrator wchodzi w `/admin/updates` i klika `Zaktualizuj`.
9. Instalacja pobiera ZIP, weryfikuje SHA-256 i podpis.
10. Instalacja robi backup core i bazy.
11. Instalacja podmienia core paths, uruchamia migracje core i modulow.
12. Health check potwierdza, ze strona dziala.
13. W razie bledu updater przywraca backup.

## Czego update core nie rusza

- `app/config`
- `app/themes`
- `app/modules/custom`
- `public/uploads`
- `app/storage/backups`
- `app/storage/logs`

To pozwala aktualizowac wspolny panel, Privacy Center, update client i admin UI bez nadpisywania motywow klientow, uploadow i konfiguracji.

## Co trzeba skonfigurowac przed pelna automatyzacja

- produkcyjny klucz publiczny w `app/core/Updates/trusted_keys.php`,
- prywatny klucz podpisujacy tylko po stronie Reklamova,
- endpointy `updates.reklamova.pl`,
- CRON na instalacjach klientow,
- proces budowania i publikacji paczek ZIP z GitHub.

## Minimalny update server MVP

Kod serwera jest w `update-server/`.

Wdrozenie domeny `updates.reklamova.pl`:

1. Ustaw document root na `update-server/public`.
2. Skopiuj `update-server/config.example.php` do `update-server/config.php`.
3. Skopiuj `update-server/storage/licenses.example.json` do `update-server/storage/licenses.json`.
4. Dodaj licencje instalacji klientow do `licenses.json`.
5. Wgraj paczki ZIP i `index.json` do `update-server/storage/packages`.

Endpointy MVP:

- `POST /api/v1/check-update`
- `GET /api/v1/packages/{packageId}/download`
- `POST /api/v1/report-update-started`
- `POST /api/v1/report-update-finished`
- `POST /api/v1/report-update-failed`
- `POST /api/v1/report-health`

## Klucze podpisu

Generowanie pary kluczy:

```bash
php tools/generate-update-keypair.php
```

Wynik:

- `REKLAMOVA_UPDATE_PRIVATE_KEY_B64` zostaje tylko u Reklamova, poza repo,
- `TRUSTED_PUBLIC_KEY_B64` trafia do `app/core/Updates/trusted_keys.php`.

## Budowanie paczki

```bash
REKLAMOVA_UPDATE_PRIVATE_KEY_B64=... php tools/build-update-package.php \
  --version=0.1.1 \
  --base-url=https://updates.reklamova.pl \
  --out=build/update-packages
```

Skrypt tworzy:

- `reklamova-core-0.1.1.zip`,
- `index-entry-pkg_core_0_1_1.json`.

Wpis z `index-entry-...json` nalezy dodac do:

```text
update-server/storage/packages/index.json
```

Format:

```json
{
  "packages": [
    {
      "id": "pkg_core_0_1_1",
      "type": "core",
      "version": "0.1.1",
      "channel": "stable",
      "file": "reklamova-core-0.1.1.zip",
      "url": "https://updates.reklamova.pl/api/v1/packages/pkg_core_0_1_1/download",
      "sha256": "...",
      "signature": "...",
      "signature_algorithm": "ed25519",
      "minimum_php": "8.3"
    }
  ]
}
```
