# Panel klienta Reklamova CMS

Panel klienta jest projektowany jak codzienny kokpit właściciela firmy, a nie jak panel techniczny. Klient ma rozumieć, gdzie zmieni tekst, zdjęcie, produkt albo sprawdzi zapytanie.

## Tryb prosty

Tryb prosty jest domyślny dla `client_admin`, `editor`, `marketing` i zwykłych kont obsługi strony.

Pokazuje:
- Start,
- Stronę główną,
- Podstrony,
- Poradnik, jeśli moduł jest aktywny,
- Media,
- Produkty i Kategorie produktów, jeśli katalog jest aktywny,
- Zapytania i Formularze, jeśli są aktywne,
- Opinie i wiarygodność oraz Strony kampanii, jeśli są aktywne,
- Dane strony, Prywatność i cookies, Konto.

Ukrywa:
- moduły core jako technikalia,
- motyw strony,
- stan systemu,
- logi,
- backupy,
- aktualizacje CMS jako ekran techniczny,
- narzędzia developerskie,
- pola routing/debug/schema, jeśli użytkownik nie ma uprawnienia.

## Aktualizacje

Klient może zobaczyć prosty komunikat: "Dostępna jest nowa wersja panelu". Szczegóły, dry run, backup i wykonanie aktualizacji są po stronie Reklamova.

## Zasada

Jeśli użytkownik musi zgadywać, czym różni się `Leady` od `MERO Leady` albo `Strony` od `Strona firmowa`, panel jest źle zaprojektowany. W UI pokazujemy zadania, nie techniczne nazwy modułów.
