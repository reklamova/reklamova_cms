# Privacy Center 0.8.0-rc3

Data testu: 2026-07-30

Środowiska:

- `https://cms-rc1.reklamova.pl`,
- `https://staging.mero.pl`,
- `https://staging.powertechsc.pl`.

## Integracja i routing

Na wszystkich trzech stagingach potwierdzono:

- `GET /api/privacy/settings`: HTTP 200,
- `GET /api/privacy/scripts`: HTTP 200,
- `/ustawienia-prywatnosci`: HTTP 200,
- legacy `/ustawienia-prywatności`: HTTP 200,
- `/polityka-prywatnosci`: HTTP 200,
- `/polityka-cookies`: HTTP 200,
- dokładnie jeden root, config, CSS i JS Privacy Center,
- domyślny Google Consent Mode przed `consent-manager.js`,
- brak GA, GTM, Meta Pixel, Clarity i Hotjar przed zgodą.

Publiczne fallbacki MERO (`/poradnik`, `/kalkulator-budowy-domu`) oraz
PowerTech (`/o-nas/`, `/nasza-oferta/`) zachowały pojedyncze hooki Privacy
Center. Nie występuje drugi ani historyczny popup.

## Test w realnym silniku Chrome

Automatyczny test wykonano w izolowanych kontekstach Chromium dla każdego
stagingu.

Wyniki na wszystkich instalacjach:

- pierwszy baner jest jedną instancją,
- `Odrzucam` zapisuje decyzję po przeładowaniu,
- `Akceptuję wszystko` zapisuje pięć kategorii po przeładowaniu,
- `Dostosuj` zapisuje indywidualny wybór kategorii,
- baner nie wraca po poprawnie zapisanej decyzji,
- link `Ustawienia prywatności` otwiera jeden modal,
- skrypty oznaczone jako zależne od zgody nie startują przed decyzją,
- nie zarejestrowano żądań do znanych trackerów przed zgodą.

## Prywatnościowy log decyzji

Test utworzył:

- 9 syntetycznych decyzji na clean CMS,
- 3 na MERO,
- 3 na PowerTech.

Każdy rekord miał 64-znakowy hash IP i user-agent oraz poprawny JSON kategorii
i stanu zgody. Rekordy syntetyczne usunięto po teście. Liczniki wróciły do
wartości sprzed walidacji:

- clean CMS: 2,
- MERO: 1,
- PowerTech: 16.

Status Privacy Center: **ZALICZONY**.
