# Privacy Center 0.8.0-rc2

Data testu: 2026-07-29

## Testy integracyjne clean CMS, MERO i PowerTech

Na wszystkich trzech stagingach potwierdzono:

- `GET /api/privacy/settings`: HTTP 200,
- aktywny moduł i pięć kategorii zgód,
- `GET /api/privacy/scripts`: HTTP 200,
- dokładnie jeden root, config, CSS i JS Privacy Center,
- domyślny Consent Mode przed `consent-manager.js`,
- brak GA, GTM, Meta Pixel, Clarity i Hotjar w HTML przed zgodą,
- `/ustawienia-prywatnosci`: HTTP 200,
- legacy `/ustawienia-prywatności`: HTTP 200,
- link stopki otwierający ustawienia prywatności.

Tryby banera odczytane z konfiguracji:

- Clean CMS: `modal`, styl `minimal`,
- MERO: `corner_box`, styl `minimal`,
- PowerTech: `modal`, styl `minimal`.

Na każdej instalacji zapisano przez publiczne API trzy decyzje:

- odrzucenie kategorii opcjonalnych,
- akceptację wszystkich kategorii,
- wybór niestandardowy.

Wszystkie żądania zwróciły HTTP 200 i `ok: true`. Rekordy zawierały 64-znakowe
hashe IP i user-agent zamiast wartości jawnych. Dziewięć rekordów testowych
usunięto po weryfikacji.

Na clean CMS dodatkowo sprawdzono logowanie `client_admin` i `super_admin`,
dynamiczne menu, Strony, Media, Privacy Center oraz ekran systemowy. Wszystkie
oczekiwane trasy zwróciły HTTP 200.

## MERO

- stary klucz `mero_cookie_consent_v1` nie występuje,
- stary endpoint `/api/mero/consent` nie występuje,
- frontend ładuje tylko systemowy Privacy Center,
- formularz kalkulatora pobiera snapshot z `window.ReklamovaConsent`,
- testowy lead zapisał UUID, wersję oraz wybrane kategorie,
- rekord testowy został usunięty,
- konfiguracja e-mail została po teście przywrócona.

## Test przeglądarkowy

Automatyczne sterowanie przeglądarką nie jest dostępne w bieżącej sesji Codex.
Nie wykonano więc wymaganego wizualnego testu kliknięć i trwałości
`localStorage`/cookie po odświeżeniu.

Do ręcznego potwierdzenia na każdym stagingu:

1. Otworzyć stronę w nowym profilu prywatnym.
2. Kliknąć `Odrzucam`, przeładować i przejść na podstronę.
3. Sprawdzić, że baner nie wraca.
4. Otworzyć ustawienia ze stopki i zaakceptować wszystkie kategorie.
5. Ponownie przeładować i sprawdzić stan.
6. Powtórzyć wybór niestandardowy.

Status: **CZĘŚCIOWO ZALICZONY, BLOKADA MERGE DO TESTU PRZEGLĄDARKOWEGO**.
