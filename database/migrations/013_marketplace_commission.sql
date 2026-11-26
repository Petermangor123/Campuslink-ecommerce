-- CampusLink migration 013: administrator-managed marketplace commission.
-- Additive and safe to re-run. Does not update order_items, refunds,
-- payouts, or financial ledger rows. Existing orders keep their snapshots.

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
