<?php

declare(strict_types=1);

return new class {
    public function up(PDO $pdo): void
    {
        $pdo->exec(
            'ALTER TABLE commerce_orders
             ADD COLUMN checkout_key CHAR(64) NULL AFTER customer_id,
             ADD COLUMN consents_json JSON NULL AFTER customer_note,
             ADD UNIQUE KEY uq_commerce_order_checkout (checkout_key)'
        );
        $pdo->exec(
            'ALTER TABLE commerce_coupon_redemptions
             ADD COLUMN customer_email_hash CHAR(64) NULL AFTER customer_id,
             ADD KEY idx_commerce_coupon_email (coupon_id, customer_email_hash)'
        );
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE commerce_coupon_redemptions DROP INDEX idx_commerce_coupon_email, DROP COLUMN customer_email_hash');
        $pdo->exec(
            'ALTER TABLE commerce_orders
             DROP INDEX uq_commerce_order_checkout, DROP COLUMN consents_json, DROP COLUMN checkout_key'
        );
    }
};
