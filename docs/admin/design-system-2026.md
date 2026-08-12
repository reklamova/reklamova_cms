# Design system panelu Reklamova CMS 2026

## Status

Ten dokument opisuje obowiązujący wzorzec wizualny panelu Reklamova CMS.
Punktem odniesienia jest zaakceptowana makieta przekazana 2026-08-12. Zasady
dotyczą wspólnego core oraz wszystkich ekranów modułów klienckich.

## Założenia

- jasna, spokojna przestrzeń robocza i ciemny techniczny sidebar;
- fiolet jako jedyny podstawowy kolor interakcji;
- cienkie obramowania i bardzo lekkie cienie zamiast ciężkich kart;
- kompaktowa typografia Inter/Manrope i wyraźna hierarchia informacji;
- jeden wspólny wygląd dashboardów, tabel, formularzy, mediów i galerii;
- widoczny kontekst zarządzanej strony bez mieszania brandingu klientów z
  marką produktu Reklamova CMS;
- pełna obsługa widoku mobilnego, klawiatury i ograniczenia animacji.

## Tokeny bazowe

| Zastosowanie | Wartość |
|---|---|
| Tło aplikacji | `#f4f6fa` |
| Sidebar | `#111827` → `#0b1220` |
| Tekst główny | `#171b27` |
| Tekst pomocniczy | `#667085` |
| Obramowanie | `#e4e7ec` |
| Akcja podstawowa | `#6847e8` |
| Akcja podstawowa — hover | `#5636d4` |
| Sukces | `#158463` |
| Ostrzeżenie | `#b66b12` |
| Błąd / akcja destrukcyjna | `#d92d20` |
| Promień standardowy | `10px` |
| Font | `Inter`, zapasowo `Manrope` i font systemowy |

## Architektura

- `public/assets/core/admin.css` pozostaje arkuszem kompatybilności dla
  istniejących modułów;
- `public/assets/core/admin-2026.css` jest obowiązującą, ładowaną na końcu
  warstwą design systemu;
- `public/assets/core/admin-shell.js` obsługuje menu mobilne, zwijanie sidebara,
  menu konta i wyszukiwarkę nawigacji;
- `AdminView` dostarcza wspólny shell, ikony SVG, tytuły i kontekst klienta;
- moduły nie powinny deklarować własnych kolorów przycisków, tabel ani pól,
  jeżeli istnieje odpowiedni komponent wspólny.

## Reguły komponentów

### Nawigacja

Sidebar ma 244 px szerokości. Aktywna pozycja używa fioletowego gradientu i
białej ikony. Grupy menu mają krótkie etykiety pisane kapitalikami. Na
telefonie sidebar jest panelem wysuwanym, a na desktopie można go zwinąć.

### Nagłówek

Górny pasek zawiera wyszukiwarkę sekcji CMS, przejście do strony publicznej i
menu konta. Tytuł bieżącego ekranu oraz jego krótki opis znajdują się na
początku treści.

### Karty i metryki

Karty mają białe tło, obramowanie `1px`, promień `10px` i najwyżej lekki cień.
Metryki są kompaktowe, a ich liczba jest elementem o najwyższym kontraście.

### Przyciski

- podstawowy: fioletowe tło i biały tekst;
- drugorzędny: białe tło, szare obramowanie;
- destrukcyjny: biały lub jasnoczerwony, czerwony tekst;
- jeden ekran nie powinien mieć kilku równorzędnych akcji podstawowych.

### Formularze

Etykieta znajduje się nad polem. Focus zawsze ma widoczny fioletowy obrys.
Formularze wielosekcyjne używają kart lub elementów `details`, a pasek zapisu
może pozostać przyklejony do dołu ekranu.

### Tabele

Nagłówki tabel mają subtelne szare tło i nie używają krzykliwych kapitalików.
Status jest małym znacznikiem semantycznym. Na wąskich ekranach tabela przewija
się lokalnie, nie poszerzając całego panelu.

### Dostępność

- wszystkie interakcje muszą działać klawiaturą;
- focus nie może być usuwany bez zamiennika;
- ikony dekoracyjne są ukryte przed czytnikiem;
- elementy mobilne mają etykiety `aria`;
- `prefers-reduced-motion` ogranicza animacje i przejścia.
