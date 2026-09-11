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

Włączenie modułu tworzy wyłącznie tabele z prefiksem `commerce_`. Wyłączenie modułu nie usuwa danych.

## Kalkulacja

`Money` nie przyjmuje floatów. `TaxCalculator` liczy podatek deterministycznie z integerów. `CartCalculator` przyjmuje linie z ceną jednostkową, ilością, rabatem i stawką VAT oraz opcjonalną dostawę. Każdy wynik zawiera subtotal, rabat, netto, VAT i brutto.

## Płatności

`PaymentProviderInterface` oddziela domenę zamówień od operatora. Provider zwraca obiekt przekierowania i przekształca surową, zweryfikowaną notyfikację w `PaymentNotification`. Warstwa aplikacyjna ma dodatkowo porównać order ID, transaction ID, kwotę i walutę z oczekiwanym payment attempt, a event zapisać idempotentnie.

ING Pay musi mieć osobną konfigurację sandbox/production. Identyfikatory i sekrety są dostarczane przez konfigurację środowiska, nie przez manifest modułu.

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
