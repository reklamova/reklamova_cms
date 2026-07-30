# MERO frontend privacy 0.8.0-1

Status: przetestowany wyłącznie na stagingu, bez zgody na produkcję

Data testu: 2026-07-30

Branch: `feature/mero-frontend-privacy-bridge`

## Źródło prawdy

Podstawą merge był przywrócony plik pobrany bezpośrednio z produkcji MERO:

- produkcyjny `public.php`: `1fdd0a1e8e0c4905570670126458dc9be300ed47fb4b96eccb14730abc91deb8`
- kandydat stagingowy: `100e0242e93392525c430896b6ff293768883d2513bc7ccbde3d5ff6170d7062`
- paczka staging-only: `f343ddfb87079c7b99d913c3e9fb06b2bd2475d38fb9205219f581afe9c6dbab`

## Zakres

Kandydat zmienia wyłącznie:

- `app/modules/custom/mero/public.php`

Zmiana:

- zachowuje produkcyjny `frontend.php` i jego renderer,
- zachowuje istniejący formularz kontaktowy i routing `/kontakt`,
- nie wymaga markera `<div data-mero-contact></div>`,
- obsługuje tylko jawnie znane trasy MERO,
- nie przejmuje zwykłych podstron generycznym fallbackiem,
- usuwa historyczny popup i storage zgód MERO,
- kieruje istniejące przyciski ustawień do Reklamova Privacy Center,
- zapisuje snapshot formularza na podstawie `window.ReklamovaConsent`,
- nie uruchamia migracji i nie zmienia bazy danych.

## Test stagingowy

Sprawdzono:

- `/`, `/o-firmie`, `/poradnik`, `/kalkulator-budowy-domu` i `/kontakt`,
- nieistniejąca trasa zwraca 404 i nie jest przejmowana przez moduł,
- istniejący formularz ma niezmieniony zestaw pól,
- rzeczywiste wysłanie formularza i zapis snapshotu zgody w bazie,
- usunięcie danych testowych po weryfikacji,
- jedna instancja Privacy Center,
- brak historycznego popupu i jego kluczy localStorage,
- trwałość odrzucenia po przeładowaniu,
- link „Ustawienia cookies” otwiera Privacy Center,
- brak skryptów opcjonalnych przed decyzją,
- mapa ładuje się po zgodzie funkcjonalnej,
- kalkulator produkcyjny działa,
- ekrany Zapytania, Poradnik i Kalkulator działają dla `client_admin`
  i `reklamova_admin`,
- brak nowych błędów PHP i JavaScript.

Backup stagingowy:

`app/storage/backups/mero_frontend_privacy_0_8_0_1_staging_20260730_133144`

## Ograniczenie wdrożenia

Tego patcha nie wolno wdrażać na produkcji bez osobnej zgody. Nie jest częścią
core 0.8.0 ani kanału stable.
