# Responsywne tabele 0.8.0-rc4

Data testu: 2026-07-30

Test wykonano w prawdziwym Chromium na finalnym kandydacie RC4.

## Viewporty

- desktop: 1440 x 1000 px,
- tablet: 768 x 1000 px,
- telefon: 390 x 844 px.

## Środowiska i ekrany

Clean CMS:

- Podstrony,
- Media.

MERO:

- Podstrony,
- Media,
- Zapytania,
- Poradnik.

PowerTech:

- Podstrony,
- Media,
- Produkty,
- Kategorie produktów.

Łącznie wykonano 30 testów ekranów i sprawdzono 30 tabel.

## Kryteria

Każdy ekran musiał spełnić wszystkie warunki:

- HTTP 200,
- `document.scrollWidth` nie większy niż szerokość viewportu,
- szeroka tabela przewija się we własnym obszarze,
- kolumny i akcje pozostają dostępne,
- brak błędów JavaScript i `pageerror`,
- brak ingerencji w motyw, konfigurację, custom moduły i uploady.

## Wynik

| Kontrola | Wynik |
| --- | --- |
| Desktop 1440 px | ZALICZONA |
| Tablet 768 px | ZALICZONA |
| Telefon 390 px | ZALICZONA |
| Globalny poziomy overflow | 0 ekranów |
| Tabele bez lokalnego scrolla, gdy był wymagany | 0 |
| Błędy JS | 0 |
| Niedostępne akcje tabel | 0 |

Wynik końcowy: **ZALICZONY**.
