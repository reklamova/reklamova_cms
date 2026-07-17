# Reklamova Privacy Center

Reklamova Privacy Center jest systemowym modulem core CMS, aktywnym domyslnie dla kazdej instalacji klienta. Nie jest zwyklym popupem cookies: laczy baner, centrum preferencji, zgody, dokumenty, skrypty zewnetrzne, rejestr cookies, klauzule formularzy i audyt.

## Zakres MVP

- Panel `/admin/privacy` z dashboardem, ustawieniami banera, kategoriami, skryptami, dokumentami, formularzami, rejestrem cookies i logami.
- Publiczne API:
  - `GET /api/privacy/settings`
  - `POST /api/privacy/consent`
  - `GET /api/privacy/scripts`
  - `GET /api/privacy/document/{slug}`
- Publiczne dokumenty:
  - `/polityka-prywatnosci`
  - `/polityka-cookies`
  - `/ustawienia-prywatnosci`
- Hooki motywu:
  - `privacy_head()`
  - `privacy_body_start()`
  - `privacy_body_end()`
  - `privacy_footer_link()`

## Zasady core

Modul jest czescia core CMS. Motywy klienta, uploady i konfiguracja instalacji nie sa nadpisywane przez update core. Customizacja klienta ma isc przez ustawienia, motyw lub modul custom, nie przez edycje core Privacy Center.

## Domyslne dane po migracji

Migracja tworzy kategorie:

- necessary
- analytics
- marketing
- functional
- personalization

Tworzy tez szkice dokumentow, podstawowe klauzule formularzowe i ustawienia banera po polsku.

## Bezpieczenstwo i prywatnosc

- Kategorie inne niz niezbedne sa domyslnie wylaczone.
- Przycisk odrzucenia jest widoczny i rownorzedny.
- Skrypty custom sa escapowane w panelu i nie sa wykonywane w adminie.
- Kod PHP w skryptach jest blokowany.
- Decyzje uzytkownikow sa logowane z hashem IP i user-agent, bez jawnego IP.
- Awaryjne wylaczenie skryptow blokuje publiczne ladowanie aktywnych skryptow zewnetrznych.

## TODO po MVP

- Pelny system ACL dla uprawnien `manage_privacy_*` w core auth.
- Wizard presetow z polami specyficznymi dla kazdej integracji.
- Retencja logow jako zadanie cron.
- Bardziej rozbudowany podglad skryptow dzialajacych na konkretnej podstronie.
