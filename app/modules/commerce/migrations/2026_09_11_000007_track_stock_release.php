<?php

declare(strict_types=1);

return new class {
    public function up(PDO $pdo): void
    {
        $pdo->exec(
            'ALTER TABLE commerce_orders
             ADD COLUMN stock_released_at TIMESTAMP NULL AFTER cancelled_at,
             ADD KEY idx_commerce_order_stock_release (order_status, stock_released_at)'
        );
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE commerce_orders DROP INDEX idx_commerce_order_stock_release');
        $pdo->exec('ALTER TABLE commerce_orders DROP COLUMN stock_released_at');
    }
};
