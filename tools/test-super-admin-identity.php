<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/core/Auth/SuperAdminIdentity.php';

use Reklamova\Cms\Auth\SuperAdminIdentity;

if (SuperAdminIdentity::EMAIL !== 'biuro@reklamova.pl') {
    throw new RuntimeException('Nieprawidłowy kanoniczny adres superadministratora.');
}
if (SuperAdminIdentity::LEGACY_EMAILS !== ['admin@reklamova.pl']) {
    throw new RuntimeException('Brak kontrolowanej migracji starego loginu.');
}

$migration = file_get_contents(dirname(__DIR__) . '/app/migrations/core/2026_09_09_000009_canonical_super_admin_identity.php');
if (!is_string($migration)) {
    throw new RuntimeException('Nie można odczytać migracji konta superadministratora.');
}
foreach (['SuperAdminIdentity::EMAIL', 'SuperAdminIdentity::LEGACY_EMAILS', 'role = "super_admin"', 'active = 1'] as $check) {
    if (!str_contains($migration, $check)) {
        throw new RuntimeException('Migracja nie utrwala tożsamości superadministratora: ' . $check);
    }
}

$installer = file_get_contents(dirname(__DIR__) . '/app/core/Install/InstallController.php');
if (!is_string($installer) || !str_contains($installer, 'SuperAdminIdentity::EMAIL') || !str_contains($installer, '"super_admin"')) {
    throw new RuntimeException('Instalator nie używa kanonicznego konta superadministratora.');
}

echo "SUPER_ADMIN_IDENTITY_TEST_OK\n";
