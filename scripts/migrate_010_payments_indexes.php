<?php
// Add payment indexes and the payment-operations schema when missing.
// Does not change payment amounts or statuses.
require_once dirname(__DIR__) . '/config/db.php';

$col = $conn->query("SHOW COLUMNS FROM payments LIKE 'updated_at'");
if ($col && $col->num_rows > 0) {
    echo "updated_at already present\n";
} else {
    $conn->query(
        'ALTER TABLE payments ADD COLUMN updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at'
    );
    echo "added payments.updated_at\n";
}

$dup = $conn->query(
    "SELECT gateway_reference FROM payments
     WHERE gateway_reference IS NOT NULL AND gateway_reference <> ''
     GROUP BY gateway_reference HAVING COUNT(*) > 1 LIMIT 1"
);
if ($dup && $dup->fetch_assoc()) {
    echo "SKIP unique index: duplicate gateway_reference values exist\n";
}

$idx = $conn->query("SHOW INDEX FROM payments WHERE Key_name = 'uq_payments_gateway_ref'");
if ($idx && $idx->num_rows > 0) {
    echo "uq_payments_gateway_ref already present\n";
} else {
    $conn->query('ALTER TABLE payments ADD UNIQUE KEY uq_payments_gateway_ref (gateway_reference)');
    echo "added uq_payments_gateway_ref\n";
}


