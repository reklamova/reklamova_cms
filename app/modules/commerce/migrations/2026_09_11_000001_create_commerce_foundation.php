<?php

declare(strict_types=1);

return new class {
    public function up(PDO $pdo): void
    {
        foreach ($this->statements() as $statement) {
            $pdo->exec($statement);
        }

        $this->seedPermissions($pdo);
    }

    public function down(PDO $pdo): void
    {
        foreach ([
            'commerce_redirects',
            'commerce_import_mappings',
            'commerce_import_runs',
            'commerce_notification_deliveries',
            'commerce_outbox',
            'commerce_order_files',
            'commerce_product_file_requirements',
            'commerce_order_shipments',
            'commerce_payment_events',
            'commerce_payment_attempts',
            'commerce_order_status_history',
            'commerce_coupon_redemptions',
            'commerce_order_items',
            'commerce_orders',
            'commerce_shipping_methods',
            'commerce_coupons',
            'commerce_cart_items',
            'commerce_carts',
            'commerce_customer_addresses',
            'commerce_customers',
            'commerce_product_option_values',
            'commerce_product_options',
            'commerce_variant_terms',
            'commerce_product_attributes',
            'commerce_attribute_terms',
            'commerce_attributes',
            'commerce_product_variants',
            'commerce_product_categories',
            'commerce_products',
            'commerce_categories',
            'commerce_tax_classes',
            'commerce_stores',
        ] as $table) {
            $pdo->exec("DROP TABLE IF EXISTS {$table}");
        }
    }

    /**
     * @return array<int, string>
     */
    private function statements(): array
    {
        $options = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

        return [
            'CREATE TABLE IF NOT EXISTS commerce_stores (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                code VARCHAR(80) NOT NULL,
                name VARCHAR(190) NOT NULL,
                currency CHAR(3) NOT NULL DEFAULT "PLN",
                country_code CHAR(2) NOT NULL DEFAULT "PL",
                prices_include_tax TINYINT(1) NOT NULL DEFAULT 1,
                status VARCHAR(30) NOT NULL DEFAULT "active",
                settings_json JSON NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_commerce_stores_code (code)
            )' . $options,

            'CREATE TABLE IF NOT EXISTS commerce_tax_classes (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                store_id BIGINT UNSIGNED NOT NULL,
                code VARCHAR(80) NOT NULL,
                name VARCHAR(190) NOT NULL,
                rate_bps INT UNSIGNED NOT NULL DEFAULT 0,
                applies_to_shipping TINYINT(1) NOT NULL DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_commerce_tax_store_code (store_id, code),
                CONSTRAINT fk_commerce_tax_store FOREIGN KEY (store_id) REFERENCES commerce_stores(id) ON DELETE CASCADE
            )' . $options,

            'CREATE TABLE IF NOT EXISTS commerce_categories (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                store_id BIGINT UNSIGNED NOT NULL,
                parent_id BIGINT UNSIGNED NULL,
                name VARCHAR(190) NOT NULL,
                slug VARCHAR(190) NOT NULL,
                full_path VARCHAR(700) NOT NULL,
                summary TEXT NULL,
                description MEDIUMTEXT NULL,
                featured_image VARCHAR(500) NULL,
                status VARCHAR(30) NOT NULL DEFAULT "draft",
                sort_order INT NOT NULL DEFAULT 100,
                meta_title VARCHAR(190) NULL,
                meta_description TEXT NULL,
                canonical_url VARCHAR(500) NULL,
                robots VARCHAR(80) NOT NULL DEFAULT "index,follow",
                og_image VARCHAR(500) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_commerce_category_path (store_id, full_path),
                KEY idx_commerce_category_parent (parent_id),
                KEY idx_commerce_category_listing (store_id, status, sort_order),
                CONSTRAINT fk_commerce_category_store FOREIGN KEY (store_id) REFERENCES commerce_stores(id) ON DELETE CASCADE,
                CONSTRAINT fk_commerce_category_parent FOREIGN KEY (parent_id) REFERENCES commerce_categories(id) ON DELETE SET NULL
            )' . $options,

            'CREATE TABLE IF NOT EXISTS commerce_products (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                store_id BIGINT UNSIGNED NOT NULL,
                tax_class_id BIGINT UNSIGNED NULL,
                type VARCHAR(30) NOT NULL DEFAULT "simple",
                name VARCHAR(220) NOT NULL,
                slug VARCHAR(220) NOT NULL,
                sku VARCHAR(120) NULL,
                summary TEXT NULL,
                description MEDIUMTEXT NULL,
                status VARCHAR(30) NOT NULL DEFAULT "draft",
                visibility VARCHAR(30) NOT NULL DEFAULT "catalog_search",
                base_price_minor BIGINT UNSIGNED NULL,
                sale_price_minor BIGINT UNSIGNED NULL,
                currency CHAR(3) NOT NULL DEFAULT "PLN",
                track_stock TINYINT(1) NOT NULL DEFAULT 0,
                stock_quantity INT NULL,
                stock_status VARCHAR(30) NOT NULL DEFAULT "in_stock",
                backorders_allowed TINYINT(1) NOT NULL DEFAULT 0,
                weight_grams INT UNSIGNED NULL,
                width_mm INT UNSIGNED NULL,
                height_mm INT UNSIGNED NULL,
                length_mm INT UNSIGNED NULL,
                featured_image VARCHAR(500) NULL,
                gallery_json JSON NULL,
                content_sections_json JSON NULL,
                calculator_key VARCHAR(120) NULL,
                sort_order INT NOT NULL DEFAULT 100,
                meta_title VARCHAR(190) NULL,
                meta_description TEXT NULL,
                canonical_url VARCHAR(500) NULL,
                robots VARCHAR(80) NOT NULL DEFAULT "index,follow",
                og_title VARCHAR(190) NULL,
                og_description TEXT NULL,
                og_image VARCHAR(500) NULL,
                schema_json JSON NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_commerce_product_slug (store_id, slug),
                UNIQUE KEY uq_commerce_product_sku (store_id, sku),
                KEY idx_commerce_product_listing (store_id, status, visibility, sort_order),
                KEY idx_commerce_product_tax (tax_class_id),
                CONSTRAINT fk_commerce_product_store FOREIGN KEY (store_id) REFERENCES commerce_stores(id) ON DELETE CASCADE,
                CONSTRAINT fk_commerce_product_tax FOREIGN KEY (tax_class_id) REFERENCES commerce_tax_classes(id) ON DELETE SET NULL
            )' . $options,

            'CREATE TABLE IF NOT EXISTS commerce_product_categories (
                product_id BIGINT UNSIGNED NOT NULL,
                category_id BIGINT UNSIGNED NOT NULL,
                is_primary TINYINT(1) NOT NULL DEFAULT 0,
                sort_order INT NOT NULL DEFAULT 100,
                PRIMARY KEY (product_id, category_id),
                KEY idx_commerce_product_category (category_id, sort_order),
                CONSTRAINT fk_commerce_pc_product FOREIGN KEY (product_id) REFERENCES commerce_products(id) ON DELETE CASCADE,
                CONSTRAINT fk_commerce_pc_category FOREIGN KEY (category_id) REFERENCES commerce_categories(id) ON DELETE CASCADE
            )' . $options,

            'CREATE TABLE IF NOT EXISTS commerce_product_variants (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                store_id BIGINT UNSIGNED NOT NULL,
                product_id BIGINT UNSIGNED NOT NULL,
                sku VARCHAR(120) NULL,
                name VARCHAR(220) NULL,
                status VARCHAR(30) NOT NULL DEFAULT "active",
                price_minor BIGINT UNSIGNED NULL,
                sale_price_minor BIGINT UNSIGNED NULL,
                track_stock TINYINT(1) NOT NULL DEFAULT 0,
                stock_quantity INT NULL,
                stock_status VARCHAR(30) NOT NULL DEFAULT "in_stock",
                backorders_allowed TINYINT(1) NOT NULL DEFAULT 0,
                weight_grams INT UNSIGNED NULL,
                width_mm INT UNSIGNED NULL,
                height_mm INT UNSIGNED NULL,
                length_mm INT UNSIGNED NULL,
                image VARCHAR(500) NULL,
                attributes_json JSON NULL,
                sort_order INT NOT NULL DEFAULT 100,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_commerce_variant_sku (store_id, sku),
                KEY idx_commerce_variant_listing (product_id, status, sort_order),
                CONSTRAINT fk_commerce_variant_store FOREIGN KEY (store_id) REFERENCES commerce_stores(id) ON DELETE CASCADE,
                CONSTRAINT fk_commerce_variant_product FOREIGN KEY (product_id) REFERENCES commerce_products(id) ON DELETE CASCADE
            )' . $options,

            'CREATE TABLE IF NOT EXISTS commerce_attributes (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                store_id BIGINT UNSIGNED NOT NULL,
                code VARCHAR(120) NOT NULL,
                name VARCHAR(190) NOT NULL,
                input_type VARCHAR(30) NOT NULL DEFAULT "select",
                is_variant TINYINT(1) NOT NULL DEFAULT 0,
                sort_order INT NOT NULL DEFAULT 100,
                settings_json JSON NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_commerce_attribute_code (store_id, code),
                CONSTRAINT fk_commerce_attribute_store FOREIGN KEY (store_id) REFERENCES commerce_stores(id) ON DELETE CASCADE
            )' . $options,

            'CREATE TABLE IF NOT EXISTS commerce_attribute_terms (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                attribute_id BIGINT UNSIGNED NOT NULL,
                value VARCHAR(190) NOT NULL,
                slug VARCHAR(190) NOT NULL,
                sort_order INT NOT NULL DEFAULT 100,
                meta_json JSON NULL,
                UNIQUE KEY uq_commerce_attribute_term (attribute_id, slug),
                KEY idx_commerce_attribute_term_sort (attribute_id, sort_order),
                CONSTRAINT fk_commerce_term_attribute FOREIGN KEY (attribute_id) REFERENCES commerce_attributes(id) ON DELETE CASCADE
            )' . $options,

            'CREATE TABLE IF NOT EXISTS commerce_product_attributes (
                product_id BIGINT UNSIGNED NOT NULL,
                attribute_id BIGINT UNSIGNED NOT NULL,
                is_visible TINYINT(1) NOT NULL DEFAULT 1,
                is_variant TINYINT(1) NOT NULL DEFAULT 0,
                sort_order INT NOT NULL DEFAULT 100,
                PRIMARY KEY (product_id, attribute_id),
                CONSTRAINT fk_commerce_pa_product FOREIGN KEY (product_id) REFERENCES commerce_products(id) ON DELETE CASCADE,
                CONSTRAINT fk_commerce_pa_attribute FOREIGN KEY (attribute_id) REFERENCES commerce_attributes(id) ON DELETE CASCADE
            )' . $options,

            'CREATE TABLE IF NOT EXISTS commerce_variant_terms (
                variant_id BIGINT UNSIGNED NOT NULL,
                term_id BIGINT UNSIGNED NOT NULL,
                PRIMARY KEY (variant_id, term_id),
                KEY idx_commerce_variant_term (term_id),
                CONSTRAINT fk_commerce_vt_variant FOREIGN KEY (variant_id) REFERENCES commerce_product_variants(id) ON DELETE CASCADE,
                CONSTRAINT fk_commerce_vt_term FOREIGN KEY (term_id) REFERENCES commerce_attribute_terms(id) ON DELETE CASCADE
            )' . $options,

            'CREATE TABLE IF NOT EXISTS commerce_product_options (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                product_id BIGINT UNSIGNED NOT NULL,
                code VARCHAR(120) NOT NULL,
                name VARCHAR(190) NOT NULL,
                input_type VARCHAR(30) NOT NULL DEFAULT "select",
                required TINYINT(1) NOT NULL DEFAULT 0,
                affects_price TINYINT(1) NOT NULL DEFAULT 0,
                validation_json JSON NULL,
                sort_order INT NOT NULL DEFAULT 100,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_commerce_product_option (product_id, code),
                CONSTRAINT fk_commerce_option_product FOREIGN KEY (product_id) REFERENCES commerce_products(id) ON DELETE CASCADE
            )' . $options,

            'CREATE TABLE IF NOT EXISTS commerce_product_option_values (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                option_id BIGINT UNSIGNED NOT NULL,
                code VARCHAR(120) NOT NULL,
                label VARCHAR(190) NOT NULL,
                price_delta_minor BIGINT NOT NULL DEFAULT 0,
                pricing_rule_json JSON NULL,
                sort_order INT NOT NULL DEFAULT 100,
                UNIQUE KEY uq_commerce_option_value (option_id, code),
                CONSTRAINT fk_commerce_value_option FOREIGN KEY (option_id) REFERENCES commerce_product_options(id) ON DELETE CASCADE
            )' . $options,

            'CREATE TABLE IF NOT EXISTS commerce_customers (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                store_id BIGINT UNSIGNED NOT NULL,
                email VARCHAR(190) NOT NULL,
                password_hash VARCHAR(255) NULL,
                first_name VARCHAR(120) NULL,
                last_name VARCHAR(120) NULL,
                phone VARCHAR(80) NULL,
                company VARCHAR(190) NULL,
                tax_id VARCHAR(40) NULL,
                status VARCHAR(30) NOT NULL DEFAULT "active",
                password_reset_required TINYINT(1) NOT NULL DEFAULT 0,
                email_verified_at TIMESTAMP NULL,
                last_login_at TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_commerce_customer_email (store_id, email),
                KEY idx_commerce_customer_name (store_id, last_name, first_name),
                CONSTRAINT fk_commerce_customer_store FOREIGN KEY (store_id) REFERENCES commerce_stores(id) ON DELETE CASCADE
            )' . $options,

            'CREATE TABLE IF NOT EXISTS commerce_customer_addresses (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                customer_id BIGINT UNSIGNED NOT NULL,
                type VARCHAR(30) NOT NULL DEFAULT "shipping",
                label VARCHAR(120) NULL,
                first_name VARCHAR(120) NULL,
                last_name VARCHAR(120) NULL,
                company VARCHAR(190) NULL,
                tax_id VARCHAR(40) NULL,
                address_line1 VARCHAR(255) NOT NULL,
                address_line2 VARCHAR(255) NULL,
                postal_code VARCHAR(30) NOT NULL,
                city VARCHAR(120) NOT NULL,
                country_code CHAR(2) NOT NULL DEFAULT "PL",
                phone VARCHAR(80) NULL,
                is_default TINYINT(1) NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_commerce_address_customer (customer_id, type, is_default),
                CONSTRAINT fk_commerce_address_customer FOREIGN KEY (customer_id) REFERENCES commerce_customers(id) ON DELETE CASCADE
            )' . $options,

            'CREATE TABLE IF NOT EXISTS commerce_carts (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                store_id BIGINT UNSIGNED NOT NULL,
                customer_id BIGINT UNSIGNED NULL,
                token_hash CHAR(64) NOT NULL,
                currency CHAR(3) NOT NULL DEFAULT "PLN",
                status VARCHAR(30) NOT NULL DEFAULT "active",
                version INT UNSIGNED NOT NULL DEFAULT 1,
                expires_at TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_commerce_cart_token (token_hash),
                KEY idx_commerce_cart_customer (store_id, customer_id, status),
                KEY idx_commerce_cart_expiry (status, expires_at),
                CONSTRAINT fk_commerce_cart_store FOREIGN KEY (store_id) REFERENCES commerce_stores(id) ON DELETE CASCADE,
                CONSTRAINT fk_commerce_cart_customer FOREIGN KEY (customer_id) REFERENCES commerce_customers(id) ON DELETE SET NULL
            )' . $options,

            'CREATE TABLE IF NOT EXISTS commerce_cart_items (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                cart_id BIGINT UNSIGNED NOT NULL,
                product_id BIGINT UNSIGNED NOT NULL,
                variant_id BIGINT UNSIGNED NULL,
                quantity INT UNSIGNED NOT NULL,
                options_json JSON NULL,
                configuration_hash CHAR(64) NOT NULL,
                price_fingerprint CHAR(64) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_commerce_cart_configuration (cart_id, configuration_hash),
                KEY idx_commerce_cart_item_product (product_id),
                CONSTRAINT fk_commerce_cart_item_cart FOREIGN KEY (cart_id) REFERENCES commerce_carts(id) ON DELETE CASCADE,
                CONSTRAINT fk_commerce_cart_item_product FOREIGN KEY (product_id) REFERENCES commerce_products(id) ON DELETE RESTRICT,
                CONSTRAINT fk_commerce_cart_item_variant FOREIGN KEY (variant_id) REFERENCES commerce_product_variants(id) ON DELETE SET NULL
            )' . $options,

            'CREATE TABLE IF NOT EXISTS commerce_coupons (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                store_id BIGINT UNSIGNED NOT NULL,
                code VARCHAR(120) NOT NULL,
                type VARCHAR(30) NOT NULL DEFAULT "percentage",
                value_minor BIGINT UNSIGNED NULL,
                value_bps INT UNSIGNED NULL,
                minimum_minor BIGINT UNSIGNED NULL,
                maximum_discount_minor BIGINT UNSIGNED NULL,
                usage_limit INT UNSIGNED NULL,
                usage_limit_per_customer INT UNSIGNED NULL,
                starts_at TIMESTAMP NULL,
                ends_at TIMESTAMP NULL,
                active TINYINT(1) NOT NULL DEFAULT 1,
                rules_json JSON NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_commerce_coupon_code (store_id, code),
                KEY idx_commerce_coupon_active (store_id, active, starts_at, ends_at),
                CONSTRAINT fk_commerce_coupon_store FOREIGN KEY (store_id) REFERENCES commerce_stores(id) ON DELETE CASCADE
            )' . $options,

            'CREATE TABLE IF NOT EXISTS commerce_shipping_methods (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                store_id BIGINT UNSIGNED NOT NULL,
                code VARCHAR(120) NOT NULL,
                name VARCHAR(190) NOT NULL,
                provider VARCHAR(120) NULL,
                service_code VARCHAR(120) NULL,
                type VARCHAR(30) NOT NULL DEFAULT "courier",
                price_minor BIGINT UNSIGNED NOT NULL DEFAULT 0,
                tax_rate_bps INT UNSIGNED NOT NULL DEFAULT 0,
                cod_allowed TINYINT(1) NOT NULL DEFAULT 0,
                active TINYINT(1) NOT NULL DEFAULT 1,
                countries_json JSON NULL,
                rules_json JSON NULL,
                sort_order INT NOT NULL DEFAULT 100,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_commerce_shipping_code (store_id, code),
                KEY idx_commerce_shipping_active (store_id, active, sort_order),
                CONSTRAINT fk_commerce_shipping_store FOREIGN KEY (store_id) REFERENCES commerce_stores(id) ON DELETE CASCADE
            )' . $options,

            'CREATE TABLE IF NOT EXISTS commerce_orders (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                store_id BIGINT UNSIGNED NOT NULL,
                customer_id BIGINT UNSIGNED NULL,
                order_number VARCHAR(80) NOT NULL,
                order_status VARCHAR(40) NOT NULL DEFAULT "new",
                payment_status VARCHAR(40) NOT NULL DEFAULT "unpaid",
                currency CHAR(3) NOT NULL DEFAULT "PLN",
                subtotal_minor BIGINT UNSIGNED NOT NULL,
                discount_minor BIGINT UNSIGNED NOT NULL DEFAULT 0,
                shipping_minor BIGINT UNSIGNED NOT NULL DEFAULT 0,
                net_minor BIGINT UNSIGNED NOT NULL,
                tax_minor BIGINT UNSIGNED NOT NULL,
                total_minor BIGINT UNSIGNED NOT NULL,
                customer_email VARCHAR(190) NOT NULL,
                customer_phone VARCHAR(80) NULL,
                billing_address_json JSON NOT NULL,
                shipping_address_json JSON NULL,
                shipping_method_code VARCHAR(120) NULL,
                shipping_method_name VARCHAR(190) NULL,
                pickup_point_json JSON NULL,
                payment_method_code VARCHAR(120) NULL,
                coupon_code VARCHAR(120) NULL,
                customer_note TEXT NULL,
                internal_note TEXT NULL,
                placed_at TIMESTAMP NULL,
                paid_at TIMESTAMP NULL,
                cancelled_at TIMESTAMP NULL,
                completed_at TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_commerce_order_number (store_id, order_number),
                KEY idx_commerce_order_status (store_id, order_status, created_at),
                KEY idx_commerce_order_payment (store_id, payment_status, created_at),
                KEY idx_commerce_order_customer (customer_id, created_at),
                KEY idx_commerce_order_email (store_id, customer_email),
                CONSTRAINT fk_commerce_order_store FOREIGN KEY (store_id) REFERENCES commerce_stores(id) ON DELETE RESTRICT,
                CONSTRAINT fk_commerce_order_customer FOREIGN KEY (customer_id) REFERENCES commerce_customers(id) ON DELETE SET NULL
            )' . $options,

            'CREATE TABLE IF NOT EXISTS commerce_order_items (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                order_id BIGINT UNSIGNED NOT NULL,
                product_id BIGINT UNSIGNED NULL,
                variant_id BIGINT UNSIGNED NULL,
                product_name VARCHAR(220) NOT NULL,
                variant_name VARCHAR(220) NULL,
                sku VARCHAR(120) NULL,
                quantity INT UNSIGNED NOT NULL,
                unit_price_minor BIGINT UNSIGNED NOT NULL,
                discount_minor BIGINT UNSIGNED NOT NULL DEFAULT 0,
                net_minor BIGINT UNSIGNED NOT NULL,
                tax_minor BIGINT UNSIGNED NOT NULL,
                total_minor BIGINT UNSIGNED NOT NULL,
                tax_rate_bps INT UNSIGNED NOT NULL,
                options_json JSON NULL,
                product_snapshot_json JSON NOT NULL,
                requires_files TINYINT(1) NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY idx_commerce_order_item_order (order_id),
                KEY idx_commerce_order_item_product (product_id),
                CONSTRAINT fk_commerce_order_item_order FOREIGN KEY (order_id) REFERENCES commerce_orders(id) ON DELETE CASCADE,
                CONSTRAINT fk_commerce_order_item_product FOREIGN KEY (product_id) REFERENCES commerce_products(id) ON DELETE SET NULL,
                CONSTRAINT fk_commerce_order_item_variant FOREIGN KEY (variant_id) REFERENCES commerce_product_variants(id) ON DELETE SET NULL
            )' . $options,

            'CREATE TABLE IF NOT EXISTS commerce_coupon_redemptions (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                coupon_id BIGINT UNSIGNED NOT NULL,
                order_id BIGINT UNSIGNED NOT NULL,
                customer_id BIGINT UNSIGNED NULL,
                discount_minor BIGINT UNSIGNED NOT NULL,
                redeemed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_commerce_coupon_order (coupon_id, order_id),
                KEY idx_commerce_coupon_customer (coupon_id, customer_id),
                CONSTRAINT fk_commerce_redemption_coupon FOREIGN KEY (coupon_id) REFERENCES commerce_coupons(id) ON DELETE RESTRICT,
                CONSTRAINT fk_commerce_redemption_order FOREIGN KEY (order_id) REFERENCES commerce_orders(id) ON DELETE CASCADE,
                CONSTRAINT fk_commerce_redemption_customer FOREIGN KEY (customer_id) REFERENCES commerce_customers(id) ON DELETE SET NULL
            )' . $options,

            'CREATE TABLE IF NOT EXISTS commerce_order_status_history (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                order_id BIGINT UNSIGNED NOT NULL,
                dimension VARCHAR(30) NOT NULL,
                from_status VARCHAR(40) NULL,
                to_status VARCHAR(40) NOT NULL,
                actor_type VARCHAR(30) NOT NULL DEFAULT "system",
                actor_id BIGINT UNSIGNED NULL,
                reason VARCHAR(500) NULL,
                metadata_json JSON NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY idx_commerce_status_order (order_id, created_at),
                CONSTRAINT fk_commerce_status_order FOREIGN KEY (order_id) REFERENCES commerce_orders(id) ON DELETE CASCADE
            )' . $options,

            'CREATE TABLE IF NOT EXISTS commerce_payment_attempts (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                order_id BIGINT UNSIGNED NOT NULL,
                provider VARCHAR(80) NOT NULL,
                environment VARCHAR(20) NOT NULL DEFAULT "sandbox",
                idempotency_key VARCHAR(190) NOT NULL,
                provider_transaction_id VARCHAR(190) NULL,
                status VARCHAR(40) NOT NULL DEFAULT "new",
                amount_minor BIGINT UNSIGNED NOT NULL,
                currency CHAR(3) NOT NULL,
                redirect_url VARCHAR(1000) NULL,
                request_hash CHAR(64) NULL,
                response_meta_json JSON NULL,
                expires_at TIMESTAMP NULL,
                settled_at TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_commerce_payment_idempotency (provider, idempotency_key),
                UNIQUE KEY uq_commerce_payment_transaction (provider, provider_transaction_id),
                KEY idx_commerce_payment_order (order_id, created_at),
                KEY idx_commerce_payment_status (provider, status, created_at),
                CONSTRAINT fk_commerce_payment_order FOREIGN KEY (order_id) REFERENCES commerce_orders(id) ON DELETE RESTRICT
            )' . $options,

            'CREATE TABLE IF NOT EXISTS commerce_payment_events (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                payment_attempt_id BIGINT UNSIGNED NULL,
                provider VARCHAR(80) NOT NULL,
                event_key VARCHAR(190) NOT NULL,
                provider_transaction_id VARCHAR(190) NULL,
                signature_valid TINYINT(1) NOT NULL DEFAULT 0,
                payload_hash CHAR(64) NOT NULL,
                received_status VARCHAR(60) NULL,
                processing_status VARCHAR(40) NOT NULL DEFAULT "received",
                error_code VARCHAR(120) NULL,
                received_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                processed_at TIMESTAMP NULL,
                UNIQUE KEY uq_commerce_payment_event (provider, event_key),
                KEY idx_commerce_payment_event_attempt (payment_attempt_id, received_at),
                KEY idx_commerce_payment_event_processing (processing_status, received_at),
                CONSTRAINT fk_commerce_event_attempt FOREIGN KEY (payment_attempt_id) REFERENCES commerce_payment_attempts(id) ON DELETE SET NULL
            )' . $options,

            'CREATE TABLE IF NOT EXISTS commerce_order_shipments (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                order_id BIGINT UNSIGNED NOT NULL,
                shipping_method_id BIGINT UNSIGNED NULL,
                provider VARCHAR(120) NULL,
                provider_shipment_id VARCHAR(190) NULL,
                status VARCHAR(40) NOT NULL DEFAULT "pending",
                tracking_number VARCHAR(190) NULL,
                tracking_url VARCHAR(1000) NULL,
                label_storage_key VARCHAR(500) NULL,
                request_meta_json JSON NULL,
                shipped_at TIMESTAMP NULL,
                delivered_at TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_commerce_provider_shipment (provider, provider_shipment_id),
                KEY idx_commerce_shipment_order (order_id, created_at),
                CONSTRAINT fk_commerce_shipment_order FOREIGN KEY (order_id) REFERENCES commerce_orders(id) ON DELETE CASCADE,
                CONSTRAINT fk_commerce_shipment_method FOREIGN KEY (shipping_method_id) REFERENCES commerce_shipping_methods(id) ON DELETE SET NULL
            )' . $options,

            'CREATE TABLE IF NOT EXISTS commerce_product_file_requirements (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                product_id BIGINT UNSIGNED NOT NULL,
                code VARCHAR(120) NOT NULL DEFAULT "print_file",
                label VARCHAR(190) NOT NULL,
                required TINYINT(1) NOT NULL DEFAULT 1,
                upload_timing VARCHAR(30) NOT NULL DEFAULT "checkout_or_after",
                allowed_extensions_json JSON NOT NULL,
                allowed_mime_types_json JSON NOT NULL,
                max_bytes BIGINT UNSIGNED NOT NULL,
                max_files INT UNSIGNED NOT NULL DEFAULT 1,
                sort_order INT NOT NULL DEFAULT 100,
                UNIQUE KEY uq_commerce_file_requirement (product_id, code),
                CONSTRAINT fk_commerce_requirement_product FOREIGN KEY (product_id) REFERENCES commerce_products(id) ON DELETE CASCADE
            )' . $options,

            'CREATE TABLE IF NOT EXISTS commerce_order_files (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                order_id BIGINT UNSIGNED NOT NULL,
                order_item_id BIGINT UNSIGNED NULL,
                requirement_id BIGINT UNSIGNED NULL,
                customer_id BIGINT UNSIGNED NULL,
                storage_key VARCHAR(500) NOT NULL,
                original_name VARCHAR(255) NOT NULL,
                extension VARCHAR(30) NOT NULL,
                mime_type VARCHAR(190) NOT NULL,
                size_bytes BIGINT UNSIGNED NOT NULL,
                checksum_sha256 CHAR(64) NOT NULL,
                status VARCHAR(40) NOT NULL DEFAULT "uploaded",
                scan_status VARCHAR(40) NOT NULL DEFAULT "pending",
                uploaded_by_type VARCHAR(30) NOT NULL DEFAULT "customer",
                uploaded_by_id BIGINT UNSIGNED NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_commerce_order_file_storage (storage_key),
                KEY idx_commerce_order_file_order (order_id, status),
                KEY idx_commerce_order_file_item (order_item_id),
                CONSTRAINT fk_commerce_file_order FOREIGN KEY (order_id) REFERENCES commerce_orders(id) ON DELETE CASCADE,
                CONSTRAINT fk_commerce_file_item FOREIGN KEY (order_item_id) REFERENCES commerce_order_items(id) ON DELETE SET NULL,
                CONSTRAINT fk_commerce_file_requirement FOREIGN KEY (requirement_id) REFERENCES commerce_product_file_requirements(id) ON DELETE SET NULL,
                CONSTRAINT fk_commerce_file_customer FOREIGN KEY (customer_id) REFERENCES commerce_customers(id) ON DELETE SET NULL
            )' . $options,

            'CREATE TABLE IF NOT EXISTS commerce_outbox (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                event_id CHAR(36) NOT NULL,
                event_type VARCHAR(190) NOT NULL,
                aggregate_type VARCHAR(120) NOT NULL,
                aggregate_id VARCHAR(190) NOT NULL,
                payload_json JSON NOT NULL,
                status VARCHAR(40) NOT NULL DEFAULT "pending",
                attempts INT UNSIGNED NOT NULL DEFAULT 0,
                available_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                processed_at TIMESTAMP NULL,
                last_error_code VARCHAR(120) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_commerce_outbox_event (event_id),
                KEY idx_commerce_outbox_ready (status, available_at),
                KEY idx_commerce_outbox_aggregate (aggregate_type, aggregate_id, created_at)
            )' . $options,

            'CREATE TABLE IF NOT EXISTS commerce_notification_deliveries (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                outbox_id BIGINT UNSIGNED NULL,
                order_id BIGINT UNSIGNED NULL,
                channel VARCHAR(30) NOT NULL DEFAULT "email",
                template_key VARCHAR(120) NOT NULL,
                recipient_hash CHAR(64) NOT NULL,
                status VARCHAR(40) NOT NULL DEFAULT "pending",
                provider_message_id VARCHAR(190) NULL,
                attempts INT UNSIGNED NOT NULL DEFAULT 0,
                last_error_code VARCHAR(120) NULL,
                sent_at TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_commerce_notification_status (status, created_at),
                KEY idx_commerce_notification_order (order_id, created_at),
                CONSTRAINT fk_commerce_notification_outbox FOREIGN KEY (outbox_id) REFERENCES commerce_outbox(id) ON DELETE SET NULL,
                CONSTRAINT fk_commerce_notification_order FOREIGN KEY (order_id) REFERENCES commerce_orders(id) ON DELETE SET NULL
            )' . $options,

            'CREATE TABLE IF NOT EXISTS commerce_import_runs (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                store_id BIGINT UNSIGNED NOT NULL,
                source_system VARCHAR(80) NOT NULL,
                mode VARCHAR(20) NOT NULL DEFAULT "dry_run",
                status VARCHAR(40) NOT NULL DEFAULT "running",
                source_snapshot VARCHAR(190) NULL,
                counters_json JSON NULL,
                report_json JSON NULL,
                started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                finished_at TIMESTAMP NULL,
                KEY idx_commerce_import_run (store_id, source_system, started_at),
                CONSTRAINT fk_commerce_import_store FOREIGN KEY (store_id) REFERENCES commerce_stores(id) ON DELETE CASCADE
            )' . $options,

            'CREATE TABLE IF NOT EXISTS commerce_import_mappings (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                store_id BIGINT UNSIGNED NOT NULL,
                source_system VARCHAR(80) NOT NULL,
                source_type VARCHAR(80) NOT NULL,
                external_id VARCHAR(190) NOT NULL,
                local_type VARCHAR(80) NOT NULL,
                local_id BIGINT UNSIGNED NOT NULL,
                source_hash CHAR(64) NULL,
                last_import_run_id BIGINT UNSIGNED NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_commerce_import_external (store_id, source_system, source_type, external_id),
                KEY idx_commerce_import_local (store_id, local_type, local_id),
                CONSTRAINT fk_commerce_mapping_store FOREIGN KEY (store_id) REFERENCES commerce_stores(id) ON DELETE CASCADE,
                CONSTRAINT fk_commerce_mapping_run FOREIGN KEY (last_import_run_id) REFERENCES commerce_import_runs(id) ON DELETE SET NULL
            )' . $options,

            'CREATE TABLE IF NOT EXISTS commerce_redirects (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                store_id BIGINT UNSIGNED NOT NULL,
                source_path VARCHAR(700) NOT NULL,
                target_path VARCHAR(700) NULL,
                status_code SMALLINT UNSIGNED NOT NULL DEFAULT 301,
                active TINYINT(1) NOT NULL DEFAULT 1,
                hit_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
                last_hit_at TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_commerce_redirect_source (store_id, source_path),
                KEY idx_commerce_redirect_active (store_id, active),
                CONSTRAINT fk_commerce_redirect_store FOREIGN KEY (store_id) REFERENCES commerce_stores(id) ON DELETE CASCADE
            )' . $options,
        ];
    }

    private function seedPermissions(PDO $pdo): void
    {
        $permissions = [
            'manage_orders' => 'Zamówienia',
            'manage_customers' => 'Klienci sklepu',
            'manage_payments' => 'Płatności',
            'manage_shipping' => 'Dostawy i wysyłki',
            'manage_order_files' => 'Pliki do zamówień',
            'manage_discounts' => 'Rabaty i promocje',
            'manage_commerce_settings' => 'Ustawienia sklepu',
        ];

        $insert = $pdo->prepare(
            'INSERT INTO cms_permissions (slug, name) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE name = VALUES(name)'
        );
        foreach ($permissions as $slug => $name) {
            $insert->execute([$slug, $name]);
        }

        $permissionId = $pdo->prepare('SELECT id FROM cms_permissions WHERE slug = ? LIMIT 1');
        $assign = $pdo->prepare(
            'INSERT IGNORE INTO cms_role_permissions (role, permission_id) VALUES (?, ?)'
        );
        foreach (['super_admin', 'reklamova_admin', 'reklamova', 'developer', 'client_admin', 'admin'] as $role) {
            foreach (array_keys($permissions) as $slug) {
                $permissionId->execute([$slug]);
                $id = (int) $permissionId->fetchColumn();
                if ($id > 0) {
                    $assign->execute([$role, $id]);
                }
            }
        }
    }
};
