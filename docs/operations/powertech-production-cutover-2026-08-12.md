# Przełączenie PowerTech na domenę produkcyjną

Data: 2026-08-12

Status: **ZAKOŃCZONE I ZWERYFIKOWANE**

## Wynik

Nowy CMS Reklamova 0.8.0 działa pod adresem `https://powertechsc.pl` na PHP
8.3.33. Domena robocza `https://nowastrona.powertechsc.pl` oraz wariant z `www`
zwracają stałe przekierowanie 301 do domeny kanonicznej.

Instalacja produkcyjna korzysta z motywu `powertech` i modułów `catalog`,
`leads`, `privacy` oraz `powertech`. Konfiguracja aplikacji, licencji, rekordy
kanoniczne i mapa strony wskazują wyłącznie domenę produkcyjną.

## Powód izolacji starej strony

Poprzednia strona była zainfekowanym WordPressem i nie została opublikowana pod
innym adresem. Aktywny wcześniej `wp-blog-header.php` zawierał kod zakodowany w
Base64 oraz słowa związane ze spamem kasynowym. W bazie starej strony było
17 308 wpisów, z czego 15 221 pasowało do sygnatur spamu kasynowego.

Pełne drzewo starego WordPressa ma około 2,4 GB i 40 141 plików. Zostało
atomowo przeniesione poza `public_html` do prywatnej kwarantanny. Nie należy go
uruchamiać ani wystawiać przez HTTP; służy wyłącznie jako materiał dowodowy i
źródło do ewentualnego, selektywnego odzyskiwania treści.

Dodatkowo z `public_html` usunięto przez odwracalne przeniesienie historyczny
katalog `_old` (około 344 MB i 26 368 plików). Zawierał między innymi stary
`xmlrpc.php`, plik `_wp-feed-reactor.php` oraz `.user.ini` wymuszający
`auto_prepend_file`. Katalog nie obsługiwał żadnej aktywnej domeny.

## Backupy i kwarantanna

Katalog operacji:

```text
/home/platne/serwer38522/backups/powertech-cutover-20260812_110931
```

Zawiera:

- `new-cms-files-pre-cutover.tar.gz` — pliki CMS przed przełączeniem,
- `new-cms-database-pre-cutover.sql.gz` — baza CMS przed przełączeniem,
- `old-wordpress-database.sql.gz` — baza zainfekowanego WordPressa,
- `quarantine-old-wordpress-live-tree` — kompletne stare drzewo WordPressa,
- `quarantine-legacy-public-html-_old` — historyczny katalog `_old`,
- `archived-one-time-powertech-tools` — jednorazowe skrypty wdrożeniowe,
- `SHA256SUMS.txt` — sumy kontrolne trzech archiwów.

Katalog operacji i obie kwarantanny mają prawa `700`. Sprawdzono sumy SHA-256
oraz integralność wszystkich trzech archiwów gzip.

## Walidacja produkcji

- strony CMS: 21,
- produkty: 104,
- kategorie: 69,
- adresy kanoniczne domeny produkcyjnej: 10,
- adresy kanoniczne domeny roboczej: 0,
- adresy produkcyjne w `sitemap.xml`: 143,
- odwołania do domeny roboczej w aktywnym runtime: 0,
- wpisy stron lub produktów pasujące do sygnatur spamu: 0,
- wykonywalne pliki w uploadach: 0,
- pliki WordPressa w produkcyjnym drzewie CMS: 0,
- błędy składni PHP: 0,
- wpisy `Fatal`, `Uncaught` lub `Parse error` w logach po przełączeniu: 0,
- API aktualizacji: HTTP 200, brak oczekującej aktualizacji dla 0.8.0.

Zweryfikowano kod HTTP 200 dla strony głównej, O nas, Oferty, Kontaktu, panelu,
favicony, CSS, JavaScriptu, mapy strony i `robots.txt`. Nieistniejąca ścieżka
zwraca 404. Panel logowania, katalog Rockwell i wyszukiwarka produktów działają;
wyszukiwanie „suwmiarka” zwróciło osiem wyników z domeny produkcyjnej. Konsola
przeglądarki nie zgłosiła błędów, a obrazy na kontrolowanych podstronach były
poprawnie załadowane.

Nagłówki produkcyjne obejmują HSTS, CSP, `X-Content-Type-Options`,
`X-Frame-Options`, `Referrer-Policy` i `Permissions-Policy`. Staging nadal jest
chroniony Basic Auth i wyłączony z indeksowania.

## Rollback

Rollback CMS należy wykonywać wyłącznie w oknie serwisowym:

1. Utworzyć aktualny backup produkcyjnych plików i bazy.
2. Odtworzyć pliki z `new-cms-files-pre-cutover.tar.gz` do nowego, prywatnego
   katalogu i bazę z `new-cms-database-pre-cutover.sql.gz` do osobnej bazy.
3. Zweryfikować konfigurację domeny, licencję, połączenie z bazą i sumy plików.
4. Przełączyć katalogi atomowo i ponownie wykonać testy HTTP oraz PHP.

Zainfekowanego WordPressa ani katalogu `_old` nie wolno używać jako rollbacku
publicznej strony. Odzyskiwanie z nich może dotyczyć tylko zweryfikowanych danych
lub mediów, nigdy kodu wykonywalnego.

## Dalsze działania bezpieczeństwa

- Zmienić hasła hostingu, FTP/SSH i baz starego WordPressa, ponieważ były używane
  przez skompromitowaną instalację lub przekazane kanałem rozmowy.
- Po ustaleniu okresu retencji usunąć starego użytkownika bazy WordPressa oraz
  obie kwarantanny; wcześniej zachować wymagany materiał dowodowy offline.
- Włączyć separację katalogów w LH po kontrolowanym teście obu aktywnych stron.
- Przeprowadzić osobny, pełny audyt WordPressa `sunpoc.pl`. Ograniczony skan
  sygnaturowy nie wykazał markerów wysokiego ryzyka, ale ta strona współdzieli
  konto systemowe i nie była przedmiotem obecnej migracji.

Żadne dane dostępowe ani sekrety nie są zapisane w repozytorium Git.
