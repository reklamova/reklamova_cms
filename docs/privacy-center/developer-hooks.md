# Developer Hooks

Privacy Center integruje sie z publicznym layoutem przez hooki modulow i helpery motywu.

## Helpery

```php
privacy_head();
privacy_body_start();
privacy_body_end();
privacy_footer_link();
```

W standardowym renderowaniu CMS hooki sa wywolywane automatycznie przez `ModuleManager` i `Application`. Motyw klienta moze ich uzyc jawnie, jezeli ma wlasny layout.

## Public API

```http
GET /api/privacy/settings
POST /api/privacy/consent
GET /api/privacy/scripts
GET /api/privacy/document/polityka-prywatnosci
```

`GET /api/privacy/scripts` zwraca tylko aktywne publiczne skrypty dopasowane do biezacego URL, kategorii i ustawien awaryjnych.

## Global JS

```js
window.ReklamovaConsent.openSettings();
window.ReklamovaConsent.getState();
window.ReklamovaConsent.hasConsent('analytics');
window.ReklamovaConsent.updateConsent({ analytics: true, marketing: false });
```

## Eventy

- `reklamovaConsentReady`
- `reklamovaConsentUpdated`
- `reklamovaConsentCategoryGranted`

## Kolejnosc ladowania

1. `privacy_head()` ustawia Consent Mode default.
2. `consent-manager.js` pobiera ustawienia.
3. Baner pokazuje sie, jesli nie ma decyzji lub wersja zgody sie zmienila.
4. Dopiero po decyzji ladowane sa skrypty z zaakceptowanych kategorii.
