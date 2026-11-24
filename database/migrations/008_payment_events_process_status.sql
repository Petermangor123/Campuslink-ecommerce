-- CampusLink migration 008 (idempotent)
-- Retry-safe Paystack webhook processing.
-- Adds process_status so failed verification does not permanently consume an event.
--
-- Safe to re-run: columns are added only when missing.
-- The one-time legacy UPDATE runs only when process_status is first introduced.
-- Reverse (manual):
--   ALTER TABLE payment_events
--     DROP COLUMN applied_at,
--     DROP COLUMN last_error,
--     DROP COLUMN process_status;

-- Ensure base table exists (no-op if already created by 006 or campuslink.sql).
CREATE TABLE IF NOT EXISTS payment_events (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_id     VARCHAR(120) NOT NULL,
    event_type   VARCHAR(80)  NOT NULL,
    order_id     INT UNSIGNED NULL,
    payload_hash CHAR(64)     NOT NULL,
    created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_payment_events_event (event_id),
    KEY idx_payment_events_order (order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @db := DATABASE();

SET @had_process := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'payment_events' AND COLUMN_NAME = 'process_status'
);

SET @sql := IF(
    @had_process = 0,
    'ALTER TABLE payment_events ADD COLUMN process_status ENUM(''claimed'',''applied'',''failed'') NOT NULL DEFAULT ''claimed'' AFTER payload_hash',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @had_err := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'payment_events' AND COLUMN_NAME = 'last_error'
);
SET @sql := IF(
    @had_err = 0,
    'ALTER TABLE payment_events ADD COLUMN last_error VARCHAR(255) NULL AFTER process_status',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @had_applied := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'payment_events' AND COLUMN_NAME = 'applied_at'
);
SET @sql := IF(
    @had_applied = 0,
    'ALTER TABLE payment_events ADD COLUMN applied_at TIMESTAMP NULL DEFAULT NULL AFTER last_error',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- One-time only: when process_status was just introduced, existing event rows were
-- previously treated as fully consumed by INSERT IGNORE. Mark them applied.
-- Re-runs must not touch live claimed/failed rows.
SET @sql := IF(
    @had_process = 0,
    'UPDATE payment_events SET process_status = ''applied'', applied_at = COALESCE(applied_at, created_at) WHERE process_status = ''claimed''',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
