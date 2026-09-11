# Commerce w CMS Reklamova

## Status

Moduł jest rozwijany jako opcjonalny silnik sprzedaży. Nie jest włączony domyślnie i nie zmienia działania instalacji bez sklepu.

## Granice

Kod znajduje się w `app/modules/commerce`. Warstwy domenowe są rozdzielone na katalog, pricing, cart/checkout, orders, customers, payments, shipping, order files, notifications, SEO/analytics i admin. Wspólny kod nie sprawdza domeny klienta. Reguły Drukarni są konfiguracją instalacji albo modułem custom.

Najważniejsze zasady:

- kwoty są integerami w najmniejszej jednostce waluty;
- stawki VAT używają basis points, np. 23% = 2300;
- pozycja zamówienia przechowuje immutable snapshot;
- status zamówienia i płatności są niezależne;
- callback klienta nie potwierdza opłacenia;
- provider płatności implementuje `PaymentProviderInterface`;
- import używa unikalnego klucza `source_system + source_type + external_id`;
- sekrety pozostają poza repozytorium.

## Włączenie

1. Skopiuj przykładowe pliki konfiguracji i uzupełnij je poza Git.
2. W panelu modułów włącz `commerce`.
3. Uruchom istniejący migrator:

       php tools/run-migrations.php

4. Sprawdź testy:

       composer test

Test integracyjny wymaga pustej, jednorazowej bazy MariaDB i jawnie ustawionych zmiennych `REKLAMOVA_DATABASE_*`:

       composer test:integration

Włączenie modułu tworzy wyłącznie tabele z prefiksem `commerce_`. Wyłączenie modułu nie usuwa danych.

## Import z WooCommerce

Skopiuj `app/config/commerce-import.example.php` do ignorowanego przez Git pliku `app/config/commerce-import.php`. Użytkownik źródłowej bazy powinien mieć wyłącznie uprawnienia odczytu. `uploads_path` wskazuje istniejący katalog `wp-content/uploads`.

Najpierw zawsze uruchom analizę:

       php tools/commerce-import-wordpress.php --dry-run --report=app/storage/temp/commerce-import-dry-run.json

Import zapisujący dane wymaga jawnej flagi:

       php tools/commerce-import-wordpress.php --apply --report=app/storage/temp/commerce-import.json

Importer pobiera dane bezpośrednio z bazy WooCommerce, kopiuje referencjonowane obrazy z kontrolą SHA-256 i wykonuje upsert przez unikalne mapowanie źródła. Ponowne uruchomienie nie tworzy duplikatów. Konflikt slugów lub SKU zatrzymuje import przed zapisem.

## Kalkulacja

`Money` nie przyjmuje floatów. `TaxCalculator` liczy podatek deterministycznie z integerów. `CartCalculator` przyjmuje linie z ceną jednostkową, ilością, rabatem i stawką VAT oraz opcjonalną dostawę. Każdy wynik zawiera subtotal, rabat, netto, VAT i brutto.

## Koszyk i checkout

`CartService` wydaje losowy token o entropii 256 bitów; w bazie przechowywany jest tylko SHA-256. Identyczne konfiguracje produktu otrzymują ten sam hash niezależnie od kolejności kluczy opcji, więc ponowne dodanie zwiększa ilość istniejącej pozycji. Każda mutacja zwiększa wersję koszyka.

`CheckoutService` nie przyjmuje cen z przeglądarki. `PdoCheckoutStore` blokuje koszyk i ponownie pobiera opublikowany produkt, aktywny wariant, opcje, cenę, VAT, stan, wymagania plikowe, kupon i metodę dostawy. Utworzenie zamówienia, immutable snapshotów pozycji, wykorzystania kuponu, zmiany stanu, historii i zdarzenia outbox odbywa się w jednej transakcji. `checkout_key` uniemożliwia podwójne zamówienie po ponowieniu requestu.

Checkout obsługuje gościa lub konto należące do tego samego sklepu i adresu e-mail, osobny adres wysyłki, dane firmy/NIP, fakturę, punkt odbioru i obowiązkowe zgody. Limit kuponu per klient jest liczony po hashu e-mail także dla gościa. Stan jest zmniejszany pod blokadą; produkt wymagający pliku rozpoczyna od `awaiting_files`.

## Płatności

`PaymentProviderInterface` oddziela domenę zamówień od operatora. Provider zwraca obiekt przekierowania i przekształca surową notyfikację w `PaymentNotification`. `PaymentNotificationProcessor` przyjmuje wyłącznie prawidłowo podpisane zdarzenia, atomowo rezerwuje ich klucz i porównuje transaction ID, order ID, kwotę oraz walutę z zablokowanym rekordem payment attempt. Duplikat nie wykonuje ponownie aktualizacji, a niedozwolone cofnięcie statusu jest odrzucane.

Powrót użytkownika z bramki jest tylko ekranem informacyjnym. Status `paid` może ustawić wyłącznie zweryfikowana notyfikacja albo jawne odpytanie API poddane tym samym kontrolom zgodności.

`PaymentInitiationService` rezerwuje próbę płatności w bazie przed wywołaniem operatora. Kwota, waluta, numer zamówienia i dane płatnika pochodzą z zablokowanego rekordu zamówienia, nie z formularza. Ten sam token ponowionego żądania zwraca już zapisany URL operatora; po jednoznacznym błędzie nowa próba wymaga nowego tokenu.

ING Pay musi mieć osobną konfigurację sandbox/production. Identyfikatory i sekrety są dostarczane przez konfigurację środowiska, nie przez manifest modułu.

Implementacja `IngPayProvider` używa oficjalnych endpointów `/{merchantId}/payment`. Autoryzacja jest przekazywana jako Bearer token. Podpis notyfikacji jest liczony wyłącznie na niezmienionym body jako `hash(raw_body + service_key, alg)`; akceptowane algorytmy to SHA-224/256/384/512. Provider dopuszcza dodatkowe pola odpowiedzi, aby pozostać zgodny z rozszerzeniami API.

Dokumentacja referencyjna:

- https://bump.sh/pgw/doc/imoje-api/
- https://bump.sh/pgw/doc/imoje-api/operation/operation-post-parameter-payment
- https://bump.sh/pgw/doc/imoje-api/topic/topic-notyfikacje

## Migracje i kompatybilność

Commerce nie rozszerza tabel obecnego katalogu ofertowego, aby nie zmieniać semantyki działających stron. Dane sklepowe mają oddzielny schemat. Adaptery mogą później publikować wybrane dane commerce do istniejących komponentów frontu.

Każda kolejna zmiana schematu otrzymuje nową migrację. Nie edytujemy wykonanej migracji na produkcji.

## Testy wymagane przed produkcją

- ceny, VAT, rabaty i dostawa;
- atomowe utworzenie zamówienia;
- ING Pay initiate/query/notification;
- duplikat i błędna kwota notyfikacji;
- anulowanie i ponowienie płatności;
- checkout gościnny i kontowy;
- uprawnienia do zamówień i plików;
- upload MIME/rozmiar/storage;
- importer dry-run/idempotencja/raport;
- E2E produkt → koszyk → checkout → ING Pay sandbox → paid.
