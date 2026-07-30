# Finalne porządki po Reklamova CMS 0.8.0

Data: 2026-07-30

Zakres obejmował repozytorium, lokalny workspace, update server, produkcję MERO,
publiczną kontrolę PowerTech i trzy środowiska stagingowe. Nie utworzono nowego
RC, nie zmieniono wersji CMS, nie przebudowano stable i nie zmieniono
funkcjonalnie produkcji.

## 1. Main i tagi

- commit bazowy `main` na początku audytu:
  `53b23251fcad951a1b2aab3f209e205dbb098f8a`,
- lokalny `main` i `origin/main` były zgodne,
- zachowane tagi:
  `v0.7.0`, `v0.7.1`, `v0.7.3`, `v0.7.4`, `v0.7.5`, `v0.8.0`,
  `mero-frontend-privacy-0.8.0-1`.

## 2. Branche

Po pozytywnym `git merge-base --is-ancestor <branch> main` i potwierdzeniu
braku unikalnych commitów usunięto lokalnie i zdalnie:

- `feature/client-panel-information-architecture`,
- `feature/mero-frontend-privacy-bridge`.

Zachowano:

- `main`,
- niescalony `origin/feature/admin-ux-modules-safe-updates`,
- wszystkie tagi release.

Po operacji wykonano `git fetch --prune`.

## 3. Lokalny workspace

Usunięto roboczy katalog `build` zawierający rozpakowane paczki, logi i
artefakty testów Chromium. Przed usunięciem finalne paczki przeniesiono do
`releases/0.8.0`, a RC2-RC4 do `archives/0.8.0-rc`.

Usunięto również jednorazowe helpery audytowe utworzone w `temp`. Nie znaleziono
drugiej kopii repozytorium wewnątrz aktualnego workspace.

Odzyskane miejsce: `64 475 169` bajtów, około `61,5 MiB`.

## 4. Finalne artefakty

Plik `releases/0.8.0/SHA256SUMS.txt` zawiera:

- `reklamova-core-0.8.0.zip`:
  `2ac6b1d2ca490ca082859dc5f53300b23432ba1e8fededde739fd6f173780e30`,
- `mero-admin-0.8.0-1.zip`:
  `4cf9f8937f9f3dd88fd62e05f28693d4fd2959847abd62ac62bb748baee9be9d`,
- `mero-frontend-privacy-0.8.0-1.zip`:
  `f343ddfb87079c7b99d913c3e9fb06b2bd2475d38fb9205219f581afe9c6dbab`.

Sumy zostały ponownie obliczone po przeniesieniu i są zgodne.

## 5. Archiwum RC i dokumentacja

Zachowano lokalne paczki RC2-RC4 w `archives/0.8.0-rc`. Nie było lokalnej
paczki RC1 do przeniesienia.

Raporty RC1-RC4, checklisty stagingowe, testy lint, migracji i paczek oraz stare
plany przeniesiono do `docs/archive/0.8.0-rc`. Linki wewnętrzne poprawiono.

W `docs/releases` pozostają dokumenty stable, produkcyjnego rolloutu, patchy
MERO oraz otwarty plan kwarantanny modułu PowerTech. Dodano indeks
`docs/releases/README.md`.

## 6. Update server

- stable: `0.8.0`,
- package ID: `pkg_core_0_8_0`,
- stable SHA-256:
  `2ac6b1d2ca490ca082859dc5f53300b23432ba1e8fededde739fd6f173780e30`,
- RC: `0.8.0-rc4`,
- RC package ID: `pkg_core_0_8_0_rc4`,
- RC4 SHA-256:
  `e1e99c9319c638de8ad820523630046271e74ac633db9d7952e1f4c7c1b1adb1`.

Obie paczki przeszły ponowną kontrolę checksumy, manifestu i podpisu Ed25519:

- stable: `PACKAGE_VERIFIED version=0.8.0 files=168`,
- RC4: `PACKAGE_VERIFIED version=0.8.0-rc4 files=165`.

Aktywny `index.json` nie zmienił sumy podczas porządków. Kanał RC nie wpływa na
instalacje stable.

Prywatny klucz podpisujący ma tryb `600`, znajduje się poza `public_html` i nie
jest obecny w repozytorium ani publicznym katalogu serwera.

Czternaście starych snapshotów indeksu i licencji przeniesiono do prywatnego
archiwum:

`private-archive/cleanup-0.8.0-20260730/update-server-metadata`.

Zapisano ich oryginalne ścieżki i SHA-256. Retencja archiwum trwa co najmniej do
2026-08-29. Nie usunięto żadnej paczki wskazywanej przez aktywne metadata.

## 7. PowerTech

Publiczny smoke test 2026-07-30:

- strona główna, O nas, Oferta, Kontakt i panel logowania: HTTP 200,
- nieistniejąca trasa: HTTP 404,
- Privacy Center: asset dołączony jeden raz,
- publiczne markery MERO: brak,
- konfiguracja, backupy i private storage: niedostępne przez URL,
- błędy HTTP 500 w sprawdzonych trasach: brak.

Ostatni pełny test po wdrożeniu 0.8.0 potwierdził:

- strony: 21,
- media: 1438,
- produkty: 104,
- kategorie: 69,
- działanie produktów, kategorii, Media, Powiel produkt i Privacy Center.

Centralny update server ma zapis zakończonej aktualizacji PowerTech z
2026-07-30T12:09:59+02:00. Nie wykonano nowej aktualizacji.

## 8. Osierocony moduł MERO w PowerTech

Katalog `app/modules/custom/mero` nie został usunięty ani przeniesiony na
produkcji. Dostęp SSH do hostingu LH wymaga poświadczenia, którego nie znaleziono
w repozytorium, agencie ani zapisanych sesjach. Bez odczytu konfiguracji,
`cms_modules`, motywu, tras i logów nie wolno uznać modułu za osierocony.

