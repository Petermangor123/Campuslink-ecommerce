-- CampusLink: add TOTP columns expected by includes/mfa.php to an older users table.
-- Intended for databases created before TOTP columns existed in campuslink.sql.
-- Re-importing campuslink.sql alone will NOT add these columns (CREATE TABLE IF NOT EXISTS).
-- Apply once with: mysql -u root campuslink < database/migrations/001_add_users_totp_columns.sql
-- Safe: only adds missing columns with NULL/0 defaults; does not change passwords, roles, or disable MFA.

ALTER TABLE users
    ADD COLUMN totp_secret VARCHAR(64) DEFAULT NULL AFTER phone,
    ADD COLUMN totp_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER totp_secret,
    ADD COLUMN totp_backup_codes TEXT DEFAULT NULL AFTER totp_enabled,
    ADD COLUMN totp_last_step BIGINT UNSIGNED DEFAULT NULL AFTER totp_backup_codes;
