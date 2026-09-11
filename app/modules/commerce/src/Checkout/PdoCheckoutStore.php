<?php

declare(strict_types=1);

namespace Reklamova\Cms\Commerce\Checkout;

use PDO;
use Reklamova\Cms\Commerce\Orders\OrderStatus;
use Reklamova\Cms\Commerce\Orders\PaymentStatus;

final class PdoCheckoutStore implements CheckoutStoreInterface
{
    public function __construct(private PDO $pdo)
    {
    }

    public function transaction(callable $callback): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $callback();
            $this->pdo->commit();

            return $result;
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function existing(string $checkoutKey): ?CheckoutResult
    {
        $statement = $this->pdo->prepare(
            'SELECT id, order_number, order_status, payment_status, currency, subtotal_minor,
                    discount_minor, shipping_minor, net_minor, tax_minor, total_minor
             FROM commerce_orders WHERE checkout_key = ? LIMIT 1 FOR UPDATE'
        );
        $statement->execute([$checkoutKey]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->checkoutResult($row) : null;
    }

    public function lockQuote(string $cartToken, CheckoutData $data): CheckoutQuote
    {
        $cartStatement = $this->pdo->prepare(
            'SELECT c.id, c.store_id, c.customer_id, c.currency, c.version, c.status, c.expires_at,
                    s.code AS store_code, s.prices_include_tax
             FROM commerce_carts c
             INNER JOIN commerce_stores s ON s.id = c.store_id AND s.status = "active"
             WHERE c.token_hash = ? LIMIT 1 FOR UPDATE'
        );
        $cartStatement->execute([hash('sha256', $cartToken)]);
        $cart = $cartStatement->fetch(PDO::FETCH_ASSOC);
        if (!$cart || (string) $cart['status'] !== 'active') {
            throw new \DomainException('Cart is unavailable.');
        }
        if ($cart['expires_at'] !== null && strtotime((string) $cart['expires_at']) <= time()) {
            throw new \DomainException('Cart has expired.');
        }
        $this->assertCustomer($cart, $data);

        $itemStatement = $this->pdo->prepare(
            'SELECT ci.product_id, ci.variant_id, ci.quantity, ci.options_json, ci.configuration_hash,
                    p.type AS product_type, p.name AS product_name, p.slug AS product_slug,
                    p.sku AS product_sku, p.status AS product_status, p.currency,
                    p.base_price_minor, p.sale_price_minor, p.track_stock AS product_track_stock,
                    p.stock_quantity AS product_stock_quantity, p.stock_status AS product_stock_status,
                    p.backorders_allowed AS product_backorders,
                    COALESCE(tc.rate_bps, 0) AS tax_rate_bps,
                    v.product_id AS variant_product_id, v.name AS variant_name, v.sku AS variant_sku,
                    v.status AS variant_status, v.price_minor AS variant_price_minor,
                    v.sale_price_minor AS variant_sale_price_minor, v.track_stock AS variant_track_stock,
                    v.stock_quantity AS variant_stock_quantity, v.stock_status AS variant_stock_status,
                    v.backorders_allowed AS variant_backorders, v.attributes_json
             FROM commerce_cart_items ci
             INNER JOIN commerce_products p ON p.id = ci.product_id AND p.store_id = ?
             LEFT JOIN commerce_product_variants v ON v.id = ci.variant_id AND v.product_id = p.id
             LEFT JOIN commerce_tax_classes tc ON tc.id = p.tax_class_id
             WHERE ci.cart_id = ? ORDER BY ci.id FOR UPDATE'
        );
        $itemStatement->execute([(int) $cart['store_id'], (int) $cart['id']]);
        $rows = $itemStatement->fetchAll(PDO::FETCH_ASSOC);
        if ($rows === []) {
            throw new \DomainException('Cart is empty.');
        }

        $productIds = array_values(array_unique(array_map(
            static fn (array $row): int => (int) $row['product_id'],
            $rows,
        )));
        $optionDefinitions = $this->optionDefinitions($productIds);
        $fileRequirements = $this->fileRequirements($productIds);
        $lines = [];
        foreach ($rows as $row) {
            $productId = (int) $row['product_id'];
            $variantId = $row['variant_id'] === null ? null : (int) $row['variant_id'];
            $quantity = (int) $row['quantity'];
            if ((string) $row['product_status'] !== 'published') {
                throw new \DomainException('Cart contains an unavailable product.');
            }
            if ((string) $row['currency'] !== (string) $cart['currency']) {
                throw new \DomainException('Cart contains a product in another currency.');
            }
            if ((string) $row['product_type'] === 'variable' && $variantId === null) {
                throw new \DomainException('Cart product requires a variant.');
            }
            if ($variantId !== null && ($row['variant_product_id'] === null || (string) $row['variant_status'] !== 'active')) {
                throw new \DomainException('Cart contains an unavailable variant.');
            }
            $this->assertStock($row, $quantity, $variantId !== null);

            $providedOptions = $this->decodeJsonObject($row['options_json']);
            [$options, $optionPriceDelta] = $this->validateOptions(
                $productId,
                $providedOptions,
                $optionDefinitions[$productId] ?? [],
            );
            $basePrice = $this->effectivePrice($row, $variantId !== null);
            $unitPrice = $basePrice + $optionPriceDelta;
            if ($unitPrice < 0) {
                throw new \DomainException('Configured product price cannot be negative.');
            }
            $sku = $variantId !== null && trim((string) $row['variant_sku']) !== ''
                ? (string) $row['variant_sku']
                : ($row['product_sku'] === null ? null : (string) $row['product_sku']);
            $lines[] = new CheckoutLine(
                $productId,
                $variantId,
                (string) $row['product_name'],
                $row['variant_name'] === null ? null : (string) $row['variant_name'],
                $sku,
                $quantity,
                $unitPrice,
                0,
                (int) $row['tax_rate_bps'],
                $options,
                [
                    'product_slug' => (string) $row['product_slug'],
                    'product_type' => (string) $row['product_type'],
                    'variant_attributes' => $this->decodeJsonObject($row['attributes_json']),
                    'configuration_hash' => (string) $row['configuration_hash'],
                    'base_price_minor' => $basePrice,
                    'option_price_delta_minor' => $optionPriceDelta,
                    'stock_scope' => $variantId !== null ? 'variant' : 'product',
                    'stock_tracked' => (bool) $row[($variantId !== null ? 'variant_' : 'product_') . 'track_stock'],
                    'backorders_allowed' => (bool) $row[($variantId !== null ? 'variant_' : 'product_') . 'backorders'],
                ],
                isset($fileRequirements[$productId]),
            );
        }

        [$couponId, $couponCode, $lines] = $this->applyCoupon(
            (int) $cart['store_id'],
            $data,
            $lines,
        );
        $shipping = $this->shippingMethod((int) $cart['store_id'], $data, $lines);
        $this->assertPaymentMethod((int) $cart['store_id'], $data->paymentMethodCode);

        return new CheckoutQuote(
            (int) $cart['store_id'],
            (string) $cart['store_code'],
            (int) $cart['id'],
            (int) $cart['version'],
            (string) $cart['currency'],
            (bool) $cart['prices_include_tax'],
            $lines,
            (string) $shipping['code'],
            (string) $shipping['name'],
            (int) $shipping['price_minor'],
            (int) $shipping['tax_rate_bps'],
            $couponId,
            $couponCode,
        );
    }

    public function createOrder(
        string $checkoutKey,
        string $orderNumber,
        CheckoutQuote $quote,
        CheckoutData $data,
        array $calculation,
    ): CheckoutResult {
        $requiresFiles = array_reduce(
            $quote->lines,
            static fn (bool $required, CheckoutLine $line): bool => $required || $line->requiresFiles,
            false,
        );
        $orderStatus = $requiresFiles ? OrderStatus::AwaitingFiles : OrderStatus::New;
        $billing = $data->billingAddress->toArray();
        $shipping = ($data->shippingAddress ?? $data->billingAddress)->toArray();
        $consents = [
            'terms' => ['accepted' => true, 'recorded_at' => gmdate(DATE_ATOM)],
            'privacy' => ['accepted' => true, 'recorded_at' => gmdate(DATE_ATOM)],
            'marketing' => ['accepted' => $data->marketingConsent, 'recorded_at' => gmdate(DATE_ATOM)],
            'invoice_requested' => $data->invoiceRequested,
        ];
        $statement = $this->pdo->prepare(
            'INSERT INTO commerce_orders
                (store_id, customer_id, checkout_key, order_number, order_status, payment_status, currency,
                 subtotal_minor, discount_minor, shipping_minor, net_minor, tax_minor, total_minor,
                 customer_email, customer_phone, billing_address_json, shipping_address_json,
                 shipping_method_code, shipping_method_name, pickup_point_json, payment_method_code,
                 coupon_code, customer_note, consents_json, placed_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)'
        );
        $statement->execute([
            $quote->storeId,
            $data->customerId,
            $checkoutKey,
            $orderNumber,
            $orderStatus->value,
            PaymentStatus::Unpaid->value,
            $calculation['currency'],
            $calculation['subtotal_minor'],
            $calculation['discount_minor'],
            $calculation['shipping']['total_minor'] ?? 0,
            $calculation['net_minor'],
            $calculation['tax_minor'],
            $calculation['total_minor'],
            strtolower($data->email),
            $this->nullable($data->phone),
            $this->json($billing),
            $this->json($shipping),
            $quote->shippingMethodCode,
            $quote->shippingMethodName,
            $data->pickupPoint === null ? null : $this->json($data->pickupPoint),
            $data->paymentMethodCode,
            $quote->couponCode,
            $this->nullable($data->customerNote),
            $this->json($consents),
        ]);
        $orderId = (int) $this->pdo->lastInsertId();

        $itemInsert = $this->pdo->prepare(
            'INSERT INTO commerce_order_items
                (order_id, product_id, variant_id, product_name, variant_name, sku, quantity,
                 unit_price_minor, discount_minor, net_minor, tax_minor, total_minor, tax_rate_bps,
                 options_json, product_snapshot_json, requires_files)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($quote->lines as $index => $line) {
            $calculated = $calculation['lines'][$index];
            $itemInsert->execute([
                $orderId,
                $line->productId,
                $line->variantId,
                $line->productName,
                $line->variantName,
                $line->sku,
                $line->quantity,
                $line->unitPriceMinor,
                $calculated['discount_minor'],
                $calculated['net_minor'],
                $calculated['tax_minor'],
                $calculated['total_minor'],
                $line->taxRateBps,
                $this->json($line->options),
                $this->json($line->snapshot),
                $line->requiresFiles ? 1 : 0,
            ]);
            $this->decrementStock($line);
        }
        if ($quote->couponId !== null) {
            $this->pdo->prepare(
                'INSERT INTO commerce_coupon_redemptions
                    (coupon_id, order_id, customer_id, customer_email_hash, discount_minor)
                 VALUES (?, ?, ?, ?, ?)'
            )->execute([
                $quote->couponId,
                $orderId,
                $data->customerId,
                hash('sha256', strtolower($data->email)),
                $calculation['discount_minor'],
            ]);
        }
        $history = $this->pdo->prepare(
            'INSERT INTO commerce_order_status_history
                (order_id, dimension, from_status, to_status, actor_type, reason)
             VALUES (?, ?, NULL, ?, "customer", "checkout")'
        );
        $history->execute([$orderId, 'order', $orderStatus->value]);
        $history->execute([$orderId, 'payment', PaymentStatus::Unpaid->value]);
        $this->pdo->prepare(
            'INSERT INTO commerce_outbox
                (event_id, event_type, aggregate_type, aggregate_id, payload_json)
             VALUES (?, "commerce.order.created", "order", ?, ?)'
        )->execute([
            $this->uuid(),
            (string) $orderId,
            $this->json([
                'order_id' => $orderId,
                'order_number' => $orderNumber,
                'customer_email_hash' => hash('sha256', strtolower($data->email)),
            ]),
        ]);

        return new CheckoutResult(
            $orderId,
            $orderNumber,
            $orderStatus->value,
            PaymentStatus::Unpaid->value,
            $calculation,
        );
    }

    public function completeCart(int $cartId, int $expectedVersion): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE commerce_carts SET status = "converted", version = version + 1, updated_at = CURRENT_TIMESTAMP
             WHERE id = ? AND status = "active" AND version = ?'
        );
        $statement->execute([$cartId, $expectedVersion]);
        if ($statement->rowCount() !== 1) {
            throw new \RuntimeException('Cart changed while checkout was being placed.');
        }
    }

