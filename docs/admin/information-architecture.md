# Architektura informacji panelu

Menu panelu jest generowane dynamicznie z aktywnych modułów, ról, uprawnień i `ContentRegistry`.

## Menu klienta

Start

Treści:
- Strona główna,
- Podstrony,
- Poradnik,
- Media.

Oferta:
- Produkty,
- Kategorie produktów,
- Kalkulator budowy.

Kontakt:
- Zapytania,
- Formularze.

Marketing:
- Opinie i wiarygodność,
- Strony kampanii.

Ustawienia:
- Dane strony,
- Prywatność i cookies,
- Konto.

## Menu Reklamova

Widoczne tylko dla `super_admin`, `reklamova_admin`, `reklamova` i `developer`:
- Instalacje CMS,
- Moduły strony,
- Motyw strony,
- Aktualizacje CMS,
- Stan systemu.

Backupy, logi, uprawnienia i narzędzia developerskie pozostają częścią modelu uprawnień i mogą dostać osobne ekrany, ale nie powinny być pokazywane klientowi.

## Brak duplikatów

`MERO Leady` i `Leady` są jedną pozycją: `Zapytania`.

`MERO Poradnik` i `Poradnik` są jedną pozycją: `Poradnik`.

`Katalog` nie jest etykietą klienta. W menu klienta występują osobno `Produkty` i `Kategorie produktów`.
