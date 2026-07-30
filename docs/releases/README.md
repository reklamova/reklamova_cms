# Wydania Reklamova CMS

## Aktualne wydanie

- wersja stable: `0.8.0`,
- package ID: `pkg_core_0_8_0`,
- tag Git: `v0.8.0`,
- raport wydania: [`0.8.0.md`](./0.8.0.md),
- rollout produkcyjny:
  [`0.8.0-production-rollout.md`](./0.8.0-production-rollout.md),
- plan rolloutu: [`0.8.0-rollout-plan.md`](./0.8.0-rollout-plan.md).

## Patche MERO

Patche modułu custom MERO są wdrażane niezależnie od paczki core:

- administracja:
  [`mero-admin-0.8.0-1.md`](./mero-admin-0.8.0-1.md),
- frontend i Privacy Center:
  [`mero-frontend-privacy-0.8.0-1-production.md`](./mero-frontend-privacy-0.8.0-1-production.md).

Paczka core stable nie zawiera `app/modules/custom/**`.

## Archiwum

Raporty RC1-RC4, checklisty stagingowe, testy migracji i walidacje pakietów są
zachowane w [`docs/archive/0.8.0-rc`](../archive/0.8.0-rc/).

## Następne wydanie

1. Utwórz branch funkcjonalny od aktualnego `main`.
2. Nie zmieniaj core pod pojedynczego klienta; użyj motywu albo modułu custom.
3. Dodaj migracje defensywne i testy kompatybilności wstecznej.
4. Zbuduj paczkę RC z allowlisty core i zweryfikuj podpis oraz protected paths.
5. Przetestuj RC na stałym stagingu CMS, MERO i PowerTech.
6. Dopiero po zamknięciu raportu walidacji wykonaj merge do `main`, zbuduj stable
   i zastosuj etapowy rollout.
