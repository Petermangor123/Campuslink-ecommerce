<?php
// CampusLink migration 011: additive payment operations schema.
// Safe to re-run on MySQL 8/MariaDB. Historical capture-ledger backfill is
// intentionally left to the application.
require_once dirname(__DIR__) . '/config/db.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');

$columnExists = function (string $table, string $column) use ($conn): bool {
    $result = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
    $exists = $result->num_rows > 0;
    $result->free();
    return $exists;
};

$indexExists = function (string $table, string $index) use ($conn): bool {
    $result = $conn->query("SHOW INDEX FROM `{$table}` WHERE Key_name = '{$index}'");
    $exists = $result->num_rows > 0;
    $result->free();
    return $exists;
};

$addColumn = function (string $table, string $column, string $definition) use ($conn, $columnExists): void {
    if ($columnExists($table, $column)) {
        echo "skip: {$table}.{$column} already exists\n";
        return;
    }
    $conn->query("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
    echo "added: {$table}.{$column}\n";
};

$addColumn(
    'payments',
    'refund_status',
    "ENUM('none','pending','partial','full','needs_attention') NOT NULL DEFAULT 'none' AFTER `status`"
);
$addColumn(
    'payments',
    'refunded_amount_minor',
    'BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER `refund_status`'
);

if ($indexExists('payments', 'idx_payments_refund_status')) {
    echo "skip: idx_payments_refund_status already exists\n";
} else {
    $conn->query('ALTER TABLE `payments` ADD KEY `idx_payments_refund_status` (`refund_status`)');
    echo "added: idx_payments_refund_status\n";
}

$addColumn(
    'order_items',
    'commission_bps',
    'INT UNSIGNED NOT NULL DEFAULT 0 AFTER `unit_price`'
);
$addColumn(
    'order_items',
    'commission_amount_minor',
    'BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER `commission_bps`'
);
$sellerNetWasMissing = !$columnExists('order_items', 'seller_net_amount_minor');
$addColumn(
    'order_items',
    'seller_net_amount_minor',
    'BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER `commission_amount_minor`'
);

if ($sellerNetWasMissing) {
    $conn->query(
        'UPDATE `order_items`
         SET `seller_net_amount_minor` = ROUND(`quantity` * `unit_price` * 100)
         WHERE `seller_net_amount_minor` = 0'
    );
    echo "backfilled: historical seller net amounts\n";
}

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

$tablesToCreate = [
    'refunds' => $createRefunds,
    'refund_allocations' => $createRefundAllocations,
    'payouts' => $createPayouts,
    'payout_allocations' => $createPayoutAllocations,
    'disputes' => $createDisputes,
    'financial_batches' => $createFinancialBatches,
    'financial_entries' => $createFinancialEntries,
];

foreach ($tablesToCreate as $table => $sql) {
    $conn->query($sql);
    echo "ensured: {$table}\n";
}

echo "DONE\n";

