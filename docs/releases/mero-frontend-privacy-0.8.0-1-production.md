# MERO frontend privacy 0.8.0-1 - produkcja

Status: wdrożony i zweryfikowany na produkcji MERO

Data wdrożenia: 2026-07-30

Branch źródłowy: `feature/mero-frontend-privacy-bridge`

## Zakres

Wdrożono dokładnie artefakt przetestowany wcześniej na stagingu:

- paczka: `mero-frontend-privacy-0.8.0-1.zip`
- SHA-256 paczki: `f343ddfb87079c7b99d913c3e9fb06b2bd2475d38fb9205219f581afe9c6dbab`
- zmieniony plik: `app/modules/custom/mero/public.php`
- SHA-256 przed zmianą: `1fdd0a1e8e0c4905570670126458dc9be300ed47fb4b96eccb14730abc91deb8`
- SHA-256 po zmianie: `100e0242e93392525c430896b6ff293768883d2513bc7ccbde3d5ff6170d7062`

Nie zmieniono `admin.php`, `module.json`, migracji, konfiguracji, motywu,
uploadów, core Reklamova CMS, kanału stable ani update servera. PowerTech
i pozostałe instalacje nie zostały zmodyfikowane.

## Backup i wdrożenie

Przed zmianą wykonano:

- kopię źródłowego `public.php`:
  `app/storage/backups/mero_frontend_privacy_0_8_0_1_prod_20260730_140116`,
- pełny backup przed aktualizacją: `bkp_20260730_140117`,
- backup bazy metodą `mysqldump`, skompresowany i sprawdzony:
  `4c267f9afc1fdd7b4491b2268b3fa6c324510cebc735e2add65dd140e7abe4dc`,
- poprawny test integralności archiwum bazy i archiwum plików core.

Paczka została rozpakowana poza katalogiem publicznym. Plik tymczasowy
przeszedł `php -l`, po czym został podmieniony atomowo. Maintenance mode
trwał około 1,8 sekundy i został poprawnie wyłączony. Cache został
wyczyszczony.

## Smoke test produkcyjny

Test automatyczny w prawdziwej przeglądarce zakończył się wynikiem pozytywnym:

- strona główna, `/o-firmie`, `/kontakt`, `/poradnik`,
  `/kalkulator-budowy-domu` i artykuł Poradnika zwracają HTTP 200,
- nieistniejąca trasa zwraca HTTP 404 i nie jest przejmowana przez moduł,
- formularz kontaktowy jest widoczny i ma niezmieniony zestaw pól,
- pole załącznika, dane kontaktowe i mapa są obecne,
- walidacja niepełnego formularza zwraca kontrolowane HTTP 422,
- testowe wysłanie formularza utworzyło poprawny snapshot
  `window.ReklamovaConsent`,
- plik testowy został zapisany w prywatnym storage,
- próby dostępu do pliku trzema publicznymi URL-ami zwróciły HTTP 404,
- rekord testowy i prywatny plik testowy zostały usunięte po weryfikacji,
- historyczny popup i klucz `mero_cookie_consent_v1` nie występują,
- działa dokładnie jedna instancja Reklamova Privacy Center,
- odrzucenie, akceptacja i wybór szczegółowy są trwałe po odświeżeniu,
- przycisk ustawień cookies otwiera Reklamova Privacy Center,
- mapa ładuje się dopiero po zgodzie funkcjonalnej,
- opcjonalne skrypty nie uruchamiają się przed decyzją,
- Kalkulator, Zapytania i Poradnik działają dla `client_admin`
  i `reklamova_admin`.

W bazie po usunięciu danych formularza pozostało osiem anonimowych zdarzeń
decyzji Privacy Center utworzonych przez test trwałości zgód. Nie zawierają
danych formularza ani uploadu.

## Integralność i logi

Po wdrożeniu:

- `php -l app/modules/custom/mero/public.php` przechodzi,
- wersja Reklamova CMS nadal wynosi `0.8.0`,
- liczba stron: 28 przed i 28 po,
- liczba mediów: 31 przed i 31 po,
- liczba artykułów MERO: 6 przed i 6 po,
- liczba zapytań MERO: 0 przed i 0 po uprzątnięciu testu,
- checksum konfiguracji nie zmienił się,
- checksum motywu nie zmienił się,
- `admin.php` zachował hash
  `bf0380929ae04e1fbc7992e45c17d333761e2df74144a0180eb792759c5cdfc8`,
- `module.json` zachował hash
  `69dfb7ef4deb1ab944a1aef55d5f1bde2583a2b79c5cd2c55e4390e27529b9ce`,
- maintenance mode jest wyłączony,
- brak nowych błędów PHP, JavaScript i odpowiedzi HTTP 500.

Ostrzeżenia testu ograniczyły się do kontrolowanej trasy 404, istniejącego
braku `/favicon.ico` oraz celowego HTTP 422 z testu walidacji formularza.

Rollback nie był potrzebny.
