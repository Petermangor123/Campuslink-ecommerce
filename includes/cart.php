<?php
// cart functions (price and stock come from the db)

function remember_cart_intent(int $productId, int $qty): void {
    $_SESSION['pending_cart'] = [
        'product_id' => $productId,
        'qty'        => max(1, $qty),
    ];
}

function take_pending_cart(): ?array {
    if (empty($_SESSION['pending_cart']) || !is_array($_SESSION['pending_cart'])) {
        return null;
    }
    $pending = $_SESSION['pending_cart'];
    unset($_SESSION['pending_cart']);
    return $pending;
}

function cart_quantity_total(mysqli $conn, int $buyerId): int {
    if ($buyerId < 1) {
        return 0;
    }
    $stmt = $conn->prepare('SELECT COALESCE(SUM(quantity),0) AS qty FROM cart WHERE buyer_id=?');
    $stmt->bind_param('i', $buyerId);
    $stmt->execute();
    $qty = (int)$stmt->get_result()->fetch_assoc()['qty'];
    $stmt->close();
    return $qty;
}

function add_to_cart(mysqli $conn, int $buyerId, int $productId, int $qty): array {
    if ($buyerId < 1 || $productId < 1 || $qty < 1) {
        return ['ok' => false, 'message' => 'Choose a valid quantity.'];
    }

    $stmt = $conn->prepare('SELECT id, seller_id, stock, is_active FROM products WHERE id=?');
    $stmt->bind_param('i', $productId);
    $stmt->execute();
    $product = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$product) {
        return ['ok' => false, 'message' => 'Product not found.'];
    }
    if ((int)($product['is_active'] ?? 1) !== 1) {
        return ['ok' => false, 'message' => 'This listing is no longer available.'];
    }
    if ((int)$product['seller_id'] === $buyerId) {
        return ['ok' => false, 'message' => 'You cannot add your own product to the cart.'];
    }

    $stock = (int)$product['stock'];
    if ($stock < 1) {
        return ['ok' => false, 'message' => 'This product is out of stock.'];
    }

    $stmt = $conn->prepare('SELECT quantity FROM cart WHERE buyer_id=? AND product_id=?');
    $stmt->bind_param('ii', $buyerId, $productId);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $newQty = $qty + (int)($existing['quantity'] ?? 0);
    if ($newQty > $stock) {
        return ['ok' => false, 'message' => 'The requested quantity exceeds available stock.'];
    }

    $stmt = $conn->prepare(
        'INSERT INTO cart (buyer_id, product_id, quantity) VALUES (?,?,?)
         ON DUPLICATE KEY UPDATE quantity=?'
    );
    $stmt->bind_param('iiii', $buyerId, $productId, $newQty, $newQty);
    $stmt->execute();
    $stmt->close();

    return ['ok' => true, 'message' => 'Product added to your cart.'];
}

function &guest_cart(): array {
    if (!isset($_SESSION['guest_cart']) || !is_array($_SESSION['guest_cart'])) {
        $_SESSION['guest_cart'] = [];
    }
    $clean = [];
    foreach ($_SESSION['guest_cart'] as $productId => $quantity) {
        $productId = (int)$productId;
        $quantity = (int)$quantity;
        if ($productId > 0 && $quantity > 0) {
            $clean[$productId] = $quantity;
        }
    }
    $_SESSION['guest_cart'] = $clean;
    return $_SESSION['guest_cart'];
}

function guest_cart_count(): int {
    $count = 0;
    foreach (guest_cart() as $quantity) {
        $count += (int)$quantity;
    }
    return $count;
}

function add_guest_item(mysqli $conn, int $productId, int $qty): array {
    if ($productId < 1 || $qty < 1) {
        return ['ok' => false, 'message' => 'Choose a valid quantity.'];
    }
    $stmt = $conn->prepare('SELECT id, stock, is_active FROM products WHERE id=?');
    $stmt->bind_param('i', $productId);
    $stmt->execute();
    $product = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$product) {
        return ['ok' => false, 'message' => 'Product not found.'];
    }
    if ((int)($product['is_active'] ?? 1) !== 1) {
        return ['ok' => false, 'message' => 'This listing is no longer available.'];
    }
    $stock = (int)$product['stock'];
    if ($stock < 1) {
        return ['ok' => false, 'message' => 'This product is out of stock.'];
    }
    $cart = &guest_cart();
    $newQty = $qty + (int)($cart[$productId] ?? 0);
    if ($newQty > $stock) {
        return ['ok' => false, 'message' => 'The requested quantity exceeds available stock.'];
    }
    $cart[$productId] = $newQty;
    return ['ok' => true, 'message' => 'Product added to your cart.'];
}

