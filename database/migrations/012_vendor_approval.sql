-- CampusLink migration 012: administrator approval for vendor accounts.
-- Additive and safe to re-run on MySQL 8 and MariaDB.
-- Existing seller accounts become pending and must be approved before they
-- can use seller routes or shared fulfilment mutations.

SET @campuslink_012_has_status = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'vendor_status'
);

SET @campuslink_012_ddl = IF(
    @campuslink_012_has_status = 0,
    'ALTER TABLE `users` ADD COLUMN `vendor_status` ENUM(''pending'',''approved'',''rejected'',''suspended'') NOT NULL DEFAULT ''pending'' AFTER `is_active`',
    'DO 0'
);
PREPARE campuslink_012_stmt FROM @campuslink_012_ddl;
EXECUTE campuslink_012_stmt;
DEALLOCATE PREPARE campuslink_012_stmt;

SET @campuslink_012_ddl = IF(
    @campuslink_012_has_status = 0,
    'UPDATE `users` SET `vendor_status` = ''pending'' WHERE `role` = ''seller''',
    'DO 0'
);
PREPARE campuslink_012_stmt FROM @campuslink_012_ddl;
EXECUTE campuslink_012_stmt;
DEALLOCATE PREPARE campuslink_012_stmt;

SET @campuslink_012_has_index = (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND INDEX_NAME = 'idx_users_vendor_status'
);
SET @campuslink_012_ddl = IF(
    @campuslink_012_has_index = 0,
    'ALTER TABLE `users` ADD KEY `idx_users_vendor_status` (`vendor_status`)',
    'DO 0'
);
PREPARE campuslink_012_stmt FROM @campuslink_012_ddl;
EXECUTE campuslink_012_stmt;
DEALLOCATE PREPARE campuslink_012_stmt;
