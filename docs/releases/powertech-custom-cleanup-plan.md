# Plan sprzątania custom module MERO w PowerTech

Data: 2026-07-29

## Co wykryto

W produkcyjnej kopii plików PowerTech znajdował się katalog:

`app/modules/custom/mero`

Jest to moduł specyficzny dla instalacji MERO. Nie należy do core Reklamova
CMS ani do funkcji strony PowerTech. Podczas przygotowania stagingu PowerTech
katalog został zarchiwizowany poza document root i nie został wdrożony razem z
core RC1.

## Dlaczego to jest ryzyko

- może dodać trasy, menu lub migracje przeznaczone dla innej strony,
- może powodować duplikaty modułów i niejasne uprawnienia,
- utrudnia określenie, które pliki należą do PowerTech,
- nie powinien być usuwany przez aktualizację core, ponieważ
  `app/modules/custom` jest chronioną ścieżką klienta.

## Jak sprawdzić użycie

1. Sprawdź `app/config/app.php`, aktywne moduły i wpisy `cms_modules`.
2. Wyszukaj odwołania do sluga `mero` w konfiguracji PowerTech i motywie.
3. Sprawdź, czy istnieją trasy `/admin/mero/*` albo publiczne endpointy MERO.
4. Sprawdź logi aplikacji przed i po wyłączeniu katalogu.
5. Porównaj menu, front, formularze, katalog i Privacy Center.

Brak wpisu aktywnego modułu, brak tras i brak odwołań motywu oznacza, że katalog
jest osierocony, ale nie upoważnia jeszcze do automatycznego usunięcia z
produkcji.

## Bezpieczna procedura na stagingu

1. Zapisz sumę SHA-256 wszystkich plików katalogu.
2. Utwórz archiwum TAR.GZ poza document root.
3. Przenieś katalog poza `app/modules/custom`.
4. Uruchom migrator bez wykonywania migracji MERO.
5. Sprawdź front, panel, katalog, produkty, kategorie, formularze i log PHP.
6. Potwierdź brak pozycji oraz tras MERO.

Na stagingu katalog został już zarchiwizowany w:

`/home/platne/serwer38522/staging-excluded/powertech-rc1/custom/mero-from-production-clone.tar.gz`

## Rollback

1. Przywróć katalog z archiwum do `app/modules/custom/mero`.
2. Odtwórz poprzednie prawa plików.
3. Wyczyść cache modułów.
4. Sprawdź panel i log PHP.

## Produkcja

Usunięcie z produkcji wymaga osobnej akceptacji. Przed operacją trzeba wykonać
backup katalogu, konfiguracji i bazy oraz powtórzyć checklistę stagingową.
Standardowa paczka core RC2 nie może usuwać ani modyfikować tego katalogu.
