# PowerTech staging Reklamova CMS 0.8.0-rc1

Data testu: 2026-07-28

Status: PRZYGOTOWANY PLIKOWO, ZABLOKOWANY PRZEZ BRAK BAZY

## Środowisko

- adres: `staging.powertechsc.pl`,
- staging root:
  `/home/platne/serwer38522/public_html/staging.powertechsc.pl`,
- PHP CLI: 8.3.30,
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

## Blokada bazy

Na przekazanym zrzucie panel LH nadal pokazywał formularz z przyciskiem
`UTWÓRZ`. Serwer MySQL nie rozpoznaje użytkownika `serwer38522_staging`, a
produkcyjny użytkownik widzi wyłącznie własną bazę. Oznacza to, że stagingowa
baza nie została faktycznie utworzona.

W `app/config/database.php` stagingu celowo pozostawiono bezpieczny placeholder
hasła. Dzięki temu staging nie może przypadkowo połączyć się z produkcją.

## Testy oczekujące

- import kopii bazy PowerTech,
- pełne migracje na kopii,
- logowanie `client_admin` i `reklamova_admin`,
- menu obu ról,
- Podstrony, Strona główna i Media,
- lista, filtrowanie, edycja i powielenie produktu,
- drzewo kategorii i karta produktu,
- Privacy Center,
- `/admin/updates` i `/admin/system`,
- publiczny front i log PHP.

## Następny krok

W panelu LH trzeba dokończyć operację `UTWÓRZ` dla bazy i przekazać ekran
potwierdzający utworzenie. Po tym można wstawić hasło do stagingowej
konfiguracji, wykonać dump/import, migracje i pełną checklistę bez ponownego
kopiowania plików.