Stagingowe archiwum istnieje poza document root zgodnie z:
[`powertech-custom-cleanup-plan.md`](../releases/powertech-custom-cleanup-plan.md).

Po odzyskaniu autoryzowanego dostępu należy wykonać pełny audyt, świeży backup,
manifest SHA-256, kwarantannę i smoke test. Do tego czasu katalog pozostaje w
aktywnej ścieżce z udokumentowaną przyczyną.

## 9. MERO

Audyt produkcyjny:

- core: `0.8.0`,
- strony: 28,
- media: 31,
- użytkownicy: 1,
- konta testowe: 0,
- Poradnik: 6 wpisów,
- leady: 0,
- leady testowe: 0,
- prywatne uploady projektu: 0,
- aktywne skrypty Privacy Center: 0,
- nowe błędy runtime w logach: 0.

Hash `app/modules/custom/mero/public.php` jest zgodny z wdrożonym patchem:
`100e0242e93392525c430896b6ff293768883d2513bc7ccbde3d5ff6170d7062`.

Smoke test potwierdził stronę główną, O firmie, Kontakt, Poradnik, artykuł,
Kalkulator i 404. Kontakt ma jeden formularz, pole uploadu, snapshot zgody,
dane/mapę i jeden asset Privacy Center. Historyczny klucz localStorage występuje
wyłącznie w instrukcji usuwającej go. Trasy admina wymagają logowania.

Chronione katalogi i private storage zwracają 404. Nie usunięto anonimowych
logów zgód i nie zmieniono core, modułu custom, motywu, konfiguracji ani bazy.

## 10. Stagingi

Wszystkie trzy domeny zwracają bez autoryzacji HTTP 401 oraz:

`X-Robots-Tag: noindex, nofollow, noarchive`.

Szczegóły są w
[`staging-environments.md`](./staging-environments.md).

- `cms-rc1.reklamova.pl`: stały staging, kanał zmieniony z `stable` na `rc`,
  brak trackerów, realna poczta zablokowana,
- `staging.mero.pl`: kanał `rc`, brak trackerów, realna poczta core i formularza
  zablokowana po backupie i lincie PHP 8.5,
- `staging.powertechsc.pl`: zachowany; Basic Auth/noindex aktywne, bieżąca
  kontrola serwerowa oczekuje na dostęp SSH LH.

Najwcześniejszy termin przeglądu stagingów klientów pod kątem archiwizacji:
2026-08-06. Stały staging CMS nie podlega usunięciu.

## 11. Backupy i retencja

MERO - potwierdzone jako obecne:

- `bkp_20260730_123237`,
- `mero_admin_0_8_0_1_20260730_131708`,
- `mero_frontend_privacy_0_8_0_1_prod_20260730_140116`,
- `bkp_20260730_140117`.

PowerTech - zachowanie wymagane i potwierdzone w raporcie rolloutu:

- `bkp_20260730_120958`,
- `asset_bridge_0_8_0_20260730_122755`.

Bieżąca obecność backupów PowerTech wymaga ponownej kontroli po odzyskaniu SSH.
Nie usunięto żadnego backupu produkcyjnego podczas tego zadania.

Polityka retencji:

- dzienne: 14 dni,
- tygodniowe: 8 tygodni,
- przed aktualizacją: 90 dni,
- finalne artefakty release: bezterminowo albo do następnego pełnego release.

Wymienione backupy wdrożeniowe należy zachować co najmniej do 2026-10-28.

## 12. Bezpieczeństwo

W aktualnym drzewie i historii Git nie znaleziono:

- haseł baz i produkcyjnych konfiguracji,
- kluczy prywatnych,
- tokenów API według skanowanych wzorców,
- danych FTP/SFTP,
- dumpów SQL,
- paczek ZIP ani logów śledzonych przez Git.

Śledzone `.gitkeep` w katalogach backupów, logów i uploadów są pustymi
placeholderami, nie artefaktami produkcyjnymi.

`.gitignore` rozszerzono o konfiguracje lokalne, prywatne storage, dumpy,
archiwa, klucze, poświadczenia, katalogi IDE i tymczasowe buildy.

**Ostrzeżenie:** repozytorium `reklamova/reklamova_cms` ma widoczność `public`.
Nie zmieniono jej automatycznie. Ze względu na autorski charakter CMS warto
świadomie ocenić zmianę na repozytorium prywatne.

## 13. Świadomie zachowane elementy

- wszystkie tagi release,
- niescalony branch `feature/admin-ux-modules-safe-updates`,
- wszystkie aktywne paczki update servera, w tym RC4,
- starsze pakiety wskazywane przez aktywny indeks,
- finalne paczki stable i patche MERO,
- wymagane backupy produkcyjne,
- cały potrzebny moduł `app/modules/custom/mero` na MERO,
- katalog MERO na PowerTech do czasu pełnego audytu zależności,
- wszystkie trzy stagingi i ich osobne bazy,
- anonimowe logi zgód Privacy Center.

## 14. Otwarte zadania

1. Uzyskać autoryzowany dostęp SSH do hostingu LH PowerTech.
2. Zweryfikować na żywo wymagane backupy PowerTech i logi PHP.
3. Wykonać procedurę kwarantanny `app/modules/custom/mero` dopiero po
   potwierdzeniu braku zależności.
4. Ponownie potwierdzić blokadę maili na stagingu PowerTech.
5. Rozważyć zmianę widoczności repozytorium GitHub z `public` na `private`.

Brak blockerów dla działania stable 0.8.0, produkcji MERO i publicznej części
PowerTech. Jedyny blocker porządkowy dotyczy serwerowej kwarantanny modułu
PowerTech.
