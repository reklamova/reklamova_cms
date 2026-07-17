# Reklamova Growth Modules

Ten dokument opisuje kolejne moduły core dodane po `Business Website Kit` i `Leads`.

## Reklamova Page Studio

Cel: pełne zarządzanie podstronami CMS bez edycji kodu.

Panel:

- `/admin/pages`
- `/admin/pages/edit`
- `/admin/pages/preview`

Funkcje MVP:

- lista podstron,
- tworzenie, edycja, usuwanie i duplikowanie,
- statusy publikacji,
- konstruktor sekcji,
- pola SEO,
- ustawienia menu,
- podgląd roboczy,
- historia wersji i przywracanie,
- publiczny renderer bloków,
- integracja z Privacy Center per podstrona.

## Reklamova Knowledge Hub

Cel: poradnik, blog, baza wiedzy, aktualności i treści SEO.

Panel:

- `/admin/knowledge`
- `/admin/knowledge/categories`
- `/admin/knowledge/authors`

Publiczne trasy:

- `/poradnik`
- `/poradnik/{slug}`

Funkcje MVP:

- artykuły,
- kategorie,
- autorzy,
- wyszukiwarka publiczna,
- schema.org Article,
- relacja artykułu z usługą i lokalizacją przez slug,
- helpery `knowledge_articles()` i `knowledge_categories()`.

## Reklamova Landing Pages

Cel: szybkie strony kampanijne i sprzedażowe pod reklamy oraz dedykowane oferty.

Panel:

- `/admin/landing-pages`

Publiczne trasy:

- `/lp/{slug}`

Funkcje MVP:

- hero,
- benefity,
- sekcje sprzedażowe,
- FAQ,
- formularz leadowy z akcją `/api/leads`,
- źródło kampanii,
- pola SEO,
- helper `landing_pages()`.

## Reklamova Trust Center

Cel: dowody zaufania, które można pokazać na homepage, usługach, landing page i osobnej stronie zaufania.

Panel:

- `/admin/trust`

Publiczne trasy:

- `/zaufanie`

Typy elementów:

- liczba,
- certyfikat,
- partner,
- nagroda,
- plik do pobrania.

Funkcje MVP:

- status publikacji,
- wyróżnienie,
- kolejność,
- obraz/logo,
- plik PDF,
- link zewnętrzny,
- helper `trust_items($type)`.

## Kolejne kroki

Następne logiczne moduły:

- `Conversion Studio`: sticky CTA, belki, pop-upy UX, pomiar kliknięć.
- `Local SEO Engine`: generowanie lokalnych landing page na bazie usług i lokalizacji.
- `Media Pro`: biblioteka assetów z altami, focus point, WebP i wariantami.
- `Redirects & SEO Ops`: przekierowania 301, sitemap, robots, kanoniczne adresy i monitoring 404.
