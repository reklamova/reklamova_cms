# Clean install Reklamova CMS 0.8.0-rc1

Data testu: 2026-07-28

Status: ZALICZONY

## Środowisko

- adres: `cms-rc1.reklamova.pl`,
- osobny document root i osobna baza,
- PHP CLI: 8.3.31,
- MariaDB: 10.11.18,
- kanał aktualizacji: `rc`,
- źródło artefaktu RC1: commit
  `bba5027b3c4ebfb54e26b6217098bda6b11b14f7`,
- dostęp zabezpieczony Basic Auth,
- `X-Robots-Tag` i `robots.txt` blokują indeksację,
- skrypty zewnętrzne zostały awaryjnie wyłączone.

## Instalacja i migracje

Instalator WWW zakończył się przekierowaniem do panelu. Powstało 37 tabel.
Wykonano pełny ciąg migracji core i modułów. Migracje uruchomiono ponownie dwa
razy; liczba tabel i rekordów testowych nie zmieniła się.

## Testy

| Kontrola | Wynik |
| --- | --- |
| PHP lint | ZALICZONY: 101 plików, 0 błędów |
| Logowanie `super_admin` | ZALICZONE |
| Logowanie `client_admin` | ZALICZONE |
| Logowanie `reklamova_admin` | ZALICZONE |
| Menu klienta bez technikaliów | ZALICZONE |
| Menu Reklamova z technikaliami | ZALICZONE |
| Utworzenie i publikacja podstrony | ZALICZONE |
| Publiczny adres nowej podstrony | ZALICZONY: HTTP 200 |
| Upload pliku SVG do Media | ZALICZONY |
| Privacy Center settings API | ZALICZONY |
| Zapis decyzji zgody | ZALICZONY |
| Hash IP i user-agent | ZALICZONY: 64 znaki |
| Moduły i widoczność per rola | ZALICZONE |
| `/admin/updates` klienta | ZALICZONY: prosty komunikat bez akcji technicznych |
| `/admin/system` klienta | ZALICZONY: HTTP 404 |
| `/admin/system` Reklamova | ZALICZONY: HTTP 200 |
| Update server na kanale `rc` | ZALICZONY: HTTP 200, brak nowszej wersji |

Po poprawce post-RC1 sprawdzono również pełnoekranowy fallback modułu
Business. Consent Mode default, root Privacy Center, konfiguracja skryptów i
link w stopce są wstrzykiwane dokładnie raz.

Adresy `/ustawienia-prywatnosci`, `/polityka-prywatnosci` oraz
`/api/privacy/document/polityka-prywatnosci` zwracają HTTP 200. Stary adres z
polskim znakiem pozostaje zgodnym aliasem.

## Dane kontrolne

Po testach baza zawierała:

- 3 użytkowników testowych,
- 2 podstrony,
- 1 plik Media,
- 2 testowe wpisy zgód.

Hasła testowe i Basic Auth są zapisane poza document root i nie znajdują się w
repozytorium.

## Wynik

Czysta instalacja jest gotowa do dalszych testów RC2. Nie wykryto błędów PHP
ani problemów z idempotencją migracji.

## Uwaga operacyjna 2026-07-29

Po zakończeniu powyższych testów zmieniono w panelu Hostido hasło użytkownika
bazy `host379800_staging`. Aktualna konfiguracja aplikacji nie może się
uwierzytelnić, a hasło widoczne na przekazanym zrzucie nie jest przyjmowane
przez MySQL. Nie zmienia to wyniku wykonanych testów RC1, ale bieżący staging
zwraca HTTP 500 do czasu zatwierdzenia poprawnego hasła i aktualizacji
`app/config/database.php`.
