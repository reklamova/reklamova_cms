# MERO custom module patch dla 0.8.0

## Zakres

Zmiany klienta MERO znajdują się w chronionej ścieżce
`app/modules/custom/mero`. Nie są częścią Reklamova CMS Core 0.8.0-rc1.

Zmodyfikowane pliki:

- `app/modules/custom/mero/admin.php`
- `app/modules/custom/mero/public.php`
- `app/modules/custom/mero/module.json`
- `app/modules/custom/mero/migrations/2026_06_24_000001_create_mero_tables.php`

Patch porządkuje nazwy i uprawnienia ekranów MERO, upraszcza Zapytania oraz
Poradnik, poprawia teksty kalkulatora, deklaruje jawne uprawnienia tras i
usuwa stary popup cookies z frontu MERO. Formularze pobierają teraz snapshot
zgody z globalnego `window.ReklamovaConsent`.

## Dlaczego patch jest osobny

`app/modules/custom` jest chronioną ścieżką każdej instalacji. Standardowa
aktualizacja core nie może jej usuwać ani nadpisywać, ponieważ różne strony
mają własne moduły i własne wersje ich konfiguracji. Dołączenie MERO do core
naruszałoby izolację klientów i mogłoby nadpisać lokalne zmiany.

## Wdrożenie na MERO staging

1. Potwierdź, że wdrożenie odbywa się na stagingu, nie na produkcji.
2. Zanotuj bieżący commit lub sumy SHA-256 czterech plików.
3. Wykonaj backup katalogu `app/modules/custom/mero`.
4. Wykonaj backup bazy danych.
5. Wgraj wyłącznie cztery pliki wymienione w sekcji Zakres.
6. Uruchom migracje modułów.
7. Wyczyść cache CMS.
8. Zaloguj się jako `client_admin` i `reklamova_admin`.

Nie kopiuj całego branchu do katalogu modułu. Patch ma zachować lokalny
`app/config`, motyw MERO, uploady oraz pozostałe moduły custom.

## Test Zapytania

- Wejdź na `/admin/mero/leads`.
- Potwierdź, że w menu jest jedna pozycja `Zapytania`.
- Jako `client_admin` sprawdź czytelny widok pól zgłoszenia.
- Potwierdź brak surowego JSON w głównym widoku.
- Jako Reklamova Admin rozwiń `Dane techniczne` i sprawdź payload.
- Użytkownik z `view_leads` może czytać, ale nie może zapisać zmian.
- Zmiana statusu lub notatki wymaga `manage_inquiries`.

## Test Poradnika

- Wejdź na `/admin/mero/articles`.
- Potwierdź jedną pozycję `Poradnik` w menu.
- Sprawdź etykiety: Tytuł, Adres URL, Status, Data publikacji, Kategoria,
  Obraz wyróżniający, Zajawka i Treść.
- Sprawdź polskie statusy `Szkic` i `Opublikowany`.
- Rozwiń `Ustawienia SEO` i zapisz meta title oraz meta description.
- Utwórz szkic, opublikuj go i ponownie otwórz do edycji.

## Test Kalkulatora

- Wejdź na `/admin/mero/calculator`.
- Potwierdź jedną pozycję `Kalkulator budowy`.
- Sprawdź polskie etykiety, `zł/m²`, `Widełki` i `Stan surowy zamknięty`.
- Zapisz stawki użytkownikiem z `manage_products`.
- Użytkownik bez tego uprawnienia nie może otworzyć ani zapisać formularza.
- Sprawdź wynik kalkulatora na froncie.

## Test Privacy Center

- Otwórz stronę jako nowy użytkownik i potwierdź jeden baner zgód.
- Potwierdź brak klucza `mero_cookie_consent_v1`.
- Zaakceptuj lub odrzuć zgody i przejdź na inną podstronę.
- Baner nie może wrócić przy tej samej wersji dokumentów.
- Link `Ustawienia prywatności` ma otworzyć manager core.
- W kodzie strony ma wystąpić dokładnie jeden `consent-manager.js`.

## Rollback

1. Włącz tryb maintenance.
2. Przywróć backup katalogu `app/modules/custom/mero`.
3. Jeżeli migracja zmieniła dane, przywróć backup bazy.
4. Wyczyść cache.
5. Wyłącz maintenance.
6. Sprawdź `/admin`, Zapytania, Poradnik, Kalkulator i stronę publiczną.

Nie wykonuj rollbacku przez paczkę core. Patch MERO ma własny backup i własną
procedurę cofnięcia.
