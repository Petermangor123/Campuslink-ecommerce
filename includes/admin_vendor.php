<?php
/**
 * Admin Vendor Management helpers — ownership validation and audit logging.
 * Does not touch payments or Paystack.
 */
require_once __DIR__ . '/session.php';

/**
 * Load an active seller account or null.
 * @return array<string,mixed>|null
 */
function admin_load_vendor(mysqli $conn, int $vendorId): ?array {
    if ($vendorId < 1) {
        return null;
    }
    $stmt = $conn->prepare(
        "SELECT id, name, email, phone, is_active, vendor_status, created_at
         FROM users WHERE id=? AND role='seller' LIMIT 1"
    );
    $stmt->bind_param('i', $vendorId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $row;
}

/** @return array<string,mixed> */
function admin_require_vendor(mysqli $conn, int $vendorId): array {
    $vendor = admin_load_vendor($conn, $vendorId);
    if (!$vendor) {
        set_flash('error', 'Select a valid vendor account.');
        redirect(APP_BASE_URL . '/admin/vendors.php');
    }
    return $vendor;
}

/**
 * Snapshot of product fields relevant to audit diffs.
 * @param array<string,mixed> $product
 * @return array<string,mixed>
 */
function admin_product_snapshot(array $product): array {
    return [
        'id' => isset($product['id']) ? (int)$product['id'] : null,
        'seller_id' => isset($product['seller_id']) ? (int)$product['seller_id'] : null,
        'category_id' => isset($product['category_id']) && $product['category_id'] !== '' && $product['category_id'] !== null
            ? (int)$product['category_id'] : null,
        'title' => (string)($product['title'] ?? ''),
        'description' => (string)($product['description'] ?? ''),
        'price' => isset($product['price']) ? round((float)$product['price'], 2) : null,
        'stock' => isset($product['stock']) ? (int)$product['stock'] : null,
        'is_active' => isset($product['is_active']) ? (int)$product['is_active'] : 1,
        'image' => (string)($product['image'] ?? ''),
    ];
}

/**
 * Append an admin audit row. Vendors have no write access to this table.
 */
function admin_audit_log(
    mysqli $conn,
    int $adminId,
    ?int $vendorId,
    ?int $productId,
    string $action,
    ?array $before = null,
    ?array $after = null
): void {
    $action = substr(preg_replace('/[^a-z0-9_]/', '', strtolower($action)) ?? '', 0, 64);
    if ($action === '' || $adminId < 1) {
        return;
    }
    $beforeJson = $before !== null ? json_encode($before, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
    $afterJson = $after !== null ? json_encode($after, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
    $vidInt = ($vendorId !== null && $vendorId > 0) ? $vendorId : 0;
    $pidInt = ($productId !== null && $productId > 0) ? $productId : 0;
    $stmt = $conn->prepare(
        'INSERT INTO admin_audit_log (admin_id, vendor_id, product_id, action, before_json, after_json)
         VALUES (?, NULLIF(?, 0), NULLIF(?, 0), ?, ?, ?)'
    );
    $stmt->bind_param('iiisss', $adminId, $vidInt, $pidInt, $action, $beforeJson, $afterJson);
    $stmt->execute();
    $stmt->close();
}

/**
 * @return array{ok:bool,message?:string,product?:array}
 */
function admin_load_vendor_product(mysqli $conn, int $productId, int $vendorId): array {
    $stmt = $conn->prepare('SELECT * FROM products WHERE id=? AND seller_id=? LIMIT 1');
    $stmt->bind_param('ii', $productId, $vendorId);
    $stmt->execute();
    $product = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$product) {
        return ['ok' => false, 'message' => 'That product does not belong to this vendor.'];
    }
    return ['ok' => true, 'product' => $product];
}

function admin_product_has_order_lines(mysqli $conn, int $productId): bool {
    $stmt = $conn->prepare('SELECT 1 FROM order_items WHERE product_id=? LIMIT 1');
    $stmt->bind_param('i', $productId);
    $stmt->execute();
    $stmt->store_result();
    $has = $stmt->num_rows > 0;
    $stmt->close();
    return $has;
}