    /** @param array<string, mixed> $cart */
    private function assertCustomer(array $cart, CheckoutData $data): void
    {
        if ($data->customerId === null) {
            if ($cart['customer_id'] !== null) {
                throw new \DomainException('Authenticated cart requires its customer account.');
            }

            return;
        }
        if ($cart['customer_id'] !== null && (int) $cart['customer_id'] !== $data->customerId) {
            throw new \DomainException('Cart belongs to another customer.');
        }
        $statement = $this->pdo->prepare(
            'SELECT id FROM commerce_customers
             WHERE id = ? AND store_id = ? AND email = ? AND status = "active" LIMIT 1 FOR UPDATE'
        );
        $statement->execute([$data->customerId, $cart['store_id'], strtolower($data->email)]);
        if (!$statement->fetchColumn()) {
            throw new \DomainException('Customer account does not match checkout data.');
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function assertStock(array $row, int $quantity, bool $useVariant): void
    {
        $prefix = $useVariant ? 'variant_' : 'product_';
        $available = (string) $row[$prefix . 'stock_status'] === 'in_stock';
        $backorders = (bool) $row[$prefix . 'backorders'];
        if (!$available && !$backorders) {
            throw new \DomainException('Cart contains an out-of-stock item.');
        }
        if ((bool) $row[$prefix . 'track_stock']
            && $row[$prefix . 'stock_quantity'] !== null
            && (int) $row[$prefix . 'stock_quantity'] < $quantity
            && !$backorders) {
            throw new \DomainException('Requested quantity is no longer available.');
        }
    }

    private function decrementStock(CheckoutLine $line): void
    {
        if (empty($line->snapshot['stock_tracked'])) {
            return;
        }
        $scope = (string) ($line->snapshot['stock_scope'] ?? '');
        $backorders = !empty($line->snapshot['backorders_allowed']);
        if ($scope === 'variant' && $line->variantId !== null) {
            $table = 'commerce_product_variants';
            $id = $line->variantId;
        } elseif ($scope === 'product') {
            $table = 'commerce_products';
            $id = $line->productId;
        } else {
            throw new \RuntimeException('Checkout line has invalid stock scope.');
        }
        $condition = $backorders ? '' : ' AND stock_quantity >= ?';
        $statement = $this->pdo->prepare(
            "UPDATE {$table}
             SET stock_quantity = stock_quantity - ?,
                 stock_status = IF(stock_quantity - ? > 0, stock_status, 'out_of_stock'),
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = ? AND track_stock = 1 AND stock_quantity IS NOT NULL{$condition}"
        );
        $parameters = [$line->quantity, $line->quantity, $id];
        if (!$backorders) {
            $parameters[] = $line->quantity;
        }
        $statement->execute($parameters);
        if ($statement->rowCount() !== 1) {
            throw new \DomainException('Stock changed while checkout was being placed.');
        }
    }

    /** @param array<string, mixed> $row */
    private function effectivePrice(array $row, bool $useVariant): int
    {
        $values = $useVariant
            ? [$row['variant_sale_price_minor'], $row['variant_price_minor'], $row['sale_price_minor'], $row['base_price_minor']]
            : [$row['sale_price_minor'], $row['base_price_minor']];
        foreach ($values as $value) {
            if ($value !== null) {
                return (int) $value;
            }
        }

        throw new \DomainException('Cart item does not have a price.');
    }

    /**
     * @param array<int, int> $productIds
     * @return array<int, array<string, array<string, mixed>>>
     */
    private function optionDefinitions(array $productIds): array
    {
        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        $statement = $this->pdo->prepare(
            "SELECT po.product_id, po.code, po.input_type, po.required, po.validation_json,
                    pov.code AS value_code, pov.label AS value_label, pov.price_delta_minor
             FROM commerce_product_options po
             LEFT JOIN commerce_product_option_values pov ON pov.option_id = po.id
             WHERE po.product_id IN ({$placeholders}) ORDER BY po.sort_order, pov.sort_order"
        );
        $statement->execute($productIds);
        $definitions = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $productId = (int) $row['product_id'];
            $code = (string) $row['code'];
            $definitions[$productId][$code] ??= [
                'input_type' => (string) $row['input_type'],
                'required' => (bool) $row['required'],
                'validation' => $this->decodeJsonObject($row['validation_json']),
                'values' => [],
            ];
            if ($row['value_code'] !== null) {
                $definitions[$productId][$code]['values'][(string) $row['value_code']] = [
                    'label' => (string) $row['value_label'],
                    'price_delta_minor' => (int) $row['price_delta_minor'],
                ];
            }
        }

        return $definitions;
    }

    /** @param array<int, int> $productIds @return array<int, true> */
    private function fileRequirements(array $productIds): array
    {
        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        $statement = $this->pdo->prepare(
            "SELECT DISTINCT product_id FROM commerce_product_file_requirements
             WHERE required = 1 AND product_id IN ({$placeholders})"
        );
        $statement->execute($productIds);
        $result = [];
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $productId) {
            $result[(int) $productId] = true;
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $provided
     * @param array<string, array<string, mixed>> $definitions
     * @return array{0: array<string, mixed>, 1: int}
     */
    private function validateOptions(int $productId, array $provided, array $definitions): array
    {
        foreach (array_keys($provided) as $code) {
            if (!isset($definitions[$code])) {
                throw new \DomainException("Unknown option {$code} for product {$productId}.");
            }
        }
        $normalized = [];
        $priceDelta = 0;
        foreach ($definitions as $code => $definition) {
            $value = $provided[$code] ?? null;
            if (!empty($definition['required']) && ($value === null || $value === '' || $value === [])) {
                throw new \DomainException("Required option {$code} is missing.");
            }
            if ($value === null || $value === '' || $value === []) {
                continue;
            }
            $type = (string) $definition['input_type'];
            $selected = $type === 'checkbox' ? (array) $value : [$value];
            if ($definition['values'] !== []) {
                $selected = array_values(array_unique(array_map('strval', $selected)));
                foreach ($selected as $selectedCode) {
                    if (!isset($definition['values'][$selectedCode])) {
                        throw new \DomainException("Invalid value for option {$code}.");
                    }
                    $priceDelta += (int) $definition['values'][$selectedCode]['price_delta_minor'];
                }
                $normalized[$code] = $type === 'checkbox' ? $selected : $selected[0];
                continue;
            }
            if (!is_scalar($value)) {
                throw new \DomainException("Invalid custom value for option {$code}.");
            }
            $custom = trim((string) $value);
            $validation = $definition['validation'];
            if (isset($validation['max_length']) && mb_strlen($custom) > (int) $validation['max_length']) {
                throw new \DomainException("Option {$code} is too long.");
            }
            if ($type === 'number') {
                if (!is_numeric($custom)) {
                    throw new \DomainException("Option {$code} must be numeric.");
                }
                if (isset($validation['min']) && (float) $custom < (float) $validation['min']) {
                    throw new \DomainException("Option {$code} is below minimum.");
                }
                if (isset($validation['max']) && (float) $custom > (float) $validation['max']) {
                    throw new \DomainException("Option {$code} exceeds maximum.");
                }
            }
            $normalized[$code] = $custom;
        }
        ksort($normalized);

        return [$normalized, $priceDelta];
    }

    /**
     * @param array<int, CheckoutLine> $lines
     * @return array{0: ?int, 1: ?string, 2: array<int, CheckoutLine>}
     */
    private function applyCoupon(int $storeId, CheckoutData $data, array $lines): array
    {
        $code = strtoupper(trim((string) $data->couponCode));
        if ($code === '') {
            return [null, null, $lines];
        }
        $statement = $this->pdo->prepare(
            'SELECT * FROM commerce_coupons WHERE store_id = ? AND code = ? LIMIT 1 FOR UPDATE'
        );
        $statement->execute([$storeId, $code]);
        $coupon = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$coupon || !(bool) $coupon['active']) {
            throw new \DomainException('Coupon is invalid or inactive.');
        }
        $now = time();
        if (($coupon['starts_at'] !== null && strtotime((string) $coupon['starts_at']) > $now)
            || ($coupon['ends_at'] !== null && strtotime((string) $coupon['ends_at']) < $now)) {
            throw new \DomainException('Coupon is outside its validity period.');
        }
        $rules = $this->decodeJsonObject($coupon['rules_json']);
        // Checkout accepts one coupon code, so WooCommerce's individual-use rule
        // is already satisfied. Other legacy rules need an explicit adapter.
        unset($rules['individual_use']);
        if ($rules !== []) {
            throw new \DomainException('Coupon uses rules not supported by this checkout version.');
        }
        $subtotal = array_reduce(
            $lines,
            static fn (int $sum, CheckoutLine $line): int => $sum + ($line->unitPriceMinor * $line->quantity),
            0,
        );
        if ($coupon['minimum_minor'] !== null && $subtotal < (int) $coupon['minimum_minor']) {
            throw new \DomainException('Cart does not meet coupon minimum.');
        }
        $this->assertCouponUsage($coupon, $data);
        $discount = match ((string) $coupon['type']) {
            'percentage', 'percent' => intdiv($subtotal * (int) $coupon['value_bps'] + 5000, 10000),
            'fixed_cart' => (int) $coupon['value_minor'],
            default => throw new \DomainException('Coupon type is not supported.'),
        };
        if ($coupon['maximum_discount_minor'] !== null) {
            $discount = min($discount, (int) $coupon['maximum_discount_minor']);
        }
        $discount = min(max(0, $discount), $subtotal);
        if ($discount === 0) {
            throw new \DomainException('Coupon does not produce a discount.');
        }

        $remaining = $discount;
        $last = array_key_last($lines);
        foreach ($lines as $index => $line) {
            $lineGross = $line->unitPriceMinor * $line->quantity;
            $lineDiscount = $index === $last ? $remaining : intdiv($discount * $lineGross, $subtotal);
            $remaining -= $lineDiscount;
            $lines[$index] = new CheckoutLine(
                $line->productId,
                $line->variantId,
                $line->productName,
                $line->variantName,
                $line->sku,
                $line->quantity,
                $line->unitPriceMinor,
                $lineDiscount,
                $line->taxRateBps,
                $line->options,
                $line->snapshot,
                $line->requiresFiles,
            );
        }

        return [(int) $coupon['id'], (string) $coupon['code'], $lines];
    }

    /** @param array<string, mixed> $coupon */
    private function assertCouponUsage(array $coupon, CheckoutData $data): void
    {
        if ($coupon['usage_limit'] !== null) {
            $statement = $this->pdo->prepare(
                'SELECT COUNT(*) FROM commerce_coupon_redemptions WHERE coupon_id = ?'
            );
            $statement->execute([(int) $coupon['id']]);
            if ((int) $statement->fetchColumn() >= (int) $coupon['usage_limit']) {
                throw new \DomainException('Coupon usage limit has been reached.');
            }
        }
        if ($coupon['usage_limit_per_customer'] !== null) {
            $statement = $this->pdo->prepare(
                'SELECT COUNT(*) FROM commerce_coupon_redemptions
                 WHERE coupon_id = ? AND customer_email_hash = ?'
            );
            $statement->execute([
                (int) $coupon['id'],
                hash('sha256', strtolower($data->email)),
            ]);
            if ((int) $statement->fetchColumn() >= (int) $coupon['usage_limit_per_customer']) {
                throw new \DomainException('Customer coupon usage limit has been reached.');
            }
        }
    }

    /** @return array<string, mixed> */
    /** @param array<int, CheckoutLine> $lines */
    private function shippingMethod(int $storeId, CheckoutData $data, array $lines): array
    {
        $statement = $this->pdo->prepare(
            'SELECT code, name, type, price_minor, tax_rate_bps, cod_allowed, countries_json, rules_json
             FROM commerce_shipping_methods WHERE store_id = ? AND code = ? AND active = 1 LIMIT 1 FOR UPDATE'
        );
        $statement->execute([$storeId, $data->shippingMethodCode]);
        $shipping = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$shipping) {
            throw new \DomainException('Shipping method is unavailable.');
        }
        $address = $data->shippingAddress ?? $data->billingAddress;
        $countries = $this->decodeJsonArray($shipping['countries_json']);
        if ($countries !== [] && !in_array(strtoupper($address->countryCode), $countries, true)) {
            throw new \DomainException('Shipping method is unavailable for this country.');
        }
        if ($data->paymentMethodCode === 'cod' && !(bool) $shipping['cod_allowed']) {
            throw new \DomainException('Cash on delivery is unavailable for this shipping method.');
        }
        if (in_array((string) $shipping['type'], ['pickup', 'pickup_point', 'locker'], true)) {
            if ($data->pickupPoint === null || trim((string) ($data->pickupPoint['code'] ?? '')) === '') {
                throw new \DomainException('Shipping method requires a pickup point.');
            }
        }
        if ($data->pickupPoint !== null && strlen($this->json($data->pickupPoint)) > 5000) {
            throw new \DomainException('Pickup point payload is too large.');
        }
        $rules = $this->decodeJsonObject($shipping['rules_json']);
        $freeFrom = isset($rules['free_from_minor']) ? (int) $rules['free_from_minor'] : null;
        if ($freeFrom !== null && $freeFrom >= 0) {
            $discountedSubtotal = array_reduce(
                $lines,
                static fn (int $sum, CheckoutLine $line): int => $sum
                    + ($line->unitPriceMinor * $line->quantity)
                    - $line->discountMinor,
                0,
            );
            if ($discountedSubtotal >= $freeFrom) {
                $shipping['price_minor'] = 0;
            }
        }

        return $shipping;
    }

