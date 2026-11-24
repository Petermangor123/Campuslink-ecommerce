<?php
require_once dirname(__DIR__) . '/config/db.php';

$c = $conn->query("SHOW COLUMNS FROM products LIKE 'is_active'");
if ($c && $c->num_rows > 0) {
    echo "is_active already present\n";
} else {
    $conn->query('ALTER TABLE products ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER stock');
    echo "added products.is_active\n";
}

$t = $conn->query("SHOW TABLES LIKE 'admin_audit_log'");
if ($t && $t->num_rows > 0) {
    echo "admin_audit_log already present\n";
} else {
    $conn->query(
        "CREATE TABLE admin_audit_log (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            admin_id INT UNSIGNED NOT NULL,
            vendor_id INT UNSIGNED NULL,
            product_id INT UNSIGNED NULL,
            action VARCHAR(64) NOT NULL,
            before_json JSON NULL,
            after_json JSON NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_admin_audit_admin (admin_id),
            KEY idx_admin_audit_vendor (vendor_id),
            KEY idx_admin_audit_product (product_id),
            KEY idx_admin_audit_created (created_at),
            CONSTRAINT fk_admin_audit_admin
                FOREIGN KEY (admin_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    echo "created admin_audit_log\n";
}

echo "DONE\n";
