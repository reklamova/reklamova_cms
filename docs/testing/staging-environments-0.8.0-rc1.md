# Środowiska stagingowe Reklamova CMS 0.8.0-rc1

Data aktualizacji: 2026-07-28

Branch: `feature/client-panel-information-architecture`

Artefakt RC1: `bba5027b3c4ebfb54e26b6217098bda6b11b14f7`

Kanał aktualizacji: `rc`

## Stan

| Środowisko | Izolowana baza | Ochrona | Wynik |
| --- | --- | --- | --- |
| `cms-rc1.reklamova.pl` | tak | Basic Auth, noindex, tracking off | ZALICZONE |
| `staging.mero.pl` | tak | Basic Auth, noindex, tracking off | runtime zaliczony, vhost do podpięcia |
| `staging.powertechsc.pl` | nieutworzona | Basic Auth, noindex, mail off | pliki gotowe, test DB zablokowany |

## Clean CMS

Świeża instalacja powstała od zera. Migracje, role, menu, podstrony, Media,
Privacy Center i kanał `rc` zostały sprawdzone. Środowisko nie korzysta z
danych ani konfiguracji produkcyjnego CMS.

## MERO

Wykonano backup produkcji, utworzono fizyczną kopię plików i uploadów oraz
zaimportowano dump do osobnej bazy. Core i patch MERO mają oddzielne zakresy,
sumy i procedury rollbacku. Testy wykonano przez lokalny serwer PHP na hostingu,
ponieważ publiczny vhost nie wskazuje jeszcze katalogu stagingowego.

## PowerTech

Fizyczny klon plików, core RC1, Basic Auth, noindex, blokada poczty i licencja
`rc` są gotowe. Konfiguracja DB nie zawiera danych produkcyjnych. Baza pokazana
na formularzu LH nie została utworzona, dlatego migracje i testy aplikacyjne są
celowo zatrzymane.

## Zasady bezpieczeństwa

- żadna konfiguracja stagingowa nie wskazuje produkcyjnej bazy,
- uploady są kopiami, nie symlinkami,
- hasła i site keys są poza document root,
- RC nie został opublikowany na kanale `stable`,
- produkcja MERO i PowerTech nie została zmodyfikowana,
- backupów źródłowych nie usunięto,
- formularzy nie wysyłano do prawdziwych odbiorców.

## Kroki do pełnej walidacji

1. W dHosting podpiąć `staging.mero.pl` do
   `staging.mero.pl-rc1/public` i włączyć SSL.
2. W LH kliknąć `UTWÓRZ` dla bazy PowerTech i potwierdzić dane nowej bazy.
3. Zaimportować kopię PowerTech i uruchomić migracje.
4. Powtórzyć testy panelu, katalogu, Privacy Center i frontu PowerTech.
5. Zbudować oznaczone RC2 z poprawkami znalezionymi podczas RC1.
