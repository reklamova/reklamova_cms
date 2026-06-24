# MERO installation

MERO uses Reklamova CMS as a self-hosted installation with the shared core admin UI.
The client-specific functionality lives in `app/modules/custom/mero`.

## Activation

During installation set:

```text
active_modules: mero
```

In `app/config/app.php` this means:

```php
'client_name' => 'MERO',
'client_logo' => '/assets/client/mero-logo.svg',
'active_modules' => ['mero'],
```

The admin login screen is always rendered by core. `client_logo` is used only
as dynamic installation branding in the shared `client x Reklamova` login.

The module migration creates:

- `mero_leads` for contact and calculator enquiries,
- `mero_articles` for the public investor guide,
- `mero_cookie_consents` for cookie consent audit events,
- `cms_settings` key `mero.calculator` for calculator pricing settings,
- seed CMS pages for MERO public sections.

## Admin panel

The admin panel always uses the core visual layer from:

- `app/core/Admin/AdminView.php`
- `public/assets/core/admin.css`
- `public/assets/core/reklamova-logo.svg`

MERO only adds menu entries and screens:

- `/admin/mero/calculator`
- `/admin/mero/leads`
- `/admin/mero/articles`

Do not create a separate MERO admin theme. Updating the core admin CSS must update the admin look for all installations.

## Public compatibility

The lead endpoint is available at both:

- `/api/mero/lead`
- `/api/lead.php`

The second path keeps compatibility with the previous MERO build.

The public cookie consent endpoint is available at:

- `/api/mero/consent`

The MERO public layer includes the current phone number `720 446 446`, footer Facebook link
`https://www.facebook.com/merobielany`, SEO metadata, structured data, a blocking cookie
popup, updated privacy microcopy and an embedded map on the contact page.
