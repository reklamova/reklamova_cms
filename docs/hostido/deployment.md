# Wdrozenie na Hostido

Docelowo Reklamova CMS ma dzialac na hostingu wspoldzielonym bez SSH.

## Wymagania

- PHP 8.3+
- MySQL albo MariaDB
- document root ustawiony na `public`
- rozszerzenia PHP: `pdo_mysql`, `openssl`, `curl`, `zip`, `mbstring`, `json`, `fileinfo`, `sodium`
- zapisywalne katalogi:
  - `app/storage`
  - `public/uploads`

## Tryb bez SSH

1. Zbuduj paczke instalacyjna ZIP.
2. Wgraj pliki na hosting przez FTP/SFTP albo manager plikow.
3. Ustaw katalog publiczny domeny na `public`.
4. Otworz domene klienta.
5. Instalator utworzy config, tabele i konto admina.
6. Wklej `site_key` z panelu Reklamova.
7. Instalacja zglosi sie do `updates.reklamova.pl`.

## CRON

Preferowany wariant:

```text
* * * * * php /path/to/cms/cron.php
```

Fallback bez CLI:

```text
https://klient.pl/cron/{secret}
```

## Aktualizacje

Domyslny tryb aktualizacji na Hostido to ZIP.

Git/Composer pozostaje trybem opcjonalnym tylko dla kont hostingowych, ktore realnie wspieraja te narzedzia.

## Uruchomienie panelu na mero.pl

Minimalny zakres do startu:

- wgrany kod z aktualnego repozytorium,
- document root domeny ustawiony na `public`,
- utworzona baza MySQL/MariaDB,
- zapisywalne `app/storage` i `public/uploads`,
- wejscie na `https://mero.pl/admin`,
- przejscie instalatora,
- utworzenie konta administratora.

Po instalacji panel udostepnia:

- dashboard,
- zarzadzanie stronami,
- upload mediow,
- podglad ustawien,
- liste modulow,
- liste motywow,
- status aktualizacji,
- health check hostingu.

Do wdrozenia produkcyjnego przed publikacja klientowi nalezy jeszcze dopiac:

- docelowy motyw `mero`,
- docelowe moduly biznesowe klienta,
- finalny update server `updates.reklamova.pl`,
- production site key,
- pelny backup bazy przez mysqldump albo PDO dumper.
