# Bezpieczne klonowanie strony na staging

Instrukcja dotyczy MERO i PowerTech. Wszystkie przykładowe nazwy trzeba
zastąpić wartościami konkretnego hostingu. Haseł nie zapisujemy w repozytorium
ani w historii poleceń.

## 1. Zatrzymanie i inwentaryzacja

Przed kopiowaniem zapisz:

- domenę i absolutny katalog produkcji,
- nazwę bazy produkcyjnej bez hasła,
- aktywną wersję CMS i PHP,
- aktywne moduły i motyw,
- rozmiar plików, uploadów i bazy,
- sumę SHA-256 `reklamova.json`,
- datę oraz osobę wykonującą operację.

Staging nie może korzystać z tego samego document root, bazy ani katalogu
uploadów co produkcja.

## 2. Backup plików

Backup przechowuj poza publicznym katalogiem:

```bash
STAMP="$(date +%Y%m%d-%H%M%S)"
mkdir -p "$HOME/reklamova-staging-backups/$STAMP"
tar -czf "$HOME/reklamova-staging-backups/$STAMP/site-files.tar.gz" \
  -C "/absolutna/sciezka/produkcji" .
sha256sum "$HOME/reklamova-staging-backups/$STAMP/site-files.tar.gz" \
  > "$HOME/reklamova-staging-backups/$STAMP/SHA256SUMS"
```

Nie zapisuj archiwum w `public`, `public_html` ani `public/uploads`.

## 3. Dump bazy

Preferowany jest `mysqldump`:

```bash
mysqldump --single-transaction --quick --routines --triggers \
  --default-character-set=utf8mb4 \
  -h DB_HOST -u DB_USER -p DB_PRODUCTION \
  | gzip -9 > "$HOME/reklamova-staging-backups/$STAMP/database.sql.gz"
sha256sum "$HOME/reklamova-staging-backups/$STAMP/database.sql.gz" \
  >> "$HOME/reklamova-staging-backups/$STAMP/SHA256SUMS"
gzip -t "$HOME/reklamova-staging-backups/$STAMP/database.sql.gz"
```

Jeżeli hosting nie udostępnia `mysqldump`, użyj eksportu SQL w panelu hostingu
lub phpMyAdmin. Eksport musi zawierać strukturę, dane, triggery i kodowanie
UTF-8. Placeholder backupu bez danych nie spełnia wymagania.

## 4. Nowa baza stagingowa

W panelu hostingu utwórz:

- nową bazę z nazwą zawierającą `staging`,
- nowego użytkownika ograniczonego wyłącznie do tej bazy,
- nowe, losowe hasło.

Zaimportuj kopię:

```bash
gzip -dc "$HOME/reklamova-staging-backups/$STAMP/database.sql.gz" \
  | mysql -h DB_HOST -u DB_STAGING_USER -p DB_STAGING
```

Po imporcie porównaj liczbę tabel oraz podstawowe liczby stron, użytkowników,
produktów i mediów. Nie uruchamiaj jeszcze publicznego frontu.

## 5. Kopia plików i uploadów

Skopiuj produkcję do nowego, pustego katalogu stagingowego. Na hostingu z SSH:

```bash
rsync -a --delete-delay \
  --exclude "app/storage/cache/*" \
  --exclude "app/storage/logs/*" \
  "/absolutna/sciezka/produkcji/" \
  "/absolutna/sciezka/stagingu/"
```

Przed użyciem `--delete-delay` oba absolutne katalogi muszą być ręcznie
sprawdzone poleceniem `realpath`. Przy braku SSH użyj kopii wykonanej w panelu
hostingu. `public/uploads` ma zostać skopiowany do stagingu, nigdy współdzielony.

## 6. Konfiguracja stagingu

Zmień wyłącznie kopię stagingową:

- `app/config/database.php`: stagingowy host, baza, użytkownik i hasło,
- `app/config/app.php`: stagingowy URL, `debug => false`,
- `app/config/license.php`: osobny `site_id`, `site_key` i serwer licencji,
- `reklamova.json`: `update_channel` ustawiony na `rc`,
- rekord licencji na update serverze: domena stagingowa i kanał `rc`,
- konfigurację cache i sesji, jeśli zawiera absolutne ścieżki,
- adresy odbiorców testowych, jeżeli moduł przechowuje je w ustawieniach.

Nie kopiuj stagingowego `app/config` z powrotem na produkcję. Sekrety nie mogą
trafić do Git.

## 7. Blokada indeksacji i dostępu

Basic Auth ma działać przed uruchomieniem aplikacji. Przykład dla Apache:

```apache
AuthType Basic
AuthName "Reklamova CMS RC1 staging"
AuthUserFile /absolutna/sciezka/poza/public/.htpasswd
Require valid-user

Header always set X-Robots-Tag "noindex, nofollow, noarchive"
```

Dodaj również stagingowy `robots.txt`:

```text
User-agent: *
Disallow: /
```

Sprawdź bez sesji:

```bash
curl -I https://staging.example.pl/
```

Oczekiwane są `401 Unauthorized` bez hasła i nagłówek `X-Robots-Tag`.

## 8. Blokada maili

Sama zmiana adresu nadawcy nie blokuje wysyłki. Przed pierwszym testem:

1. wyłącz pocztę wychodzącą dla stagingu w panelu hostingu albo
2. ustaw dla tego vhosta/PHP kontrolowany mail sink, np.
   `sendmail_path=/bin/true`, jeżeli hosting wspiera tę dyrektywę.

Potwierdź wynik przez `ini_get('sendmail_path')` albo log panelu hostingu.
Jeżeli blokady nie da się potwierdzić, test formularzy jest ZABLOKOWANY.
Nie używaj prawdziwych adresów klientów jako odbiorców testowych.

## 9. Wyłączenie trackingów

Na stagingowej bazie, przed pierwszym publicznym requestem:

```sql
INSERT INTO privacy_settings (`key`, value, created_at, updated_at)
VALUES ('emergency_disable_external_scripts', 'true', NOW(), NOW())
ON DUPLICATE KEY UPDATE value = 'true', updated_at = NOW();

UPDATE privacy_scripts SET is_active = 0, updated_at = NOW();
```

Polecenia wykonuj wyłącznie po potwierdzeniu nazwy stagingowej bazy. Po
uruchomieniu panelu sprawdź w Privacy Center tryb awaryjny i kartę Network w
przeglądarce. Żądania do GA, GTM, Meta, Clarity, Hotjar i innych trackerów nie
mogą wystąpić.

## 10. Wdrożenie RC1

- core kopiuj wyłącznie z allowlisty `reklamova.json`,
- przed wdrożeniem uruchom `php tools/build-update-package.php --validate-only`,
- nie kopiuj `app/config`, `app/themes`, `app/modules/custom` ani
  `public/uploads`,
- patch MERO wdrażaj osobno i tylko na MERO,
- na PowerTech potwierdź brak `app/modules/custom/mero`.

Po wdrożeniu uruchom migracje, wyczyść cache, wykonaj health check i sprawdź
log PHP. Zapisz sumy plików chronionych przed i po aktualizacji.

## 11. Rollback

1. Włącz maintenance mode albo zamknij staging przez Basic Auth.
2. Przywróć pliki z `site-files.tar.gz`.
3. Usuń stagingową bazę i utwórz ją ponownie albo przywróć dump.
4. Przywróć stagingową konfigurację domeny i bazy.
5. Wyczyść cache.
6. Sprawdź logowanie, front i brak połączeń z produkcyjnymi usługami.

Rollback stagingu nie może wykonywać żadnej operacji na produkcyjnym katalogu
ani produkcyjnej bazie.
