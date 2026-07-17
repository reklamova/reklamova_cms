# Panel klienta Reklamova CMS

Panel klienta ma działać jak codzienny kokpit właściciela strony, nie jak agencyjny backoffice.

## Zasada główna

Klient widzi tylko funkcje aktywne dla jego instalacji i tylko te, do których ma uprawnienia.

Reklamova widzi część techniczną: moduły, motyw, aktualizacje, stan systemu, backupy i logi.

## Menu klienta

- Treść: Start, Strony, Strona główna, Aktualności / Poradniki, Media, Formularze.
- Sprzedaż: Produkty, Kategorie produktów, Zapytania / Zamówienia.
- Marketing: Landing pages, Opinie i referencje, Prywatność i cookies.
- Ustawienia: Ustawienia strony, Konto.

Pozycje modułowe pojawiają się tylko, jeśli moduł jest aktywny i ma `visible_in_client_nav = true`.

## Menu Reklamova

Grupa `Reklamova / techniczne` jest widoczna tylko dla `super_admin`, `reklamova_admin`, `reklamova` i `developer`.

Zawiera moduły strony, motyw strony, aktualizacje CMS, stan systemu, logi, backupy i narzędzia developerskie.

## Edycja treści

Ekran edycji strony ma prowadzić przez zadanie: nazwa strony, adres URL, status, sekcje, media i opcjonalne SEO.

Puste sekcje są zwinięte. SEO jest zwinięte i nie jest wymagane do zapisu.
