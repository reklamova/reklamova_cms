# Reklamova Page Studio

Page Studio jest core'owym modułem zarządzania podstronami. Nie jest częścią motywu klienta, więc po aktualizacji core trafia do każdej instalacji Reklamova CMS bez nadpisywania uploadów, konfiguracji i motywów.

## Co jest gotowe

- lista podstron w `/admin/pages`,
- tworzenie, edycja, duplikowanie i usuwanie podstron,
- statusy: szkic, opublikowana, archiwum,
- pola SEO: meta title, meta description, canonical, robots, obraz wyróżniający,
- menu: pokaż w menu, etykieta, kolejność,
- szablony: standard, landing, dokument prawny, szeroka strona,
- konstruktor sekcji bez kodowania,
- publiczny renderer bloków,
- podgląd roboczy w panelu,
- historia rewizji i przywracanie poprzedniej wersji,
- integracja z zakładką skryptów i prywatności dla konkretnej podstrony.

## Sekcje strony

Obsługiwane typy bloków:

- `hero`,
- `text`,
- `image_text`,
- `cards`,
- `faq`,
- `cta`,
- `html`.

Bloki `cards` i `faq` używają pola elementów w formacie jedna linia na element. Separator pól to `|`.

Przykład kart:

```text
Projektowanie stron | Nowoczesne strony firmowe i landing pages | /kontakt
SEO lokalne | Podstrony pod miasta i usługi | /oferta
```

Przykład FAQ:

```text
Czy mogę edytować stronę samodzielnie? | Tak, podstrony edytuje się w Page Studio.
Czy aktualizacja core nadpisze treści? | Nie, treści klienta są w bazie i nie są częścią paczki core.
```

## Zasada aktualizacji

Page Studio znajduje się w `app/core/Pages` oraz w migracji core. Paczka aktualizacji może podmienić pliki core, ale nie wolno jej usuwać ani nadpisywać:

- `app/config`,
- `app/themes`,
- `app/modules/custom`,
- `public/uploads`,
- `app/storage/backups`,
- `app/storage/logs`.

## Test ręczny

1. Wejdź w `/admin/pages`.
2. Utwórz nową podstronę jako szkic.
3. Dodaj sekcję `hero`, `text`, `cards` i `cta`.
4. Uzupełnij meta title oraz meta description.
5. Kliknij podgląd i sprawdź renderowanie.
6. Opublikuj stronę.
7. Wejdź na publiczny URL.
8. Zmień treść, zapisz i sprawdź, czy pojawiła się rewizja.
9. Przywróć rewizję i sprawdź, czy treść wróciła.
10. Zduplikuj stronę i upewnij się, że kopia jest szkicem i nie trafia automatycznie do menu.
