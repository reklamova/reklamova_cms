# Walidacja paczki 0.8.0-rc4

Data testu: 2026-07-30

## Artefakt

- commit źródłowy: `5e97839e3d867b02b9525a2a1b1543e4eab439aa`,
- package ID: `pkg_core_0_8_0_rc4`,
- kanał: `rc`,
- plik: `reklamova-core-0.8.0-rc4.zip`,
- rozmiar: 276122 bajty,
- SHA-256:
  `e1e99c9319c638de8ad820523630046271e74ac633db9d7952e1f4c7c1b1adb1`,
- podpis: Ed25519,
- minimalne PHP: 8.3.

Archiwum źródłowe użyte do izolowanego builda miało SHA-256:
`d32bc6606f849ad1bca6bf26c2caa6f15f1902119ded66eb41db086ccffd45de`.
Klucz prywatny pozostał na update serverze.

## Wyniki walidacji

| Kontrola | Wynik |
| --- | --- |
| Package ID, wersja i kanał | ZALICZONA |
| Manifest | ZALICZONA |
| SHA-256 ZIP | ZALICZONA |
| Podpis Ed25519 | ZALICZONA |
| Pliki manifestu | 165 |
| PHP lint źródła | 114/114 |
| Pliki poza allowlistą | 0 |
| Protected paths | 0 |
| Negatywny test `app/modules/custom/mero` | ZALICZONY, build przerwany |

Niezależna weryfikacja na update serverze zwróciła:

```text
PACKAGE_VERIFIED version=0.8.0-rc4 files=165
```

Paczka nie zawiera konfiguracji instalacji, motywów klientów, custom modułów,
uploadów, backupów, logów, dumpów baz ani zagnieżdżonych buildów RC.

## Zmiany względem RC3

Zmiany funkcjonalne:

- szerokie tabele panelu przewijają się lokalnie bez poszerzania całej strony,
- układ panelu ma poprawne ograniczenia szerokości na desktopie, tablecie i
  telefonie,
- serializacja parametrów i list produktu bezpiecznie obsługuje nieprawidłowe
  sekwencje UTF-8 odziedziczone ze starszych danych.

Zmiany metadanych:

- `Version.php`: `0.8.0-rc4`,
- `reklamova.json`: `0.8.0-rc4`, kanał `rc`.

## Publikacja

RC4 opublikowano wyłącznie na kanale `rc`. Kanał `stable` nie został zmieniony
i podczas walidacji nadal wskazywał `0.7.5`.

Status paczki: **ZALICZONA**.
