# PowerTech staging Reklamova CMS 0.8.0-rc1

Data raportu: 2026-07-28

Status: ZABLOKOWANY INFRASTRUKTURALNIE

## Środowisko

Planowany adres: `staging.powertechsc.pl`

DNS kieruje na hosting PowerTech, ale `/` oraz `/admin/` zwracają 404. Nie
potwierdzono oddzielnego document root, stagingowej bazy ani blokady maili.
Działająca instalacja pod `nowastrona.powertechsc.pl` nie była modyfikowana.

## Wymagany zakres wdrożenia

- commit `403534fd0afd1d4261415a0f7446321085f59b03`,
- tylko allowlista core,
- migracje core,
- kanał `rc`,
- brak patcha MERO,
- brak `app/modules/custom/mero`.

Przed i po wdrożeniu należy porównać sumy `app/config`, `app/themes`,
`app/modules/custom` i `public/uploads`.

## Panel

- [ ] logowanie jako `client_admin`,
- [ ] logowanie jako `reklamova_admin`,
- [ ] menu klienta bez ekranów technicznych,
- [ ] menu Reklamova z ekranami technicznymi,
- [ ] Podstrony: lista, edycja, zapis i podgląd,
- [ ] Strona główna,
- [ ] Media: lista, upload i wybór obrazu,
- [ ] Produkty i Kategorie produktów, jeśli katalog jest aktywny,
- [ ] Privacy Center,
- [ ] `/admin/updates` zgodnie z uprawnieniem,
- [ ] `/admin/system` tylko dla Reklamova Admin,
- [ ] brak modułów i nazw MERO.

## Katalog

- [ ] lista produktów ma wyszukiwarkę, filtry i paginację,
- [ ] dodanie produktu działa w osobnym widoku,
- [ ] edycja produktu zachowuje kategorie, parametry i SEO,
- [ ] powielenie produktu tworzy niezależny szkic,
- [ ] lista kategorii nie renderuje jednocześnie wielkiego formularza,
- [ ] statusy są prezentowane po polsku,
- [ ] stare adresy panelu nadal działają albo przekierowują.

## Front

- [ ] strona główna,
- [ ] podstrony,
- [ ] drzewo kategorii,
- [ ] lista produktów kategorii,
- [ ] karta pojedynczego produktu,
- [ ] formularze bez realnej wysyłki e-mail,
- [ ] Privacy Center nie uruchamia trackerów,
- [ ] brak odwołań do plików MERO,
- [ ] brak błędów PHP w logu.

## Wynik

NIEURUCHOMIONY. Domenę trzeba przypisać do oddzielnego katalogu, utworzyć i
zaimportować oddzielną bazę, skopiować uploady oraz włączyć Basic Auth,
`noindex`, blokadę maili i blokadę trackingów. Nie wolno wykorzystać działającej
instalacji `nowastrona.powertechsc.pl` jako stagingu RC1.
