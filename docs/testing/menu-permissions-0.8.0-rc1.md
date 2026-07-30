# Role i menu 0.8.0-rc1

Data testu: 2026-07-28

Test wykonano na PHP 8.3 przez renderowanie rzeczywistego `AdminView` i
`PermissionManager` z aktywnymi pozycjami modułów.

## client_admin

Widoczne:

- Start
- Podstrony
- Media
- Strona główna
- Poradnik
- Produkty
- Kategorie produktów
- Zapytania
- Formularze
- Prywatność i cookies
- Dane strony
- Konto

Niewidoczne:

- Moduły strony
- Motyw strony
- Aktualizacje CMS jako ekran techniczny
- Stan systemu
- orphaned Opinie i wiarygodność

Wynik automatyczny: ZALICZONY, 0 brakujących pozycji, 0 wycieków technicznych.

## reklamova_admin

Widoczne były wszystkie pozycje klienta oraz:

- Moduły strony
- Motyw strony
- Aktualizacje CMS
- Stan systemu
- Opinie i wiarygodność także bez placementu, do diagnostyki

Wynik automatyczny: ZALICZONY.

## Trust placement

- orphaned Trust był ukryty dla klienta,
- Trust z placementem i uprawnieniem był widoczny dla roli marketingowej.

Wynik automatyczny: ZALICZONY.

## Test wymagany przed merge

Nadal wymagane jest ręczne logowanie na stagingu jako rzeczywisty
`client_admin` i `reklamova_admin`, ponieważ test CLI nie sprawdza sesji,
layoutu przeglądarki ani konfiguracji modułów konkretnej instalacji.

Status całego wymagania menu: AUTOMATYCZNIE ZALICZONY, MANUALNY STAGING OCZEKUJE.
