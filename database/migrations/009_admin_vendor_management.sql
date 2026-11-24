-- CampusLink migration 009: Admin Vendor Management
-- - products.is_active for soft deactivate/reactivate
-- - admin_audit_log for administrative product actions
-- Reverse (destructive):
--   DROP TABLE IF EXISTS admin_audit_log;
--   ALTER TABLE products DROP COLUMN is_active;

ALTER TABLE products
    ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER stock;

CREATE TABLE IF NOT EXISTS admin_audit_log (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    admin_id      INT UNSIGNED     NOT NULL,
    vendor_id     INT UNSIGNED     NULL,
    product_id    INT UNSIGNED     NULL,
    action        VARCHAR(64)      NOT NULL,
    before_json   JSON             NULL,
    after_json    JSON             NULL,
    created_at    TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_admin_audit_admin (admin_id),
    KEY idx_admin_audit_vendor (vendor_id),
    KEY idx_admin_audit_product (product_id),
    KEY idx_admin_audit_created (created_at),
    CONSTRAINT fk_admin_audit_admin
        FOREIGN KEY (admin_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
