# Client Admin UX

Reklamova CMS ma pokazywac klientowi kokpit do obslugi strony, a nie techniczna maszynownie.

## Klient widzi

- Start.
- Strony.
- Media.
- Formularze, blog i produkty tylko wtedy, gdy aktywny modul i rola ma uprawnienie.
- Ustawienia strony.
- Prywatnosc i cookies w zakresie podstawowym.

## Reklamova widzi

- Funkcje strony.
- Motyw core.
- Aktualizacje CMS.
- Stan techniczny.
- Narzedzia developerskie i logi.

## Zasady ekranow

- SEO na stronie jest schowane w akordeonie `Ustawienia SEO`.
- Zaawansowane SEO wymaga `manage_advanced_seo`.
- Ekrany techniczne wymagaja roli Reklamova albo odpowiedniego uprawnienia.
- Nie pokazujemy pustych modulow klientowi.

## Reczna kontrola

1. Zaloguj sie jako `client_admin`.
2. Sprawdz, ze nie widac: Funkcje strony, Motyw core, Aktualizacje CMS, Stan techniczny.
3. Wejdz w edycje strony i potwierdz, ze SEO jest zwiniete.
4. Zaloguj sie jako `super_admin` i sprawdz, ze grupa `Reklamova / Techniczne` jest widoczna.
