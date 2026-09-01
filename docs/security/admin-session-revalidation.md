# Rewalidacja sesji administratora

## Cel

Sesja panelu administracyjnego nie może pozostawać aktywna po usunięciu lub
wyłączeniu konta. Zmiany nazwy, adresu e-mail i roli użytkownika również muszą
obowiązywać bez czekania na ponowne logowanie.

## Reguły

- po poprawnym logowaniu identyfikator sesji jest zmieniany;
- przy każdym chronionym żądaniu dane konta są pobierane ponownie z
  `cms_users`;
- brak konta albo `active = 0` usuwa uwierzytelnienie i zmienia identyfikator
  sesji;
- wylogowanie usuwa dane użytkownika i token CSRF oraz zmienia identyfikator
  sesji;
- aktualna rola, nazwa i e-mail z bazy zastępują dane zapisane wcześniej w
  sesji.

## Test integracyjny

Test tworzy wyłącznie losowe konto techniczne i usuwa je w bloku `finally`:

```bash
php tools/test-auth-session.php /sciezka/do/instalacji
```

Sprawdza rotację identyfikatora po logowaniu, odświeżenie danych konta oraz
natychmiastowe unieważnienie sesji po dezaktywacji i usunięciu użytkownika.

## Wdrożenie PowerTech z 2026-08-12

- źródło: commit `713f3602d1669258a3a7d3f8c211e4a01e53807e`;
- staging: test integracyjny zaliczony, SHA-256 pliku zgodne ze źródłem;
- produkcja: test integracyjny zaliczony, SHA-256 pliku zgodne ze źródłem;
- test regresyjny w przeglądarce: istniejąca sesja usuniętego konta została po
  wdrożeniu przekierowana z `/admin/` do `/admin/login`;
- audyt po wdrożeniu: brak testowych użytkowników, brak tymczasowych wpisów
  Basic Auth oraz brak nowych błędów `Fatal`, `Uncaught` i `Parse` w logach;
- kopia stagingu:
  `/home/platne/serwer38522/backups/powertech-auth-staging-20260812_130506`;
- kopia produkcji:
  `/home/platne/serwer38522/backups/powertech-auth-production-20260812_130801`.

Obie kopie mają tryb `700`, a zapisane sumy kontrolne zostały zweryfikowane
poleceniem `sha256sum -c`.
