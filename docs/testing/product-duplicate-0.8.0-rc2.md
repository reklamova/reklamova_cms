# Powielanie produktu PowerTech 0.8.0-rc2

Data testu: 2026-07-29

Test wykonano przez rzeczywiste logowanie `client_admin` i żądania panelu.

## Przebieg

1. Otwarto listę produktów.
2. Potwierdzono obecność akcji `Powiel`.
3. Powielono produkt o ID 754.
4. Powstała kopia o ID 856.
5. Zweryfikowano dane kopii w bazie.
6. Sprawdzono publiczny adres produktu.
7. Usunięto kopię przez panel.

## Wynik

- logowanie: HTTP 302 po poprawnym POST,
- lista produktów: HTTP 200,
- powielenie: HTTP 302 do edycji kopii,
- status kopii: `draft`,
- nazwa otrzymała końcówkę `- kopia`,
- adres URL otrzymał unikalną końcówkę `-kopia`,
- kategoria, SKU, marka, model, opis, parametry, galeria, dokumenty, zdjęcie i
  dane SEO były identyczne z oryginałem,
- `is_featured` kopii: 0,
- kolejność kopii: oryginał + 1,
- publiczny adres szkicu: HTTP 404,
- oryginał nie został zmieniony,
- po usunięciu kopii liczba produktów wróciła z 104 do 104,
- brak nowych wpisów w logu błędów PHP.

Status: **ZALICZONY**.
