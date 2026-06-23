# Reklamova CMS Architecture

## Model

Kazdy klient ma osobna instalacje:

- wlasny hosting,
- wlasna baze danych,
- wlasne pliki,
- wlasna domene,
- wlasne uploady,
- wlasny motyw.

Reklamova centralnie utrzymuje:

- core CMS,
- repozytorium kodu,
- paczki aktualizacji,
- update server,
- license server,
- panel centralny.

## Podzial odpowiedzialnosci

Core:

- routing,
- admin,
- auth,
- content,
- media,
- modules,
- themes,
- updates,
- backup,
- health check.

Themes:

- szablony klienta,
- assety klienta,
- ustawienia wygladu,
- brak modyfikacji core.

Modules:

- funkcje dodatkowe,
- aktywacja per instalacja,
- migracje modulowe,
- brak patchowania core.

Config:

- sekrety,
- polaczenie z baza,
- site key,
- ustawienia lokalne.

Uploads:

- media klienta,
- nigdy nienadpisywane przez update core.

Storage:

- cache,
- logi,
- backupy,
- staging aktualizacji.

## Zasada najwazniejsza

Core CMS nie moze byc modyfikowany dla pojedynczego klienta.

Kazda customizacja musi przejsc przez:

- motyw,
- modul,
- konfiguracje,
- publiczne extension points.