    private function assertPaymentMethod(int $storeId, string $code): void
    {
        $statement = $this->pdo->prepare(
            'SELECT code FROM commerce_payment_methods
             WHERE store_id = ? AND code = ? AND active = 1 LIMIT 1 FOR UPDATE'
        );
        $statement->execute([$storeId, $code]);
        if (!$statement->fetchColumn()) {
            throw new \DomainException('Payment method is unavailable.');
        }
    }

    /** @param array<string, mixed> $row */
    private function checkoutResult(array $row): CheckoutResult
    {
        return new CheckoutResult(
            (int) $row['id'],
            (string) $row['order_number'],
            (string) $row['order_status'],
            (string) $row['payment_status'],
            [
                'currency' => (string) $row['currency'],
                'subtotal_minor' => (int) $row['subtotal_minor'],
                'discount_minor' => (int) $row['discount_minor'],
                'shipping' => ['total_minor' => (int) $row['shipping_minor']],
                'net_minor' => (int) $row['net_minor'],
                'tax_minor' => (int) $row['tax_minor'],
                'total_minor' => (int) $row['total_minor'],
            ],
        );
    }

    /** @return array<string, mixed> */
    private function decodeJsonObject(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        $decoded = json_decode((string) $value, true, 512, JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : [];
    }

    /** @return array<int, string> */
    private function decodeJsonArray(mixed $value): array
    {
        $decoded = $this->decodeJsonObject($value);

        return array_values(array_unique(array_map(static fn (mixed $country): string => strtoupper((string) $country), $decoded)));
    }

    private function json(mixed $value): string
    {
        return json_encode(
            $value,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
        );
    }

    private function nullable(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
            . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }
}
