<?php
/**
 * CLI-only CampusLink database migration / repair script.
 *
 * Run from the project root: php setup_database.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

if (in_array('--help', $argv, true)) {
    fwrite(STDOUT, "Usage: php setup_database.php\n");
    exit(0);
}

require_once __DIR__ . '/config/db.php';

header('Content-Type: text/plain; charset=utf-8');

echo "CampusLink database migration/repair\n";
echo "database : {$database}\n";
echo "server   : {$conn->server_info}\n\n";

$conn->set_charset('utf8mb4');

// Existing tables.
$existingTables = [];
$r = $conn->query('SHOW TABLES');
while ($row = $r->fetch_array()) { $existingTables[] = $row[0]; }
$r->free();
sort($existingTables);
echo "existing tables: " . (implode(', ', $existingTables) ?: '(none)') . "\n\n";

/** Get column names of a table. */
$columnsOf = function (string $table) use ($conn): array {
    $cols = [];
    $r = $conn->query("SHOW COLUMNS FROM `{$table}`");
    if ($r) {
        while ($row = $r->fetch_assoc()) { $cols[] = $row['Field']; }
        $r->free();
    }
    return $cols;
};

/** Execute a statement, report result. Safe with strict MySQLi mode. */
$exec = function (string $label, string $sql) use ($conn): bool {
    try {
        if ($conn->query($sql)) {
            echo "  ok   : {$label}\n";
            return true;
        }
        echo "  FAIL : {$label} -> {$conn->error}\n";
    } catch (Throwable $e) {
        echo "  skip : {$label} -> {$e->getMessage()}\n";
    }
    return false;
};

/** Check whether an index exists on a table. */
$indexExists = function (string $table, string $indexName) use ($conn): bool {
    $r = @$conn->query("SHOW INDEX FROM `{$table}` WHERE Key_name = '{$indexName}'");
    if (!$r) { return false; }
    $exists = $r->num_rows > 0;
    $r->free();
    return $exists;
};

// ---------------------------------------------------------------------------
// 1. Create missing tables with the full, correct schema.
// ---------------------------------------------------------------------------
echo "== Creating missing tables ==\n";

$createUsers = <<<SQL
CREATE TABLE IF NOT EXISTS `users` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(100) NOT NULL,
    `email` VARCHAR(150) NOT NULL,
    `password` VARCHAR(255) NOT NULL,
    `role` ENUM('buyer','seller','admin') NOT NULL DEFAULT 'buyer',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `phone` VARCHAR(20) DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_users_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

$createCategories = <<<SQL
CREATE TABLE IF NOT EXISTS `categories` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(80) NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_categories_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

