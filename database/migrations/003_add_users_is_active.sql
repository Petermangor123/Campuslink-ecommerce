-- Account activation flag for admin user management.
-- Reverse: ALTER TABLE users DROP COLUMN is_active;

ALTER TABLE users
    ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER role;
