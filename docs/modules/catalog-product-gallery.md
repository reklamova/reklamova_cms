# Galeria zdjęć produktów

Status: **GOTOWA DO WALIDACJI WDROŻENIOWEJ**

Galeria produktów należy do wspólnego modułu `catalog`, a nie do modułu
PowerTech. Każda instalacja Reklamova CMS z aktywnym katalogiem otrzymuje ten
sam menedżer galerii w formularzu produktu.

## Zachowanie w panelu

- zdjęcia są wyświetlane jako kafelki z miniaturami,
- wiele plików można przeciągnąć na pole uploadu albo wybrać z dysku,
- istniejące obrazy można dodać z biblioteki Media,
- kolejność można zmieniać przeciąganiem lub przyciskami strzałek,
- pierwszy kafelek jest oznaczony jako zdjęcie główne,
- zdjęcia można usuwać z galerii bez ręcznej edycji adresów URL,
- komunikaty uploadu i zmian są przekazywane przez region `aria-live`,
- układ przechodzi z czterech kolumn do jednej na małych ekranach.

Formularz nadal zapisuje uporządkowaną listę w istniejącym polu
`catalog_products.gallery_json`. Nie jest wymagana migracja bazy danych.
Podczas zapisu `featured_image` jest synchronizowane z pierwszym elementem
galerii. Usunięcie wszystkich kafelków czyści zarówno galerię, jak i zdjęcie
główne.

Starsze produkty pozostają zgodne: jeżeli `featured_image` nie występowało w
`gallery_json`, formularz pokazuje je jako pierwszy kafelek. Po pierwszym
zapisie dane są ujednolicone do nowego modelu.

## Upload i bezpieczeństwo

Endpoint `POST /admin/catalog/gallery-upload`:

- wymaga zalogowanego użytkownika z uprawnieniem `manage_products`,
- wymaga poprawnego tokenu CSRF,
- przyjmuje maksymalnie 20 plików w jednym żądaniu,
- ogranicza pojedynczy plik do 12 MB,
- rozpoznaje MIME na podstawie zawartości, nie rozszerzenia,
- przyjmuje tylko JPG, PNG, WebP, GIF i AVIF,
- weryfikuje, że plik jest obrazem i nie przekracza 60 milionów pikseli,
- nadaje losową nazwę i zapisuje plik w `uploads/catalog/YYYY/MM`,
- rejestruje obraz w `cms_media` i zapisuje operację w logu aktywności,
- usuwa plik z dysku, jeżeli zapis rekordu Media się nie powiedzie.

Adresy istniejących zdjęć są deduplikowane. Dozwolone są ścieżki lokalne
rozpoczynające się od pojedynczego `/` oraz adresy HTTP/HTTPS. Inne schematy są
odrzucane.

## Warstwa publiczna

Pierwszy obraz galerii jest używany jako zdjęcie główne produktu. Pozostałe
obrazy są renderowane w galerii bez powtarzania zdjęcia głównego. Zasada działa
zarówno w standardowym widoku katalogu, jak i w chronionej warstwie PowerTech.

## Walidacja

Test `php tools/test-catalog-gallery.php` sprawdza:

- zachowanie kolejności,
- deduplikację,
- synchronizację zdjęcia głównego,
- pustą galerię,
- serializację JSON,
- odrzucanie niebezpiecznych schematów URL,
- limit 100 pozycji w zapisanej galerii.

Walidacja interfejsu powinna dodatkowo obejmować upload wielu zdjęć, zmianę
kolejności, usuwanie, ponowny zapis produktu oraz kontrolę karty produktu na
froncie.
