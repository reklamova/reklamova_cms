# Google Consent Mode v2

Privacy Center ustawia domyslny stan Consent Mode przed ladowaniem skryptow analitycznych i marketingowych.

## Stan domyslny przed zgoda

```json
{
  "ad_storage": "denied",
  "ad_user_data": "denied",
  "ad_personalization": "denied",
  "analytics_storage": "denied",
  "functionality_storage": "denied",
  "personalization_storage": "denied",
  "security_storage": "granted"
}
```

Ten snippet jest wstrzykiwany w hooku `head`, przed `consent-manager.js` i przed publicznymi skryptami.

## Mapowanie kategorii

- `necessary`: `security_storage`
- `analytics`: `analytics_storage`
- `marketing`: `ad_storage`, `ad_user_data`, `ad_personalization`
- `functional`: `functionality_storage`
- `personalization`: `personalization_storage`

Po decyzji uzytkownika JS wywoluje:

```js
gtag('consent', 'update', state);
```

## Test reczny

1. Otworz strone w trybie prywatnym.
2. W konsoli sprawdz `window.ReklamovaConsentModeDefault`.
3. Przed decyzja kategorie marketing/analityka musza byc `denied`.
4. Kliknij `Akceptuje wszystko`.
5. Sprawdz `window.ReklamovaConsent.getState().state`.
6. Odpowiednie pola powinny przejsc na `granted`.
