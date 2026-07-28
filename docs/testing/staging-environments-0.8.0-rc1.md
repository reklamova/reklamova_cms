# Środowiska stagingowe Reklamova CMS 0.8.0-rc1

Data rozpoznania: 2026-07-28

Branch: `feature/client-panel-information-architecture`

Commit RC1: `403534fd0afd1d4261415a0f7446321085f59b03`

Kanał aktualizacji: `rc`

## Zasady bezwzględne

- nie używamy produkcyjnej bazy ani produkcyjnych uploadów,
- nie kopiujemy konfiguracji licencyjnej bez zmiany identyfikatora instalacji,
- nie wysyłamy prawdziwych wiadomości e-mail,
- nie uruchamiamy produkcyjnych trackingów,
- blokujemy indeksację i dostęp anonimowy,
- nie publikujemy RC1 w kanale `stable`,
- nie dotykamy produkcji MERO ani PowerTech.

## Stan infrastruktury

| Środowisko | Docelowy adres | DNS | HTTP | Oddzielna baza | Status |
| --- | --- | --- | --- | --- | --- |
| Clean CMS | `cms-rc1.reklamova.pl` | brak rekordu | niedostępny | nieutworzona | BLOKADA |
| MERO | `staging.mero.pl` | działa | 404 | niepotwierdzona | BLOKADA |
| PowerTech | `staging.powertechsc.pl` | działa | 404 | niepotwierdzona | BLOKADA |

Kod 404 na domenach stagingowych oznacza, że DNS wskazuje hosting, ale nie ma
jeszcze poprawnie przypisanego katalogu z aplikacją. Nie jest to wynik testu
CMS.

Na hostingu Reklamova dostępny przez SSH użytkownik bazy ma uprawnienia tylko
do istniejącej bazy CMS. Nie wolno użyć jej do clean install. Nową bazę i
użytkownika trzeba utworzyć w panelu hostingu.

## Clean CMS staging

Potrzebne zasoby:

- subdomena `cms-rc1.reklamova.pl`,
- pusty katalog niezależny od `cms.reklamova.pl`,
- osobna pusta baza i osobny użytkownik,
- PHP 8.3 lub 8.4 z `pdo_mysql`, `mbstring`, `json`, `fileinfo`, `curl`,
  `zip`, `openssl` i `sodium`,
- pliki dokładnie z commita RC1,
- osobny `site_id` i `site_key`,
- kanał `rc` w `reklamova.json` oraz w rekordzie licencji update servera.

Nie wolno kopiować danych ani konfiguracji z centralnego CMS. To ma być
instalacja od zera przez instalator.

## MERO staging

Potrzebne zasoby:

- katalog przypisany wyłącznie do `staging.mero.pl`,
- kopia plików MERO wykonana po backupie produkcji,
- nowa baza stagingowa z importem kopii bazy MERO,
- niezależna kopia `public/uploads`, bez dowiązania do produkcji,
- core RC1 wdrożony z allowlisty, bez `app/modules/custom/**`,
- osobny patch `app/modules/custom/mero` opisany w
  `docs/releases/mero-custom-patch-0.8.0.md`,
- osobna licencja stagingowa z kanałem `rc` po stronie update servera.

## PowerTech staging

Potrzebne zasoby:

- katalog przypisany wyłącznie do `staging.powertechsc.pl`,
- kopia plików PowerTech wykonana po backupie produkcji,
- nowa baza stagingowa z importem kopii bazy PowerTech,
- niezależna kopia `public/uploads`, bez dowiązania do produkcji,
- core RC1 wdrożony z allowlisty,
- brak jakichkolwiek plików patcha MERO,
- osobna licencja stagingowa z kanałem `rc` po stronie update servera.

## Bramka rozpoczęcia testów

Każde środowisko można oznaczyć jako GOTOWE DO TESTU dopiero po potwierdzeniu:

1. `realpath` katalogu stagingowego nie wskazuje produkcji.
2. Nazwa bazy stagingowej różni się od produkcyjnej.
3. Konto bazy stagingowej nie ma uprawnień do bazy produkcyjnej.
4. `public/uploads` jest fizyczną kopią, nie symlinkiem.
5. działa Basic Auth albo równoważna blokada dostępu.
6. odpowiedź zawiera `X-Robots-Tag: noindex, nofollow, noarchive`.
7. wysyłka e-mail jest zablokowana na poziomie hostingu/PHP.
8. `emergency_disable_external_scripts` ma wartość `true`.
9. konfiguracja wskazuje stagingową domenę i stagingową bazę.
10. wykonano backup oraz zapisano manifest z datą, źródłem i sumami plików.

## Dalsza kolejność

1. Administrator hostingu tworzy trzy katalogi/domeny i trzy osobne bazy.
2. Reklamova zabezpiecza środowiska zgodnie z instrukcją klonowania.
3. Najpierw przechodzi clean install.
4. Następnie testowane jest MERO.
5. Na końcu testowany jest PowerTech.
6. Dopiero komplet trzech wyników może zmienić decyzję RC1.
