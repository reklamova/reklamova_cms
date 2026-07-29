# PowerTech staging Reklamova CMS 0.8.0-rc1

Data testu: 2026-07-28, aktualizacja: 2026-07-29

Status: ZALICZONY TECHNICZNIE DLA RC1, FUNKCJA POWIELANIA WYMAGA RC2

## Środowisko

- adres: `staging.powertechsc.pl`,
- staging root:
  `/home/platne/serwer38522/public_html/staging.powertechsc.pl`,
- PHP CLI i WWW: 8.3.30,
- osobna baza stagingowa: 37 tabel,
- kanał aktualizacji: `rc`,
- osobna licencja stagingowa działa z update API,
- Basic Auth zwraca HTTP 401 bez uwierzytelnienia,
- `noindex` i `robots.txt` blokują indeksację,
- konfiguracja poczty jest wyłączona i kieruje wyłącznie do domeny `.invalid`.

## Klon plików i ochrona produkcji

Pliki skopiowano z `nowastrona.powertechsc.pl` do niezależnego katalogu
stagingowego. Kopia ma około 222 MB i własne uploady. Konfigurację stagingu
zmieniono przed udostępnieniem aplikacji, więc nie może połączyć się z
produkcyjną bazą.

Produkcja pozostała bez zmian. Sprawdzono jej adres konfiguracyjny po
klonowaniu.

Core wdrożono wyłącznie z allowlisty. Walidacja paczki:

- wersja: `0.8.0-rc1`,
- kanał: `rc`,
- 18 ścieżek core,
- chronione ścieżki stabilne,
- PHP lint: 105 plików, 0 błędów.

Kopia produkcyjna zawierała obcy katalog `app/modules/custom/mero`. Na
stagingu został zarchiwizowany poza document root i wykluczony. Aktualny wynik:
0 plików MERO w PowerTech.

## Update server

Licencję `staging.powertechsc.pl` dodano wyłącznie do kanału `rc`. Bezpośredni
test API z hostingu PowerTech zwrócił:

- HTTP 200,
- aktualna wersja: `0.8.0-rc1`,
- najnowsza wersja: `0.8.0-rc1`,
- brak `invalid_license`,
- brak dostępnej nowszej wersji.

## Baza i migracje

Utworzono osobną bazę `serwer38522_staging`. Dump produkcyjnej bazy wykonano
wyłącznie do odczytu, skompresowano, sprawdzono przez `gzip -t` i zapisano poza
document root razem z sumą SHA-256. Następnie:

- zaimportowano 37 tabel do stagingu,
- wykonano migracje core i aktywnych modułów,
- zapisano 17 wykonanych migracji,
- potwierdzono 21 podstron, 1438 mediów, 104 produkty i 69 kategorii,
- konfiguracja stagingu wskazuje wyłącznie bazę stagingową.

Backup importu:
`/home/platne/serwer38522/staging-backups/powertech-rc1/20260729-093349`.

## Test panelu i frontu

HTTP 200 potwierdzono dla:

- strony głównej, O nas, oferty i kontaktu,
- `/admin/login`,
- `/admin/pages`,
- `/admin/media`,
- `/admin/catalog/products`,
- `/admin/catalog/categories`,
- `/admin/privacy`,
- `/api/privacy/settings`,
- `/ustawienia-prywatnosci`.

Basic Auth zwraca HTTP 401 bez danych logowania, a plik z hashem jest chroniony
przed pobraniem przez HTTP 403.

`client_admin` loguje się i nie widzi technicznego menu. `reklamova_admin`
widzi Moduły strony, Aktualizacje CMS i Stan systemu. Test połączenia z update
serverem na kanale `rc` zwraca HTTP 200 bez błędu licencji.

## Znaleziona różnica RC1

Artefakt RC1 nie zawiera jeszcze dodanej później na branchu akcji `Powiel`.
Lista produktów w RC1 działa, ale nie pokazuje tego przycisku. Aktualny branch
zawiera `duplicateProduct()` i formularz `Powiel`, dlatego funkcję trzeba
ponownie wdrożyć i przetestować dopiero w jednoznacznie zbudowanym RC2.

## Pozostałe testy

- przeglądarkowa kontrola wizualna długiej listy produktów i drzewa kategorii,
- powielenie produktu oraz usunięcie kopii na RC2,
- regresja formularzy bez wysyłania wiadomości do prawdziwych odbiorców.
