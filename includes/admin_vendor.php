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

/** Absolute path of a product image stored under assets/uploads, or null. */
function product_upload_file(string $image): ?string {
    $image = str_replace('\\', '/', trim($image));
    if (!preg_match('#^assets/uploads/[A-Za-z0-9._-]+$#', $image)) {
        return null;
    }
    $uploads = realpath(dirname(__DIR__) . '/assets/uploads');
    if ($uploads === false) {
        return null;
    }
    $candidate = dirname(__DIR__) . '/' . $image;
    if (!is_file($candidate)) {
        return null;
    }
    $real = realpath($candidate);
    $uploadsNorm = str_replace('\\', '/', $uploads);
    $realNorm = $real === false ? '' : str_replace('\\', '/', $real);
    if ($realNorm === '' || ($realNorm !== $uploadsNorm && !str_starts_with($realNorm, $uploadsNorm . '/'))) {
        return null;
    }
    return $real;
}

/** Remove a file only when no other product still points at it. */
function forget_unshared_product_image(mysqli $conn, string $image, int $exceptProductId): void {
    $path = product_upload_file($image);
    if ($path === null) {
        return;
    }
    $stmt = $conn->prepare('SELECT COUNT(*) AS c FROM products WHERE image=? AND id<>?');
    $stmt->bind_param('si', $image, $exceptProductId);
    $stmt->execute();
    $count = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
    $stmt->close();
    if ($count === 0 && is_file($path)) {
        @unlink($path);
    }
}

/**
 * Hide a vendor's product from the marketplace without touching orders.
 * @return array{ok:bool,message:string}
 */
function set_vendor_product_active(mysqli $conn, int $productId, int $sellerId, bool $active): array {
    $loaded = admin_load_vendor_product($conn, $productId, $sellerId);
    if (!$loaded['ok']) {
        return ['ok' => false, 'message' => $loaded['message'] ?? 'Product not found.'];
    }
    $flag = $active ? 1 : 0;
    $stmt = $conn->prepare('UPDATE products SET is_active=? WHERE id=? AND seller_id=?');
    $stmt->bind_param('iii', $flag, $productId, $sellerId);
    $stmt->execute();
    $stmt->close();
    return [
        'ok' => true,
        'message' => $active
            ? 'Product activated.'
            : 'Product deactivated. It is hidden from the marketplace, and existing orders are unchanged.',
    ];
}

/**
 * Permanently delete a product that has never been ordered.
 * Ordered products are deactivated instead. Cart rows for an unused product are removed first.
 *
 * @return array{ok:bool,action:string,message:string,product?:array}
 */
function remove_vendor_product(mysqli $conn, int $productId, int $sellerId): array {
    $loaded = admin_load_vendor_product($conn, $productId, $sellerId);
    if (!$loaded['ok']) {
        return ['ok' => false, 'action' => 'missing', 'message' => $loaded['message'] ?? 'Product not found.'];
    }
    $product = $loaded['product'];
    $archive = static function () use ($conn, $productId, $sellerId, $product): array {
        if ((int)($product['is_active'] ?? 1) !== 0) {
            set_vendor_product_active($conn, $productId, $sellerId, false);
        }
        return [
            'ok' => true,
            'action' => 'deactivated',
            'message' => 'This product is on an order, so it was deactivated instead of deleted. Order history, prices and payments stay as they were.',
            'product' => $product,
        ];
    };
    if (admin_product_has_order_lines($conn, $productId)) {
        return $archive();
    }

    $conn->begin_transaction();
    try {
        $cart = $conn->prepare('DELETE FROM cart WHERE product_id=?');
        $cart->bind_param('i', $productId);
        $cart->execute();
        $cartsCleared = $cart->affected_rows;
        $cart->close();
        $delete = $conn->prepare('DELETE FROM products WHERE id=? AND seller_id=?');
        $delete->bind_param('ii', $productId, $sellerId);
        $delete->execute();
        $removed = $delete->affected_rows === 1;
        $delete->close();
        if (!$removed) {
            throw new RuntimeException('Could not delete that product.');
        }
        $conn->commit();
    } catch (mysqli_sql_exception $e) {
        $conn->rollback();
        if ((int)$e->getCode() === 1451) {
            return $archive();
        }
        error_log('CampusLink product removal failed: ' . $e->getMessage());
        return ['ok' => false, 'action' => 'error', 'message' => 'That product could not be deleted. Please try again.'];
    } catch (Throwable $e) {
        $conn->rollback();
        error_log('CampusLink product removal failed: ' . $e->getMessage());
        return ['ok' => false, 'action' => 'error', 'message' => 'That product could not be deleted. Please try again.'];
    }

    forget_unshared_product_image($conn, (string)($product['image'] ?? ''), $productId);
    $message = 'Product deleted.';
    if ($cartsCleared > 0) {
        $message .= ' It was also removed from shopping carts that still held it.';
    }
    return ['ok' => true, 'action' => 'deleted', 'message' => $message, 'product' => $product];
}
