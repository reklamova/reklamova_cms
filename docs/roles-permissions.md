# Role i uprawnienia

## Role

`super_admin`

Pełny dostęp do core CMS, aktualizacji, modułów, motywów, backupów, logów i uprawnień.

`reklamova_admin`

Administracja techniczna i treściowa po stronie Reklamova. Może konfigurować stronę klienta i wykonywać aktualizacje.

`client_admin`

Codzienna obsługa strony klienta: treści, media, produkty, zapytania, podstawowe dane strony i podstawowa prywatność.

`editor`

Treści, media i podstawowe SEO bez technikaliów.

`seo`

Treści, media, podstawowe i zaawansowane SEO.

`marketing`

Treści kampanii, poradnik, opinie i wiarygodność oraz privacy scripts, jeśli Reklamova nada uprawnienie.

## Uprawnienia core

- `view_dashboard`
- `view_update_notice`
- `manage_pages`
- `manage_homepage`
- `manage_media`
- `manage_blog`
- `manage_products`
- `manage_product_categories`
- `manage_forms`
- `view_leads`
- `manage_inquiries`
- `manage_privacy_basic`
- `manage_privacy_scripts`
- `manage_reviews_trust`
- `manage_campaign_pages`
- `manage_basic_settings`
- `manage_basic_seo`
- `manage_advanced_seo`
- `manage_modules`
- `manage_theme`
- `manage_updates`
- `view_system_health`
- `manage_backups`
- `view_logs`
- `manage_users`
- `manage_permissions`
- `view_developer_tools`

## Helpery

- `can($permission)`
- `requirePermission($permission)`
- `canSeeMenuItem($item)`
- `canAccessModule($module)`
- `canManageTechnicalSettings()`

Każda trasa i każda akcja POST musi przechodzić przez uprawnienia backendowe. Ukrycie linku w menu nie wystarcza.
