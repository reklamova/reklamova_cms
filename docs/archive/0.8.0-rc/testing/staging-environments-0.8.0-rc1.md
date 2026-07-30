# Środowiska stagingowe Reklamova CMS 0.8.0-rc1

Data aktualizacji: 2026-07-29

Branch: `feature/client-panel-information-architecture`

Artefakt RC1: `bba5027b3c4ebfb54e26b6217098bda6b11b14f7`

Kanał aktualizacji: `rc`

## Stan

| Środowisko | Izolowana baza | Ochrona | Wynik |
| --- | --- | --- | --- |
| `cms-rc1.reklamova.pl` | tak | Basic Auth, noindex, tracking off | testy zaliczone; bieżący dostęp DB zablokowany po zmianie hasła |
| `staging.mero.pl` | tak | Basic Auth, noindex, tracking off | ZALICZONE publicznie |
| `staging.powertechsc.pl` | tak | Basic Auth, noindex, mail off | ZALICZONE technicznie dla RC1 |

## Clean CMS

Świeża instalacja powstała od zera. Migracje, role, menu, podstrony, Media,
Privacy Center i kanał `rc` zostały sprawdzone. Środowisko nie korzysta z
danych ani konfiguracji produkcyjnego CMS.

## MERO

Wykonano backup produkcji, utworzono fizyczną kopię plików i uploadów oraz
zaimportowano dump do osobnej bazy. Core i patch MERO mają oddzielne zakresy,
sumy i procedury rollbacku. Publiczny vhost, SSL, Basic Auth, front, role i
połączenie z update serverem zostały sprawdzone.

## PowerTech

Fizyczny klon plików, core RC1, Basic Auth, noindex, blokada poczty i licencja
`rc` są gotowe. Dump produkcji został bezpiecznie zaimportowany do osobnej bazy,
migracje przeszły, a front, panel, role, katalog i Privacy Center zwracają
poprawne odpowiedzi. Powielanie produktu wymaga RC2, ponieważ nie ma go jeszcze
w artefakcie RC1.

## Zasady bezpieczeństwa

- żadna konfiguracja stagingowa nie wskazuje produkcyjnej bazy,
- uploady są kopiami, nie symlinkami,
- hasła i site keys są poza document root,
- RC nie został opublikowany na kanale `stable`,
- produkcja MERO i PowerTech nie została zmodyfikowana,
- backupów źródłowych nie usunięto,
- formularzy nie wysyłano do prawdziwych odbiorców.

## Kroki do pełnej walidacji

1. Przywrócić dostęp clean stagingu po zmianie hasła użytkownika bazy.
2. Zbudować oznaczone RC2 z poprawkami znalezionymi podczas RC1.
3. Powtórzyć na RC2 test powielania produktu na PowerTech.
4. Wykonać przeglądarkowy test trwałości zgody cookies na MERO i PowerTech.
