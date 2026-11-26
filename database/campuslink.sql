-- CampusLink schema (fresh install).
-- Import once via phpMyAdmin or the MySQL client:
--   mysql -u USER -p < database/campuslink.sql
--
-- Creates the database and uses CREATE TABLE IF NOT EXISTS, so re-importing
-- does not drop existing rows. It does not repair a database created from an
-- older schema — run setup_database.php or apply files in database/migrations/
-- for that.

CREATE DATABASE IF NOT EXISTS `ecommerce_2026A_chol_thiong`
    DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `ecommerce_2026A_chol_thiong`;

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- USERS
CREATE TABLE IF NOT EXISTS `users` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(100) NOT NULL,
    `email` VARCHAR(150) NOT NULL,
    `password` VARCHAR(255) NOT NULL,
    `role` ENUM('buyer','seller','admin') NOT NULL DEFAULT 'buyer',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `vendor_status` ENUM('pending','approved','rejected','suspended') NOT NULL DEFAULT 'pending',
    `phone` VARCHAR(20) DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_users_email` (`email`),
    KEY `idx_users_vendor_status` (`vendor_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- CATEGORIES
CREATE TABLE IF NOT EXISTS `categories` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(80) NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_categories_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- PRODUCTS
CREATE TABLE IF NOT EXISTS `products` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `seller_id` INT UNSIGNED NOT NULL,
    `category_id` INT UNSIGNED DEFAULT NULL,
    `title` VARCHAR(200) NOT NULL,
    `description` TEXT DEFAULT NULL,
    `price` DECIMAL(10,2) NOT NULL,
    `stock` INT UNSIGNED NOT NULL DEFAULT 1,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `image` VARCHAR(255) DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_products_seller` (`seller_id`),
    KEY `idx_products_category` (`category_id`),
    CONSTRAINT `fk_products_seller`
        FOREIGN KEY (`seller_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_products_category`
        FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- CART
CREATE TABLE IF NOT EXISTS `cart` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `buyer_id` INT UNSIGNED NOT NULL,
    `product_id` INT UNSIGNED NOT NULL,
    `quantity` INT UNSIGNED NOT NULL DEFAULT 1,
    `added_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_cart_buyer_product` (`buyer_id`, `product_id`),
    KEY `idx_cart_product` (`product_id`),
    CONSTRAINT `fk_cart_buyer`
        FOREIGN KEY (`buyer_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_cart_product`
        FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ORDERS
CREATE TABLE IF NOT EXISTS `orders` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `buyer_id` INT UNSIGNED DEFAULT NULL,
    `customer_name` VARCHAR(100) DEFAULT NULL,
    `customer_email` VARCHAR(150) DEFAULT NULL,
    `customer_phone` VARCHAR(30) DEFAULT NULL,
    `delivery_address` VARCHAR(500) DEFAULT NULL,
    `order_notes` VARCHAR(500) DEFAULT NULL,
    `total` DECIMAL(10,2) NOT NULL,
    `status` ENUM('pending','paid','shipped','delivered','cancelled') NOT NULL DEFAULT 'pending',
    `payment_status` ENUM('pending','paid','failed','cancelled') NOT NULL DEFAULT 'pending',
    `reference_code` VARCHAR(20) DEFAULT NULL,
    `tracking_token_hash` CHAR(64) DEFAULT NULL,
    `checkout_key` CHAR(64) DEFAULT NULL,
    `confirmation_sent` TINYINT(1) NOT NULL DEFAULT 0,
    `stock_restored` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_orders_reference` (`reference_code`),
    UNIQUE KEY `uq_orders_checkout_key` (`checkout_key`),
    KEY `idx_orders_buyer` (`buyer_id`),
    CONSTRAINT `fk_orders_buyer_users`
        FOREIGN KEY (`buyer_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ORDER ITEMS
CREATE TABLE IF NOT EXISTS `order_items` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `order_id` INT UNSIGNED NOT NULL,
    `product_id` INT UNSIGNED NOT NULL,
    `seller_id` INT UNSIGNED DEFAULT NULL,
    `quantity` INT UNSIGNED NOT NULL,
    `unit_price` DECIMAL(10,2) NOT NULL,
    `commission_bps` INT UNSIGNED NOT NULL DEFAULT 0,
    `commission_amount_minor` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `seller_net_amount_minor` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `fulfilment_status` VARCHAR(32) NOT NULL DEFAULT 'awaiting_payment',
    `rejection_reason` VARCHAR(500) DEFAULT NULL,
    `fulfilled_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_order_items_order` (`order_id`),
    KEY `idx_order_items_seller` (`seller_id`),
    KEY `idx_order_items_fulfilment` (`fulfilment_status`),
    KEY `idx_order_items_product` (`product_id`),
    CONSTRAINT `fk_order_items_order`
        FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_order_items_product`
        FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- PAYMENTS
CREATE TABLE IF NOT EXISTS `payments` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `order_id` INT UNSIGNED NOT NULL,
    `amount` DECIMAL(10,2) NOT NULL,
    `status` ENUM('pending','paid','failed','cancelled') NOT NULL DEFAULT 'pending',
    `refund_status` ENUM('none','pending','partial','full','needs_attention') NOT NULL DEFAULT 'none',
    `refunded_amount_minor` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `provider` VARCHAR(50) NOT NULL DEFAULT 'manual_review',
    `gateway_reference` VARCHAR(100) DEFAULT NULL,
    `currency` CHAR(3) NOT NULL DEFAULT 'GHS',
    `verified_at` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_payments_order` (`order_id`),
    UNIQUE KEY `uq_payments_gateway_ref` (`gateway_reference`),
    KEY `idx_payments_refund_status` (`refund_status`),
    CONSTRAINT `fk_payments_order`
        FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- PAYMENT EVENTS
CREATE TABLE IF NOT EXISTS `payment_events` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `event_id` VARCHAR(120) NOT NULL,
    `event_type` VARCHAR(80) NOT NULL,
    `order_id` INT UNSIGNED DEFAULT NULL,
    `payload_hash` CHAR(64) NOT NULL,
    `process_status` ENUM('claimed','applied','failed') NOT NULL DEFAULT 'claimed',
    `last_error` VARCHAR(255) DEFAULT NULL,
    `applied_at` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_payment_events_event` (`event_id`),
    KEY `idx_payment_events_order` (`order_id`),
    CONSTRAINT `fk_payment_events_order`
        FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ORDER EVENTS
CREATE TABLE IF NOT EXISTS `order_events` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `order_id` INT UNSIGNED NOT NULL,
    `order_item_id` INT UNSIGNED DEFAULT NULL,
    `actor_user_id` INT UNSIGNED DEFAULT NULL,
    `actor_role` VARCHAR(20) NOT NULL DEFAULT 'system',
    `event_type` VARCHAR(40) NOT NULL,
    `message` VARCHAR(500) NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_order_events_order` (`order_id`, `created_at`),
    CONSTRAINT `fk_order_events_order`
        FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- NOTIFICATIONS
CREATE TABLE IF NOT EXISTS `notifications` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `order_id` INT UNSIGNED DEFAULT NULL,
    `title` VARCHAR(120) NOT NULL,
    `body` VARCHAR(500) NOT NULL,
    `is_read` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_notifications_user` (`user_id`, `is_read`, `created_at`),
    KEY `idx_notifications_order` (`order_id`),
    CONSTRAINT `fk_notifications_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_notifications_order`
        FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ORDER REFERENCE SEQUENCE
CREATE TABLE IF NOT EXISTS `order_ref_seq` (
    `id` TINYINT UNSIGNED NOT NULL,
    `next_val` INT UNSIGNED NOT NULL,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ADMIN AUDIT LOG
CREATE TABLE IF NOT EXISTS `admin_audit_log` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `admin_id` INT UNSIGNED NOT NULL,
    `vendor_id` INT UNSIGNED DEFAULT NULL,
    `product_id` INT UNSIGNED DEFAULT NULL,
    `action` VARCHAR(64) NOT NULL,
    `before_json` JSON NULL,
    `after_json` JSON NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_admin_audit_admin` (`admin_id`),
    KEY `idx_admin_audit_vendor` (`vendor_id`),
    KEY `idx_admin_audit_product` (`product_id`),
    KEY `idx_admin_audit_created` (`created_at`),
    CONSTRAINT `fk_admin_audit_admin`
        FOREIGN KEY (`admin_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- REFUNDS
CREATE TABLE IF NOT EXISTS `refunds` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `order_id` INT UNSIGNED NOT NULL,
    `payment_id` INT UNSIGNED NOT NULL,
    `amount_minor` BIGINT UNSIGNED NOT NULL,
    `currency` CHAR(3) NOT NULL,
    `status` ENUM('pending','processing','needs_attention','processed','failed','cancelled') NOT NULL DEFAULT 'pending',
    `provider` VARCHAR(50) NOT NULL,
    `reason` VARCHAR(500) NOT NULL,
    `idempotency_key` VARCHAR(100) NOT NULL,
    `gateway_refund_id` VARCHAR(100) NULL DEFAULT NULL,
    `gateway_reference` VARCHAR(100) NULL DEFAULT NULL,
    `external_reference` VARCHAR(100) NULL DEFAULT NULL,
    `created_by` INT UNSIGNED NULL DEFAULT NULL,
    `failure_reason` VARCHAR(500) NULL DEFAULT NULL,
    `requested_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `processed_at` TIMESTAMP NULL DEFAULT NULL,
    `failed_at` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_refunds_idempotency_key` (`idempotency_key`),
    UNIQUE KEY `uq_refunds_gateway_refund_id` (`gateway_refund_id`),
    UNIQUE KEY `uq_refunds_gateway_reference` (`gateway_reference`),
    KEY `idx_refunds_order_requested` (`order_id`, `requested_at`),
    KEY `idx_refunds_payment` (`payment_id`),
    KEY `idx_refunds_status_requested` (`status`, `requested_at`),
    KEY `idx_refunds_external_reference` (`external_reference`),
    KEY `idx_refunds_created_by` (`created_by`),
    CONSTRAINT `fk_refunds_order`
        FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_refunds_payment`
        FOREIGN KEY (`payment_id`) REFERENCES `payments` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_refunds_created_by`
        FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- REFUND ALLOCATIONS
CREATE TABLE IF NOT EXISTS `refund_allocations` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `refund_id` INT UNSIGNED NOT NULL,
    `order_item_id` INT UNSIGNED NOT NULL,
    `buyer_refund_minor` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `commission_reversal_minor` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `seller_net_reversal_minor` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_refund_allocations_refund_item` (`refund_id`, `order_item_id`),
    KEY `idx_refund_allocations_order_item` (`order_item_id`),
    CONSTRAINT `fk_refund_allocations_refund`
        FOREIGN KEY (`refund_id`) REFERENCES `refunds` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_refund_allocations_order_item`
        FOREIGN KEY (`order_item_id`) REFERENCES `order_items` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- PAYOUTS
CREATE TABLE IF NOT EXISTS `payouts` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `seller_id` INT UNSIGNED NOT NULL,
    `amount_minor` BIGINT UNSIGNED NOT NULL,
    `currency` CHAR(3) NOT NULL,
    `status` ENUM('pending','completed','void') NOT NULL DEFAULT 'pending',
    `settlement_reference` VARCHAR(100) NOT NULL,
    `method` VARCHAR(50) NOT NULL,
    `note` VARCHAR(500) NOT NULL,
    `created_by` INT UNSIGNED NULL DEFAULT NULL,
    `completed_by` INT UNSIGNED NULL DEFAULT NULL,
    `external_reference` VARCHAR(100) NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `completed_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_payouts_settlement_reference` (`settlement_reference`),
    KEY `idx_payouts_seller_status` (`seller_id`, `status`),
    KEY `idx_payouts_status_created` (`status`, `created_at`),
    KEY `idx_payouts_external_reference` (`external_reference`),
    KEY `idx_payouts_created_by` (`created_by`),
    KEY `idx_payouts_completed_by` (`completed_by`),
    CONSTRAINT `fk_payouts_seller`
        FOREIGN KEY (`seller_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_payouts_created_by`
        FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_payouts_completed_by`
        FOREIGN KEY (`completed_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- PAYOUT ALLOCATIONS
CREATE TABLE IF NOT EXISTS `payout_allocations` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `payout_id` INT UNSIGNED NOT NULL,
    `order_item_id` INT UNSIGNED NOT NULL,
    `amount_minor` BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_payout_allocations_payout_item` (`payout_id`, `order_item_id`),
    KEY `idx_payout_allocations_order_item` (`order_item_id`),
    CONSTRAINT `fk_payout_allocations_payout`
        FOREIGN KEY (`payout_id`) REFERENCES `payouts` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_payout_allocations_order_item`
        FOREIGN KEY (`order_item_id`) REFERENCES `order_items` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- DISPUTES
CREATE TABLE IF NOT EXISTS `disputes` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `order_id` INT UNSIGNED NOT NULL,
    `payment_id` INT UNSIGNED NOT NULL,
    `gateway_dispute_id` VARCHAR(100) NULL DEFAULT NULL,
    `status` ENUM('open','under_review','resolved','rejected') NOT NULL DEFAULT 'open',
    `resolution` VARCHAR(30) NULL DEFAULT NULL,
    `disputed_amount_minor` BIGINT UNSIGNED NULL DEFAULT NULL,
    `currency` CHAR(3) NOT NULL,
    `reason` VARCHAR(100) NOT NULL,
    `customer_message` VARCHAR(2000) NULL DEFAULT NULL,
    `admin_note` VARCHAR(2000) NULL DEFAULT NULL,
    `resolution_note` VARCHAR(2000) NULL DEFAULT NULL,
    `refund_id` INT UNSIGNED NULL DEFAULT NULL,
    `created_by_user_id` INT UNSIGNED NULL DEFAULT NULL,
    `resolved_by_user_id` INT UNSIGNED NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `resolved_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_disputes_gateway_dispute_id` (`gateway_dispute_id`),
    KEY `idx_disputes_order` (`order_id`),
    KEY `idx_disputes_payment` (`payment_id`),
    KEY `idx_disputes_status_created` (`status`, `created_at`),
    KEY `idx_disputes_refund` (`refund_id`),
    KEY `idx_disputes_created_by_user` (`created_by_user_id`),
    KEY `idx_disputes_resolved_by_user` (`resolved_by_user_id`),
    CONSTRAINT `fk_disputes_order`
        FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_disputes_payment`
        FOREIGN KEY (`payment_id`) REFERENCES `payments` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_disputes_refund`
        FOREIGN KEY (`refund_id`) REFERENCES `refunds` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_disputes_created_by_user`
        FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_disputes_resolved_by_user`
        FOREIGN KEY (`resolved_by_user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- FINANCIAL BATCHES
CREATE TABLE IF NOT EXISTS `financial_batches` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `source_type` VARCHAR(40) NOT NULL,
    `source_id` VARCHAR(100) NOT NULL,
    `event_key` VARCHAR(160) NOT NULL,
    `order_id` INT UNSIGNED NULL DEFAULT NULL,
    `currency` CHAR(3) NOT NULL,
    `description` VARCHAR(255) NOT NULL,
    `created_by_user_id` INT UNSIGNED NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_financial_batches_event_key` (`event_key`),
    KEY `idx_financial_batches_source` (`source_type`, `source_id`),
    KEY `idx_financial_batches_order` (`order_id`),
    KEY `idx_financial_batches_created_by_user` (`created_by_user_id`),
    KEY `idx_financial_batches_created` (`created_at`),
    CONSTRAINT `fk_financial_batches_order`
        FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_financial_batches_created_by_user`
        FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- FINANCIAL ENTRIES
CREATE TABLE IF NOT EXISTS `financial_entries` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `batch_id` INT UNSIGNED NOT NULL,
    `account` VARCHAR(40) NOT NULL,
    `seller_id` INT UNSIGNED NULL DEFAULT NULL,
    `order_item_id` INT UNSIGNED NULL DEFAULT NULL,
    `debit_minor` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `credit_minor` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    KEY `idx_financial_entries_batch_account` (`batch_id`, `account`),
    KEY `idx_financial_entries_seller` (`seller_id`),
    KEY `idx_financial_entries_order_item` (`order_item_id`),
    CONSTRAINT `fk_financial_entries_batch`
        FOREIGN KEY (`batch_id`) REFERENCES `financial_batches` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_financial_entries_seller`
        FOREIGN KEY (`seller_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_financial_entries_order_item`
        FOREIGN KEY (`order_item_id`) REFERENCES `order_items` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- MARKETPLACE SETTINGS (commission rate). Empty until an administrator saves one.
CREATE TABLE IF NOT EXISTS `marketplace_settings` (
    `setting_key` VARCHAR(64) NOT NULL,
    `setting_value` VARCHAR(255) NOT NULL,
    `updated_by` INT UNSIGNED DEFAULT NULL,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`setting_key`),
    KEY `idx_marketplace_settings_updated_by` (`updated_by`),
    CONSTRAINT `fk_marketplace_settings_updated_by`
        FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- Seed categories
INSERT IGNORE INTO `categories` (`name`) VALUES
    ('Textbooks'),
    ('Electronics'),
    ('Clothing'),
    ('Furniture'),
    ('Stationery'),
    ('Other');

-- Seed the order reference sequence (starts at CL-001)
INSERT INTO `order_ref_seq` (`id`, `next_val`)
SELECT 1, 1
WHERE NOT EXISTS (SELECT 1 FROM `order_ref_seq` WHERE `id` = 1);

-- No default administrator is created.
-- Register an account and assign role='admin' through a secure admin process,
-- or run setup_admin.php.
