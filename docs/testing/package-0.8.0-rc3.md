# Walidacja paczki 0.8.0-rc3

Data testu: 2026-07-30

## Artefakt

- commit źródłowy: `47743a75efad095435dec4e0a1b005354dc12f7f`,
- package ID: `pkg_core_0_8_0_rc3`,
- kanał: `rc`,
- plik: `reklamova-core-0.8.0-rc3.zip`,
- rozmiar: 269409 bajtów,
- SHA-256:
  `8a10e98a2585bbbebcefa31450e024c38191fe6bd68139ed0dcecea83a79fc94`,
- podpis: Ed25519,
- minimalne PHP: 8.3.

Archiwum źródłowe użyte do izolowanego builda miało SHA-256:
`751ab0923672cc5f03e275362ae2dd315d4fa401db62a2120e0079f699b2aecf`.
Klucz prywatny pozostał na update serverze i nie był kopiowany do repozytorium
ani środowiska lokalnego.

## Wyniki walidacji

| Kontrola | Wynik |
| --- | --- |
| Package ID, wersja i kanał | ZALICZONA |
| Manifest | ZALICZONA |
| SHA-256 ZIP | ZALICZONA |
| Podpis Ed25519 | ZALICZONA |
| Sumy plików | 161/161 zgodnych |
| Pliki poza allowlistą | 0 |
| Protected paths | 0 |
| Brakujące pliki z manifestu | 0 |
| Negatywny test `app/modules/custom/mero` | ZALICZONY, build przerwany |

Niezależna weryfikacja na update serverze zwróciła:

```text
PACKAGE_VERIFIED version=0.8.0-rc3 files=161
```

## Zakres

Paczka zawiera tylko wskazane moduły core, migracje core, `app/core`,
`public/assets/core`, dokumentację, `reklamova.json` oraz
`app/config/placements.example.php`.

Paczka nie zawiera:

- `app/config/**` poza `placements.example.php`,
- `app/themes/**`,
- `app/modules/custom/**`,
- `public/uploads/**`,
- `app/storage/backups/**`,
- `app/storage/logs/**`,
- konfiguracji stagingowych,
- dumpów baz,
- poprzednich paczek RC.

## Różnice względem RC2

Porównanie ZIP RC2 i RC3 wykazało 14 różniących się plików:

- 4 pliki funkcjonalne:
  `ConnectionFactory.php`, `Application.php`, `catalog/admin.php`, `admin.css`,
- 2 pliki metadanych:
  `Version.php`, `reklamova.json`,
- 8 plików dokumentacji RC2/RC3.

Nie wykryto innej, nieopisanej zmiany funkcjonalnej.

## Publikacja

RC3 opublikowano wyłącznie na kanale `rc`. Najnowszy pakiet kanału `rc` to
`pkg_core_0_8_0_rc3`. Kanał `stable` nie został zmieniony i nadal wskazuje
`0.7.5` (`pkg_core_0_7_5`).

Status paczki: **ZALICZONA**.
