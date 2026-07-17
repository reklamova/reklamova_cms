# Reklamova Central - instalacje CMS

Panel `/admin/installations` jest widoczny tylko dla ról wewnętrznych Reklamova:

- `super_admin`,
- `reklamova_admin`,
- `reklamova`,
- `developer`.

Zwykły klient nie widzi tej sekcji.

## Co pokazuje panel

Panel czyta dane z centralnego update servera `updates.reklamova.pl` przez API:

- `GET /api/v1/central/installations`,
- `POST /api/v1/central/module-policy`.

Endpointy są chronione tokenem `central_admin_token` po stronie update servera oraz `central_update_server_token` po stronie panelu Reklamova.

Jeżeli panel działa w tym samym katalogu roboczym co update server, może użyć fallbacku plikowego, ale nie jest to domyślny model produkcyjny na hostingu współdzielonym.

Update server przechowuje dane w plikach:

- `storage/licenses.json` - rejestr licencji i instalacji,
- `storage/packages/index.json` - najnowsze paczki core,
- `storage/reports/*.jsonl` - ostatnie checki, health i raporty aktualizacji,
- `storage/module-policies.json` - centralne polityki modułów per instalacja.

W tabeli instalacji widać domenę, wersję CMS, najnowszą wersję core, status licencji, PHP, aktywne moduły, ostatni kontakt z update serverem i informację, czy dla strony ustawiono centralną politykę modułów.

## Zarządzanie modułami strony

Ekran `/admin/installations/modules?site_id=...` zapisuje politykę modułów dla wybranej instalacji.

Polityka nie dotyka:

- motywu klienta,
- uploadów,
- `app/config`,
- modułów custom klienta.

Zmiana jest zapisywana po stronie update servera. Instalacja klienta pobiera ją przy najbliższym checku aktualizacji albo przez CRON i stosuje lokalnie tylko dla znanych modułów core/oficjalnych.

Moduły systemowe i zablokowane nie mogą zostać wyłączone centralnie.

## Wymagana konfiguracja

W `app/config/app.php` panelu centralnego Reklamova ustaw:

```php
'central_update_server_url' => 'https://updates.reklamova.pl',
'central_update_server_token' => 'sekretny-token-centralny',
```

W `updates.reklamova.pl/config.php` ustaw ten sam token:

```php
'central_admin_token' => 'sekretny-token-centralny',
```

Jeśli panel centralny działa na tym samym hostingu co update server i ma dostęp do plików, można dodatkowo ustawić:

```php
'central_update_server_path' => '/pelna/sciezka/do/updates.reklamova.pl',
```

## Model bezpieczeństwa

Lista instalacji i zarządzanie modułami są elementem panelu Reklamova, nie panelu klienta. Klient może widzieć swoje treści i proste ustawienia strony, ale nie centralny rejestr instalacji, licencje, health checki ani techniczne sterowanie core.
