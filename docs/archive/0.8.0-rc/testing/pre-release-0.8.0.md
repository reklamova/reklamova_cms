# Pre-Release 0.8.0

Ta checklista jest do wykonania przed merge do `main` i przed zbudowaniem paczki produkcyjnej 0.8.0.

## Kontrole Techniczne

- [ ] Uruchom `php -l` dla wszystkich plików PHP.
- [ ] Zweryfikuj wszystkie `module.json`.
- [ ] Uruchom migracje na czystej bazie.
- [ ] Uruchom migracje na kopii bazy MERO.
- [ ] Uruchom migracje na kopii bazy PowerTech.

## Role I Menu

- [ ] Zaloguj się jako `client_admin`.
- [ ] Zaloguj się jako `reklamova_admin`.
- [ ] Sprawdź menu klienta: brak technikaliów, tylko aktywne funkcje strony.
- [ ] Sprawdź menu Reklamova: widoczne moduły, aktualizacje, stan systemu i narzędzia techniczne.

## Ekrany Klienta

- [ ] Sprawdź MERO: Zapytania.
- [ ] Sprawdź MERO: Poradnik.
- [ ] Sprawdź MERO: Kalkulator.
- [ ] Sprawdź Podstrony.
- [ ] Sprawdź Media.
- [ ] Sprawdź placementy Trust / Opinie i wiarygodność.

## Aktualizacje

- [ ] Sprawdź `/admin/updates` dla użytkownika bez `manage_updates`.
- [ ] Sprawdź `/admin/system` dla użytkownika z `manage_updates`.
- [ ] Potwierdź, że klient widzi tylko prosty komunikat o aktualizacji.
- [ ] Potwierdź, że Reklamova widzi szczegóły techniczne, jeśli ma uprawnienia.
