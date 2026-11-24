-- Drop TOTP columns after 2FA removal. Safe when no accounts have totp_enabled=1.
-- Reverse: re-apply database/migrations/001_add_users_totp_columns.sql

ALTER TABLE users
    DROP COLUMN totp_secret,
    DROP COLUMN totp_enabled,
    DROP COLUMN totp_backup_codes,
    DROP COLUMN totp_last_step;
