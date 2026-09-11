<?php

declare(strict_types=1);

return new class {
    public function up(PDO $pdo): void
    {
        $pdo->exec(
            'ALTER TABLE commerce_notification_deliveries
             ADD UNIQUE KEY uq_commerce_notification_delivery (outbox_id, channel, template_key)'
        );
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE commerce_notification_deliveries DROP INDEX uq_commerce_notification_delivery');
    }
};