function cart_items(mysqli $conn): array {
    if (is_logged_in() && current_user_role() === 'buyer') {
        $uid = current_user_id();
        $stmt = $conn->prepare(
            'SELECT c.product_id, c.quantity, p.title, p.price, p.stock, p.image, u.name AS seller
             FROM cart c
             JOIN products p ON p.id = c.product_id
             LEFT JOIN users u ON u.id = p.seller_id
             WHERE c.buyer_id = ?'
        );
        $stmt->bind_param('i', $uid);
        $stmt->execute();
        $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $items;
    }
    if (is_logged_in()) {
        return [];
    }

    $cart = &guest_cart();
    if (!$cart) {
        return [];
    }
    $ids = array_keys($cart);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));
    $stmt = $conn->prepare("SELECT p.id, p.title, p.price, p.stock, p.image, u.name AS seller
         FROM products p LEFT JOIN users u ON u.id = p.seller_id WHERE p.id IN ($placeholders)");
    $stmt->bind_param($types, ...$ids);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $byId = [];
    foreach ($rows as $row) {
        $byId[(int)$row['id']] = $row;
    }
    $items = [];
    foreach ($cart as $productId => $quantity) {
        if (!isset($byId[$productId])) {
            unset($cart[$productId]);
            continue;
        }
        $product = $byId[$productId];
        if ((int)$product['stock'] < 1) {
            unset($cart[$productId]);
            continue;
        }
        $quantity = min((int)$quantity, (int)$product['stock']);
        $cart[$productId] = $quantity;
        $items[] = [
            'product_id' => $productId,
            'quantity' => $quantity,
            'title' => $product['title'],
            'price' => $product['price'],
            'stock' => $product['stock'],
            'image' => $product['image'],
            'seller' => $product['seller'] ?? '',
        ];
    }
    return $items;
}

function cart_update(mysqli $conn, array $quantities): void {
    if (is_logged_in() && current_user_role() === 'buyer') {
        $uid = current_user_id();
        foreach ($quantities as $productId => $quantity) {
            $productId = (int)$productId;
            $quantity = (int)$quantity;
            $stmt = $conn->prepare('SELECT stock FROM products WHERE id=?');
            $stmt->bind_param('i', $productId);
            $stmt->execute();
            $product = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($quantity < 1 || !$product || (int)$product['stock'] < 1) {
                $stmt = $conn->prepare('DELETE FROM cart WHERE buyer_id=? AND product_id=?');
                $stmt->bind_param('ii', $uid, $productId);
            } else {
                $quantity = min($quantity, (int)$product['stock']);
                $stmt = $conn->prepare('UPDATE cart SET quantity=? WHERE buyer_id=? AND product_id=?');
                $stmt->bind_param('iii', $quantity, $uid, $productId);
            }
            $stmt->execute();
            $stmt->close();
        }
        return;
    }
    if (is_logged_in()) {
        return;
    }
    $cart = &guest_cart();
    foreach ($quantities as $productId => $quantity) {
        $productId = (int)$productId;
        $quantity = (int)$quantity;
        if (!isset($cart[$productId])) {
            continue;
        }
        $stmt = $conn->prepare('SELECT stock FROM products WHERE id=?');
        $stmt->bind_param('i', $productId);
        $stmt->execute();
        $product = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($quantity < 1 || !$product || (int)$product['stock'] < 1) {
            unset($cart[$productId]);
            continue;
        }
        $cart[$productId] = min($quantity, (int)$product['stock']);
    }
}

function cart_remove(mysqli $conn, int $productId): void {
    if (is_logged_in() && current_user_role() === 'buyer') {
        $uid = current_user_id();
        $stmt = $conn->prepare('DELETE FROM cart WHERE buyer_id=? AND product_id=?');
        $stmt->bind_param('ii', $uid, $productId);
        $stmt->execute();
        $stmt->close();
        return;
    }
    if (!is_logged_in()) {
        $cart = &guest_cart();
        unset($cart[$productId]);
    }
}

function merge_guest_cart(mysqli $conn, int $buyerId): void {
    foreach (guest_cart() as $productId => $quantity) {
        add_to_cart($conn, $buyerId, (int)$productId, (int)$quantity);
    }
    $_SESSION['guest_cart'] = [];
}