$createProducts = <<<SQL
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
    KEY `idx_products_category` (`category_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

$createCart = <<<SQL
CREATE TABLE IF NOT EXISTS `cart` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `buyer_id` INT UNSIGNED NOT NULL,
    `product_id` INT UNSIGNED NOT NULL,
    `quantity` INT UNSIGNED NOT NULL DEFAULT 1,
    `added_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_cart_buyer_product` (`buyer_id`, `product_id`),
    KEY `idx_cart_product` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

$createOrders = <<<SQL
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
    KEY `idx_orders_buyer` (`buyer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

$createOrderItems = <<<SQL
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
    KEY `idx_order_items_product` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

$createPayments = <<<SQL
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
    KEY `idx_payments_refund_status` (`refund_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

$createPaymentEvents = <<<SQL
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
    KEY `idx_payment_events_order` (`order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

$createOrderEvents = <<<SQL
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
    KEY `idx_order_events_order` (`order_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

$createNotifications = <<<SQL
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
    KEY `idx_notifications_order` (`order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

$createOrderRefSeq = <<<SQL
CREATE TABLE IF NOT EXISTS `order_ref_seq` (
    `id` TINYINT UNSIGNED NOT NULL,
    `next_val` INT UNSIGNED NOT NULL,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

$createAdminAuditLog = <<<SQL
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
    KEY `idx_admin_audit_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

$createRefunds = <<<SQL
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

$createRefundAllocations = <<<SQL
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

$createPayouts = <<<SQL
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

$createPayoutAllocations = <<<SQL
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

$createDisputes = <<<SQL
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

$createFinancialBatches = <<<SQL
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

$createFinancialEntries = <<<SQL
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

$createMarketplaceSettings = <<<SQL
CREATE TABLE IF NOT EXISTS `marketplace_settings` (
    `setting_key` VARCHAR(64) NOT NULL,
    `setting_value` VARCHAR(255) NOT NULL,
    `updated_by` INT UNSIGNED DEFAULT NULL,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`setting_key`),
    KEY `idx_marketplace_settings_updated_by` (`updated_by`),
    CONSTRAINT `fk_marketplace_settings_updated_by`
        FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

$tablesToCreate = [
    'users'             => $createUsers,
    'categories'        => $createCategories,
    'products'          => $createProducts,
    'cart'              => $createCart,
    'orders'            => $createOrders,
    'order_items'       => $createOrderItems,
    'payments'          => $createPayments,
    'payment_events'    => $createPaymentEvents,
    'order_events'      => $createOrderEvents,
    'notifications'     => $createNotifications,
    'order_ref_seq'     => $createOrderRefSeq,
    'admin_audit_log'   => $createAdminAuditLog,
    'marketplace_settings' => $createMarketplaceSettings,
    'refunds'           => $createRefunds,
    'refund_allocations' => $createRefundAllocations,
    'payouts'           => $createPayouts,
    'payout_allocations' => $createPayoutAllocations,
    'disputes'          => $createDisputes,
    'financial_batches' => $createFinancialBatches,
    'financial_entries' => $createFinancialEntries,
];

foreach ($tablesToCreate as $name => $sql) {
    if (in_array($name, $existingTables, true)) {
        echo "  skip : `{$name}` already exists\n";
    } else {
        $exec("CREATE TABLE `{$name}`", $sql);
    }
}
echo "\n";

// ---------------------------------------------------------------------------
// 2. Repair existing tables: rename legacy columns + add missing columns.
// ---------------------------------------------------------------------------
echo "== Repairing existing tables (rename legacy columns, add missing) ==\n";

// users: expected columns. We only add missing ones (no known legacy renames).
$usersExpected = [
    'id'         => "INT UNSIGNED NOT NULL AUTO_INCREMENT",
    'name'       => "VARCHAR(100) NOT NULL DEFAULT ''",
    'email'      => "VARCHAR(150) NOT NULL DEFAULT ''",
    'password'   => "VARCHAR(255) NOT NULL DEFAULT ''",
    'role'       => "ENUM('buyer','seller','admin') NOT NULL DEFAULT 'buyer'",
    'is_active'  => "TINYINT(1) NOT NULL DEFAULT 1",
    'phone'      => "VARCHAR(20) DEFAULT NULL",
    'created_at' => "TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP",
];

// categories: legacy cat_id -> id, cat_name -> name.
$categoriesExpected = [
    'id'   => "INT UNSIGNED NOT NULL AUTO_INCREMENT",
    'name' => "VARCHAR(80) NOT NULL DEFAULT ''",
];
$categoriesRenames = ['cat_id' => 'id', 'cat_name' => 'name'];

// products: known legacy column names.
$productsExpected = [
    'id'          => "INT UNSIGNED NOT NULL AUTO_INCREMENT",
    'seller_id'   => "INT UNSIGNED NOT NULL DEFAULT 0",
    'category_id' => "INT UNSIGNED DEFAULT NULL",
    'title'       => "VARCHAR(200) NOT NULL DEFAULT ''",
    'description' => "TEXT DEFAULT NULL",
    'price'       => "DECIMAL(10,2) NOT NULL DEFAULT 0.00",
    'stock'       => "INT UNSIGNED NOT NULL DEFAULT 1",
    'is_active'   => "TINYINT(1) NOT NULL DEFAULT 1",
    'image'       => "VARCHAR(255) DEFAULT NULL",
    'created_at'  => "TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP",
];
$productsRenames = [
    'product_id'    => 'id',
    'product_title' => 'title',
    'product_price' => 'price',
    'product_desc'  => 'description',
    'product_image' => 'image',
    'product_cat'   => 'category_id',
];

$repairTable = function (string $table, array $expected, array $renames) use ($conn, $columnsOf, $exec) {
    $tableCheck = $conn->query("SHOW TABLES LIKE '{$table}'");
    if (!$tableCheck || $tableCheck->num_rows === 0) {
        if ($tableCheck) {
            $tableCheck->free();
        }
        return;
    }
    $tableCheck->free();
    $cols = $columnsOf($table);
    echo "-- `{$table}` columns: " . implode(', ', $cols) . "\n";

    foreach ($expected as $col => $def) {
        if (in_array($col, $cols, true)) {
            continue; // already present
        }
        // Check for a legacy equivalent to rename.
        $renamed = false;
        foreach ($renames as $legacy => $target) {
            if ($target === $col && in_array($legacy, $cols, true)) {
                $sql = "ALTER TABLE `{$table}` CHANGE `{$legacy}` `{$target}` {$def}";
                if ($exec("rename `{$legacy}` -> `{$target}`", $sql)) {
                    $renamed = true;
                    $cols = array_values(array_diff($cols, [$legacy]));
                    $cols[] = $target;
                }
                break;
            }
        }
        if (!$renamed) {
            $sql = "ALTER TABLE `{$table}` ADD COLUMN `{$col}` {$def}";
            $exec("add column `{$col}`", $sql);
            $cols[] = $col;
        }
    }
    echo "\n";
};

$repairTable('users', $usersExpected, []);
$repairTable('categories', $categoriesExpected, $categoriesRenames);
$repairTable('products', $productsExpected, $productsRenames);

// Payment operations columns added by migration 011.
$sellerNetWasMissing = !in_array('seller_net_amount_minor', $columnsOf('order_items'), true);
$repairTable('order_items', [
    'commission_bps' => 'INT UNSIGNED NOT NULL DEFAULT 0',
    'commission_amount_minor' => 'BIGINT UNSIGNED NOT NULL DEFAULT 0',
    'seller_net_amount_minor' => 'BIGINT UNSIGNED NOT NULL DEFAULT 0',
], []);
$repairTable('payments', [
    'refund_status' => "ENUM('none','pending','partial','full','needs_attention') NOT NULL DEFAULT 'none'",
    'refunded_amount_minor' => 'BIGINT UNSIGNED NOT NULL DEFAULT 0',
], []);
if ($sellerNetWasMissing) {
    $exec(
        'backfill historical seller net amounts',
        'UPDATE `order_items` SET `seller_net_amount_minor` = ROUND(`quantity` * `unit_price` * 100) WHERE `seller_net_amount_minor` = 0'
    );
}

// Ensure unique keys exist (safe: skip if already present).
echo "== Ensuring key indexes ==\n";
if (!$indexExists('users', 'uq_users_email')) {
    $exec("uq_users_email (users.email)", "ALTER TABLE `users` ADD UNIQUE KEY `uq_users_email` (`email`)");
} else { echo "  skip : uq_users_email already exists\n"; }
if (!$indexExists('categories', 'uq_categories_name')) {
    $exec("uq_categories_name (categories.name)", "ALTER TABLE `categories` ADD UNIQUE KEY `uq_categories_name` (`name`)");
} else { echo "  skip : uq_categories_name already exists\n"; }
if (!$indexExists('products', 'idx_products_seller')) {
    $exec("idx_products_seller", "ALTER TABLE `products` ADD KEY `idx_products_seller` (`seller_id`)");
} else { echo "  skip : idx_products_seller already exists\n"; }
if (!$indexExists('products', 'idx_products_category')) {
    $exec("idx_products_category", "ALTER TABLE `products` ADD KEY `idx_products_category` (`category_id`)");
} else { echo "  skip : idx_products_category already exists\n"; }
if (!$indexExists('payments', 'idx_payments_refund_status')) {
    $exec("idx_payments_refund_status", "ALTER TABLE `payments` ADD KEY `idx_payments_refund_status` (`refund_status`)");
} else { echo "  skip : idx_payments_refund_status already exists\n"; }
echo "\n";

// ---------------------------------------------------------------------------
// 3. Seed reference data.
// ---------------------------------------------------------------------------
echo "== Seeding reference data ==\n";
$exec("seed categories", "INSERT IGNORE INTO `categories` (`name`) VALUES ('Textbooks'),('Electronics'),('Clothing'),('Furniture'),('Stationery'),('Other')");
$exec("seed order_ref_seq", "INSERT INTO `order_ref_seq` (`id`, `next_val`) SELECT 1, 1 WHERE NOT EXISTS (SELECT 1 FROM `order_ref_seq` WHERE `id` = 1)");
echo "\n";

// ---------------------------------------------------------------------------
// 4. Verification: run the exact homepage queries.
// ---------------------------------------------------------------------------
echo "== Verification (homepage queries) ==\n";
$checks = [
    "SELECT COUNT(*) AS listings, SUM(stock > 0) AS in_stock, COUNT(DISTINCT seller_id) AS sellers FROM products WHERE is_active = 1",
    "SELECT COUNT(*) AS c FROM users WHERE role = 'buyer' AND is_active = 1",
    "SELECT c.id, c.name, COUNT(p.id) AS listings FROM categories c LEFT JOIN products p ON p.category_id = c.id AND p.is_active = 1 GROUP BY c.id, c.name ORDER BY c.name",
    "SELECT p.id, p.seller_id, p.title, p.price, p.image, p.stock, p.created_at, c.name AS category, u.name AS seller FROM products p LEFT JOIN categories c ON c.id = p.category_id LEFT JOIN users u ON u.id = p.seller_id WHERE p.is_active = 1 ORDER BY p.created_at DESC, p.id DESC LIMIT 8",
];
$allOk = true;
foreach ($checks as $i => $sql) {
    $r = @$conn->query($sql);
    if ($r) {
        $count = $r->num_rows;
        $r->free();
        echo "  ok   : query #" . ($i + 1) . " ({$count} rows)\n";
    } else {
        $allOk = false;
        echo "  FAIL : query #" . ($i + 1) . " -> {$conn->error}\n";
    }
}
echo "\n";

// ---------------------------------------------------------------------------
// 5. Summary.
// ---------------------------------------------------------------------------
echo "== Result ==\n";
echo $allOk ? "SUCCESS: all homepage queries pass — the 500 should be resolved.\n"
            : "FAILURE: some queries still fail. Check the errors above.\n";

if (PHP_SAPI === 'cli' && in_array('--backfill-captures', $argv, true)) {
    require_once __DIR__ . '/includes/order_service.php';
    $limit = 100;
    foreach ($argv as $argument) {
        if (preg_match('/^--limit=(\d+)$/', $argument, $matches)) {
            $limit = (int)$matches[1];
        }
    }
    if ($limit < 1 || $limit > 500) {
        fwrite(STDERR, "Use --limit=1..500.\n");
        exit(1);
    }
    $apply = in_array('--apply', $argv, true);
    $stmt = $conn->prepare(
        "SELECT p.id, p.order_id, p.provider, o.reference_code
         FROM payments p
         INNER JOIN orders o ON o.id=p.order_id
         LEFT JOIN financial_batches fb ON fb.event_key=CONCAT('capture:',p.id)
         WHERE p.status='paid' AND o.payment_status='paid' AND fb.id IS NULL
         ORDER BY p.id ASC LIMIT ?"
    );
    $stmt->bind_param('i', $limit);
    $stmt->execute();
    $payments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $posted = 0;
    $failed = 0;
    foreach ($payments as $payment) {
        $label = (string)$payment['reference_code'] . ' (payment #' . (int)$payment['id'] . ')';
        if (!$apply) {
            echo 'would post: ' . $label . "\n";
            continue;
        }
        $conn->begin_transaction();
        try {
            post_payment_capture($conn, (int)$payment['order_id'], null, (string)$payment['provider']);
            $conn->commit();
            $posted++;
            echo 'posted: ' . $label . "\n";
        } catch (Throwable $e) {
            $conn->rollback();
            $failed++;
            echo 'failed: ' . $label . ' — ' . $e->getMessage() . "\n";
        }
    }
    echo $apply ? "Done: {$posted} posted, {$failed} failed.\n" : "Dry run only; use --apply to post these captures.\n";
    exit($failed === 0 ? 0 : 1);
}

if (PHP_SAPI === 'cli' && in_array('--expire-orders', $argv, true)) {
    require_once __DIR__ . '/includes/order_service.php';
    $hours = 24;
    $limit = 100;
    foreach ($argv as $argument) {
        if (preg_match('/^--hours=(\d+)$/', $argument, $matches)) {
            $hours = (int)$matches[1];
        }
        if (preg_match('/^--limit=(\d+)$/', $argument, $matches)) {
            $limit = (int)$matches[1];
        }
    }
    if ($hours < 1 || $hours > 8760 || $limit < 1 || $limit > 500) {
        fwrite(STDERR, "Use --hours=1..8760 and --limit=1..500.\n");
        exit(1);
    }
    try {
        $results = expire_abandoned_orders($conn, $hours, $limit, !in_array('--apply', $argv, true));
        foreach ($results as $result) {
            echo ($result['reference'] ?? ('#' . $result['order_id'])) . ': ' . $result['action'];
            if (!empty($result['reason'])) {
                echo ' — ' . $result['reason'];
            }
            echo "\n";
        }
        echo 'DONE: ' . count($results) . " order(s) examined\n";
        exit(0);
    } catch (Throwable $e) {
        fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n");
        exit(1);
    }
}

echo "\nDatabase setup complete.\n";
