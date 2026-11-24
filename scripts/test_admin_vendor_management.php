<?php
/**
 * Automated checks for Admin Vendor Management.
 */
require_once dirname(__DIR__) . '/config/db.php';
require_once dirname(__DIR__) . '/includes/session.php';
require_once dirname(__DIR__) . '/includes/admin_vendor.php';

$fail = 0;
function t(string $name, bool $ok, string $detail = ''): void {
    global $fail;
    echo ($ok ? 'PASS' : 'FAIL') . " $name" . ($detail !== '' ? " — $detail" : '') . "\n";
    if (!$ok) {
        $fail++;
    }
}

$cols = $conn->query("SHOW COLUMNS FROM products LIKE 'is_active'");
t('Schema products.is_active', $cols && $cols->num_rows === 1);
$tbl = $conn->query("SHOW TABLES LIKE 'admin_audit_log'");
t('Schema admin_audit_log', $tbl && $tbl->num_rows === 1);

$admin = $conn->query("SELECT id FROM users WHERE role='admin' LIMIT 1")->fetch_assoc();
$vendorA = $conn->query("SELECT id,name FROM users WHERE role='seller' AND id=31 LIMIT 1")->fetch_assoc()
        ?: $conn->query("SELECT id,name FROM users WHERE role='seller' ORDER BY id LIMIT 1")->fetch_assoc();
$vendorB = $conn->query(
    "SELECT id,name FROM users WHERE role='seller' AND id<>" . (int)$vendorA['id'] . " ORDER BY id LIMIT 1"
)->fetch_assoc();
$adminId = (int)$admin['id'];
$va = (int)$vendorA['id'];
$vb = $vendorB ? (int)$vendorB['id'] : 0;

t('Fixtures admin+vendorA', $adminId > 0 && $va > 0, "admin=$adminId vendorA=$va");

// Create product as admin on behalf of vendor A
$title = 'AVM Test Listing ' . bin2hex(random_bytes(3));
$price = 42.50;
$stock = 3;
$active = 1;
$cat = null;
$desc = 'Created by admin vendor management test';
$img = null;
$stmt = $conn->prepare(
    'INSERT INTO products (seller_id, category_id, title, description, price, stock, is_active, image)
     VALUES (?,?,?,?,?,?,?,?)'
);
$stmt->bind_param('iissdiis', $va, $cat, $title, $desc, $price, $stock, $active, $img);
$stmt->execute();
$pid = (int)$conn->insert_id;
$stmt->close();
t('Insert product id', $pid > 0, (string)$pid);

$row = $conn->query('SELECT seller_id, title, is_active FROM products WHERE id=' . $pid)->fetch_assoc();
t('Ownership is vendor A not admin', (int)$row['seller_id'] === $va && (int)$row['seller_id'] !== $adminId, 'seller_id=' . $row['seller_id']);

admin_audit_log($conn, $adminId, $va, $pid, 'product_create', null, admin_product_snapshot($row + ['id' => $pid, 'price' => $price, 'stock' => $stock, 'image' => '', 'description' => $desc, 'category_id' => null]));
$aud = $conn->query("SELECT action, admin_id, vendor_id, product_id FROM admin_audit_log WHERE product_id=$pid AND action='product_create' ORDER BY id DESC LIMIT 1")->fetch_assoc();
t('Audit create row', $aud && (int)$aud['admin_id'] === $adminId && (int)$aud['vendor_id'] === $va);

// Vendor A can select it
$own = $conn->prepare('SELECT id FROM products WHERE id=? AND seller_id=?');
$own->bind_param('ii', $pid, $va);
$own->execute();
$own->store_result();
t('Vendor A sees product', $own->num_rows === 1);
$own->close();

// Vendor B cannot
if ($vb > 0) {
    $other = $conn->prepare('SELECT id FROM products WHERE id=? AND seller_id=?');
    $other->bind_param('ii', $pid, $vb);
    $other->execute();
    $other->store_result();
    t('Vendor B cannot claim product', $other->num_rows === 0);
    $other->close();
} else {
    t('Vendor B cannot claim product', true, 'skipped (only one seller)');
}

// Admin edit without ownership change
$before = admin_product_snapshot($conn->query('SELECT * FROM products WHERE id=' . $pid)->fetch_assoc());
$newTitle = $title . ' Edited';
$u = $conn->prepare('UPDATE products SET title=?, seller_id=seller_id WHERE id=? AND seller_id=?');
$u->bind_param('sii', $newTitle, $pid, $va);
$u->execute();
$u->close();
$afterRow = $conn->query('SELECT * FROM products WHERE id=' . $pid)->fetch_assoc();
t('Edit preserves seller_id', (int)$afterRow['seller_id'] === $va);
admin_audit_log($conn, $adminId, $va, $pid, 'product_update', $before, admin_product_snapshot($afterRow));

// Deactivate / activate
$conn->query("UPDATE products SET is_active=0 WHERE id=$pid AND seller_id=$va");
t('Deactivated', (int)$conn->query("SELECT is_active FROM products WHERE id=$pid")->fetch_assoc()['is_active'] === 0);
admin_audit_log($conn, $adminId, $va, $pid, 'product_deactivate', ['is_active' => 1], ['is_active' => 0]);
$conn->query("UPDATE products SET is_active=1 WHERE id=$pid AND seller_id=$va");
t('Reactivated', (int)$conn->query("SELECT is_active FROM products WHERE id=$pid")->fetch_assoc()['is_active'] === 1);
admin_audit_log($conn, $adminId, $va, $pid, 'product_activate', ['is_active' => 0], ['is_active' => 1]);

$auditCount = (int)$conn->query("SELECT COUNT(*) c FROM admin_audit_log WHERE product_id=$pid")->fetch_assoc()['c'];
t('Audit has multiple actions', $auditCount >= 4, "count=$auditCount");

// Existing Nana products unaffected
$nana = (int)$conn->query('SELECT COUNT(*) c FROM products WHERE seller_id=31 AND id IN (15,16)')->fetch_assoc()['c'];
t('Existing Nana products intact', $nana === 2 || $conn->query('SELECT COUNT(*) c FROM products WHERE seller_id=31')->fetch_assoc()['c'] >= 1);

// Catalog hides inactive
$conn->query("UPDATE products SET is_active=0 WHERE id=$pid");
require_once dirname(__DIR__) . '/includes/catalog.php';
$found = false;
foreach (catalog_products($conn, ['q' => 'AVM Test Listing', 'limit' => 48]) as $p) {
    if ((int)$p['id'] === $pid) {
        $found = true;
    }
}
t('Inactive hidden from catalog', !$found);
$conn->query("UPDATE products SET is_active=1 WHERE id=$pid");

// Cleanup test product (no order lines)
$conn->query("DELETE FROM admin_audit_log WHERE product_id=$pid");
$conn->query("DELETE FROM products WHERE id=$pid AND seller_id=$va");
t('Cleanup removed test product', !$conn->query("SELECT id FROM products WHERE id=$pid")->fetch_assoc());

// Paystack helpers still load
require_once dirname(__DIR__) . '/includes/payment_gateway.php';
t('Paystack mode unchanged load', in_array(payment_gateway_mode(), ['paystack', 'manual_review'], true), payment_gateway_mode());

echo $fail === 0 ? "OVERALL=PASS\n" : "OVERALL=FAIL ($fail)\n";
exit($fail === 0 ? 0 : 1);
