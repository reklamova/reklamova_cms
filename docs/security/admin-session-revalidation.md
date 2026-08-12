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
