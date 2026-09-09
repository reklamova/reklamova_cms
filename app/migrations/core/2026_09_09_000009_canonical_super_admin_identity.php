<?php

declare(strict_types=1);

use Reklamova\Cms\Auth\SuperAdminIdentity;

return new class {
    public function up(PDO $pdo): void
    {
        $find = $pdo->prepare('SELECT id FROM cms_users WHERE LOWER(email) = LOWER(?) LIMIT 1');
        $find->execute([SuperAdminIdentity::EMAIL]);
        $canonicalId = $find->fetchColumn();

        if ($canonicalId !== false) {
            $pdo->prepare('UPDATE cms_users SET role = "super_admin", active = 1 WHERE id = ?')
                ->execute([(int) $canonicalId]);
            return;
        }

        foreach (SuperAdminIdentity::LEGACY_EMAILS as $legacyEmail) {
            $find->execute([$legacyEmail]);
            $legacyId = $find->fetchColumn();
            if ($legacyId === false) {
                continue;
            }

            $pdo->prepare('UPDATE cms_users SET email = ?, role = "super_admin", active = 1 WHERE id = ?')
                ->execute([SuperAdminIdentity::EMAIL, (int) $legacyId]);
            return;
        }
    }
};
