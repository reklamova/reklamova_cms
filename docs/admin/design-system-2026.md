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

## Wdrożenie PowerTech z 2026-08-12

Warstwa wizualna i nowa biblioteka mediów zostały wdrożone z commitów:

- `c461d63dbd9dc7eeae48a9d9b59d45d42e6a8aa4` — wspólny shell i design system;
- `a10145e03165bacbe0a0d201ccb5595ab9d65865` — siatka mediów, filtry,
  wyszukiwanie i paginacja;
- `594c242d4e4c6330e6094f3b85bddca9203d0c2e` — wersjonowanie assetów z
  instalacji publikujących katalog `/assets` bezpośrednio z katalogu głównego.

Zweryfikowano:

- dashboard, listę 21 podstron, bibliotekę 1438 mediów oraz edytor produktu z
  galerią;
- wyszukiwarkę nawigacji, menu konta i zwijanie sidebara;
- widok desktopowy oraz breakpoint mobilny 390 × 844 px bez poziomego
  przepełnienia;
- osobno rolę `reklamova_admin` i `client_admin`; konto klienta nie widzi
  modułów, motywów, aktualizacji ani stanu systemu;
- 24-elementową paginację mediów, zakładki typów oraz wyszukiwanie;
- zgodność SHA-256 CSS i JavaScript pomiędzy repozytorium, stagingiem i
  produkcją;
- HTTP 200 dla strony, logowania i nowych assetów oraz HTTP 401 dla
  anonimowego wejścia na staging;
- brak nowych błędów `Fatal`, `Uncaught` i `Parse` w logach obu środowisk.

Kopie rollback:

- `/home/platne/serwer38522/backups/powertech-admin-design-staging-correct-20260812_145949`;
- `/home/platne/serwer38522/backups/powertech-admin-media-staging-20260812_150447`;
- `/home/platne/serwer38522/backups/powertech-admin-design-production-20260812_151144`;
- `/home/platne/serwer38522/backups/powertech-admin-asset-version-20260812_151355`.

Wszystkie cztery kopie mają tryb `700` i przeszły kontrolę `sha256sum -c`.
Po testach usunięto konta techniczne, wpis Basic Auth, helpery, checkout oraz
lokalne pliki dostępowe.
