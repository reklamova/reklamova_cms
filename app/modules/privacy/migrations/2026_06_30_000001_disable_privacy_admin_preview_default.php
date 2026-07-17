<?php

return new class {
    public function up(PDO $pdo): void
    {
        $statement = $pdo->prepare(
            'UPDATE privacy_settings
             SET value = ?, updated_at = CURRENT_TIMESTAMP
             WHERE `key` = ? AND value IN (?, ?)'
        );
        $statement->execute([
            json_encode(false, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'test_mode_admin_always_show',
            json_encode(true),
            'true',
        ]);
    }

    public function down(PDO $pdo): void
    {
    }
};
