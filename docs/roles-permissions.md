# Role i uprawnienia

RBAC w core opiera się o role, uprawnienia i kompatybilne mapowanie starych kont.

## Role

- `super_admin`: pełny dostęp Reklamova.
- `reklamova_admin`: prawie pełny dostęp techniczny Reklamova.
- `client_admin`: obsługa strony klienta.
- `editor`: strony, wpisy i media.
- `seo`: SEO podstawowe i zaawansowane.
- `marketing`: treści marketingowe i prywatność/skrypty, jeżeli włączone.

Stara rola `admin` jest traktowana jak `client_admin`, chyba że instalacja działa na `cms.reklamova.pl`.

## Uprawnienia

- `view_dashboard`
- `view_update_notice`
- `manage_pages`
- `manage_media`
- `manage_forms`
- `manage_blog`
- `manage_products`
- `manage_basic_settings`
- `manage_basic_seo`
- `manage_advanced_seo`
- `manage_modules`
- `manage_themes`
- `manage_updates`
- `manage_backups`
- `view_logs`
- `view_health`
- `manage_users`
- `manage_permissions`
- `manage_privacy`
- `manage_privacy_scripts`
- `view_developer_tools`

## Tabele

- `cms_permissions`
- `cms_role_permissions`
- `cms_user_permissions`

## Helpery

- `PermissionManager::can()`
- `PermissionManager::requirePermission()`
- `PermissionManager::canSeeMenuItem()`
- `PermissionManager::canAccessModule()`

## Test ręczny

1. `client_admin` nie widzi grupy `Reklamova / techniczne`.
2. `super_admin` widzi wszystkie ekrany.
3. `seo` widzi zaawansowane SEO.
4. `editor` nie widzi zaawansowanego SEO ani aktualizacji.
