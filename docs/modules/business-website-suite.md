# Reklamova Business Website Suite

## Wnioski z analizy stron firmowych

Nowoczesna strona firmowa nie jest katalogiem podstron. Jest systemem sprzedaży i zaufania. Najczęściej powtarzające się elementy dobrych stron biznesowych:

- jasna oferta i podstrony usług,
- lokalne podstrony SEO dla firm działających regionalnie,
- szybki kontakt i formularze leadowe,
- dowody zaufania: opinie, realizacje, case studies, certyfikaty,
- FAQ przypisane do konkretnych usług,
- landing page dla kampanii,
- blog, poradnik lub baza wiedzy,
- schema.org dla firmy, usług, lokalizacji i FAQ,
- prywatnościowe zarządzanie skryptami oraz zgodami,
- panel, który mówi językiem klienta, a nie technicznego CMS-a.

## Moduły core

### 1. Reklamova Business Website Kit

Status: MVP wdrożone.

Panel:

- `/admin/business`
- `/admin/business/services`
- `/admin/business/areas`
- `/admin/business/cases`
- `/admin/business/testimonials`
- `/admin/business/faqs`
- `/admin/business/team`
- `/admin/business/ctas`

Publiczne trasy:

- `/uslugi`
- `/uslugi/{slug}`
- `/realizacje`
- `/realizacje/{slug}`
- `/lokalizacje/{slug}`
- `/zespol`

Helpery dla motywów:

- `business_services()`
- `business_service_areas()`
- `business_case_studies()`
- `business_testimonials()`
- `business_faqs($scopeType, $scopeSlug)`
- `business_cta($placement)`

### 2. Reklamova Leads

Status: MVP wdrożone.

Panel:

- `/admin/leads`

API:

- `POST /api/leads`

Cel:

- jedna skrzynka zapytań z formularzy kontaktowych, kalkulatorów, landing page i kampanii,
- statusy leadów: nowy, w toku, wygrany, przegrany, spam, archiwum,
- prywatnościowy zapis IP i user agenta jako hash.

## Następne moduły do wdrożenia

### 3. Landing Pages

Cel:

- szybkie tworzenie landing page dla kampanii Google Ads, Meta Ads, LinkedIn i mailingów.

Funkcje:

- hero,
- sekcje korzyści,
- formularz,
- FAQ,
- opinie,
- CTA,
- warianty A/B,
- integracja z Privacy Center i Leads.

### 4. Knowledge Hub

Cel:

- poradnik, blog, baza wiedzy, aktualności i treści SEO.

Funkcje:

- kategorie,
- tagi,
- autorzy,
- powiązane usługi,
- powiązane lokalizacje,
- schema Article,
- spis treści,
- status aktualności treści.

### 5. Local SEO Engine

Cel:

- skalowalne strony lokalne dla firm usługowych.

Funkcje:

- miejscowości i regiony,
- szablony treści lokalnych,
- unikanie duplikacji,
- LocalBusiness schema,
- powiązanie lokalizacji z usługami,
- mapa i dane NAP.

### 6. Trust Center

Cel:

- certyfikaty, partnerzy, nagrody, liczby, referencje i pliki do pobrania.

Funkcje:

- biblioteka certyfikatów,
- logotypy partnerów,
- wskaźniki typu lata doświadczenia i liczba realizacji,
- pliki PDF,
- widoczność per podstrona.

### 7. Conversion Studio

Cel:

- zarządzanie elementami konwersji bez grzebania w kodzie.

Funkcje:

- sticky CTA,
- belki promocyjne,
- popupy zgodne z UX,
- mikroankiety,
- CTA per usługa/lokalizacja,
- pomiar kliknięć.

## Zasada projektowa

Każdy moduł ma dostarczać konkretny obiekt biznesowy. Klient nie powinien widzieć w panelu abstrakcji typu "blok HTML", jeśli może dostać ekran "Usługi", "Opinie", "Realizacje" albo "Leady".
