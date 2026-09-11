# Linki w polach treściowych

Reklamova CMS zapisuje linki w zwykłych polach tekstowych w bezpiecznym, przenośnym formacie:

```text
[tekst linku](https://example.com)
[katalog PDF](/uploads/katalog.pdf){new-tab}
```

## Panel administracyjny

Pole, które ma obsługiwać linki, musi jawnie zadeklarować edytor:

```html
<textarea name="description" data-content-editor></textarea>
```

Skrypt rdzenia dodaje nad takim polem przycisk `Link` i korzysta z jednego wspólnego modala. Nie należy dodawać atrybutu do pól SEO, JSON, CSV, kodu źródłowego, adresów URL ani innych danych technicznych.

## Widok publiczny

Treść z takiego pola musi być renderowana przez formatter rdzenia:

```php
use Reklamova\Cms\Content\TextFormatter;

echo TextFormatter::withLinks((string) $record['description']);
```

Formatter escapuje całą treść, zamienia podziały wierszy na `<br>` i tworzy odnośniki wyłącznie dla pełnych adresów `http://` lub `https://` oraz bezpiecznych ścieżek rozpoczynających się pojedynczym `/`. Dla znacznika `{new-tab}` dodaje `target="_blank"` wraz z `rel="noopener noreferrer"`.

Deklaracja pola i użycie formattera stanowią jeden kontrakt. Nie wolno udostępniać przycisku `Link`, jeżeli odpowiadający mu widok publiczny nie korzysta z `TextFormatter::withLinks()`.
