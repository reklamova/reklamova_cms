<?php

declare(strict_types=1);

namespace Reklamova\Cms\Auth;

use PDO;
use Reklamova\Cms\Support\Config;

final class PermissionManager
{
    public const ALL_PERMISSIONS = [
        'view_dashboard',
        'view_update_notice',
        'manage_pages',
        'manage_media',
        'manage_forms',
        'manage_blog',
        'manage_products',
        'manage_basic_settings',
        'manage_basic_seo',
        'manage_advanced_seo',
        'manage_modules',
        'manage_themes',
        'manage_updates',
        'manage_backups',
        'view_logs',
        'view_health',
        'manage_users',
        'manage_permissions',
        'manage_privacy',
        'manage_privacy_scripts',
        'view_developer_tools',
    ];

    private const ROLE_PERMISSIONS = [
        'super_admin' => self::ALL_PERMISSIONS,
        'reklamova_admin' => self::ALL_PERMISSIONS,
        'reklamova' => self::ALL_PERMISSIONS,
        'developer' => self::ALL_PERMISSIONS,
        'client_admin' => [
            'view_dashboard',
            'view_update_notice',
            'manage_pages',
            'manage_media',
            'manage_forms',
            'manage_blog',
            'manage_products',
            'manage_basic_settings',
            'manage_basic_seo',
            'manage_privacy',
        ],
        'admin' => [
            'view_dashboard',
            'view_update_notice',
            'manage_pages',
            'manage_media',
            'manage_forms',
            'manage_blog',
            'manage_products',
            'manage_basic_settings',
            'manage_basic_seo',
            'manage_privacy',
        ],
        'editor' => [
            'view_dashboard',
            'view_update_notice',
            'manage_pages',
            'manage_media',
            'manage_blog',
            'manage_basic_seo',
        ],
        'seo' => [
            'view_dashboard',
            'view_update_notice',
            'manage_pages',
            'manage_media',
            'manage_basic_seo',
            'manage_advanced_seo',
        ],
        'marketing' => [
            'view_dashboard',
            'view_update_notice',
            'manage_pages',
            'manage_media',
            'manage_blog',
            'manage_privacy',
            'manage_privacy_scripts',
        ],
    ];

    public function __construct(private PDO $pdo, private array $container)
    {
    }

    public function can(?array $user, string $permission): bool
    {
        if (!$user || !in_array($permission, self::ALL_PERMISSIONS, true)) {
            return false;
        }

        if ($this->isInternalUser($user)) {
            return true;
        }

        $permissions = $this->permissionsForUser($user);

        return in_array($permission, $permissions, true);
    }

    public function require(?array $user, string $permission): void
    {
        if (!$this->can($user, $permission)) {
            throw new \RuntimeException('Brak uprawnienia: ' . $permission);
        }
    }

    public function requirePermission(?array $user, string $permission): void
    {
        $this->require($user, $permission);
    }

    /**
     * @param array<string, mixed> $menuItem
     */
    public function canSeeMenuItem(?array $user, array $menuItem): bool
    {
        if (!$user) {
            return false;
        }

        $permission = (string) ($menuItem['permission'] ?? 'view_dashboard');
        if (!$this->can($user, $permission)) {
            return false;
        }

        if (!empty($menuItem['internal_only']) && !$this->isInternalUser($user)) {
            return false;
        }

        if ($this->isInternalUser($user)) {
            return !array_key_exists('visible_in_admin_nav', $menuItem) || (bool) $menuItem['visible_in_admin_nav'];
        }

        return !array_key_exists('visible_in_client_nav', $menuItem) || (bool) $menuItem['visible_in_client_nav'];
    }

    /**
     * @param array<string, mixed> $module
     */
    public function canAccessModule(?array $user, array $module): bool
    {
        if (!$user || empty($module['enabled'])) {
            return false;
        }

        $permissions = $module['permissions'] ?? [];
        if (is_string($permissions)) {
            $permissions = [$permissions];
        }
        if (!is_array($permissions) || $permissions === []) {
            $permissions = ['view_dashboard'];
        }

        foreach ($permissions as $permission) {
            if ($this->can($user, (string) $permission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, string>
     */
    public function permissionsForUser(array $user): array
    {
        $role = $this->normalizedRole($user);
        $permissions = self::ROLE_PERMISSIONS[$role] ?? self::ROLE_PERMISSIONS['editor'];
        $permissions = array_values(array_unique(array_merge($permissions, $this->rolePermissionsFromDatabase($role), $this->userPermissionsFromDatabase((int) ($user['id'] ?? 0)))));

        return array_values(array_intersect($permissions, self::ALL_PERMISSIONS));
    }

    public function normalizedRole(array $user): string
    {
        $role = strtolower((string) ($user['role'] ?? 'editor'));
        if ($role === '') {
            return 'editor';
        }

        if ($role === 'administrator') {
            return 'admin';
        }

        return $role;
    }

    public function isInternalUser(array $user): bool
    {
        $role = $this->normalizedRole($user);
        $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
        $appUrl = strtolower((string) (new Config($this->container))->get('app', 'url', ''));

        return in_array($role, ['super_admin', 'reklamova_admin', 'reklamova', 'developer'], true)
            || str_contains($host, 'cms.reklamova.pl')
            || str_contains($appUrl, 'cms.reklamova.pl');
    }

    /**
     * @return array<int, string>
     */
    private function rolePermissionsFromDatabase(string $role): array
    {
        try {
            $statement = $this->pdo->prepare(
                'SELECT p.slug
                 FROM cms_role_permissions rp
                 INNER JOIN cms_permissions p ON p.id = rp.permission_id
                 WHERE rp.role = ?'
            );
            $statement->execute([$role]);
            return $statement->fetchAll(PDO::FETCH_COLUMN) ?: [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return array<int, string>
     */
    private function userPermissionsFromDatabase(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        try {
            $statement = $this->pdo->prepare(
                'SELECT p.slug
                 FROM cms_user_permissions up
                 INNER JOIN cms_permissions p ON p.id = up.permission_id
                 WHERE up.user_id = ? AND up.allowed = 1'
            );
            $statement->execute([$userId]);
            return $statement->fetchAll(PDO::FETCH_COLUMN) ?: [];
        } catch (\Throwable) {
            return [];
        }
    }
}
