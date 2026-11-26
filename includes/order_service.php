<?php
/**
 * Shared order placement for registered buyers and guests.
 * Totals are calculated from locked product rows. Payment stays pending
 * until Paystack verifies it, or an administrator confirms a campus payment.
 */
require_once __DIR__ . '/cart.php';
require_once __DIR__ . '/currency.php';

function checkout_intent(): array {
    $intent = $_SESSION['checkout_intent'] ?? null;
    if (!is_array($intent) || empty($intent['key']) || empty($intent['token']) || !empty($intent['used'])) {
        $intent = [
            'key' => bin2hex(random_bytes(32)),
            'token' => bin2hex(random_bytes(32)),
            'used' => false,
        ];
        $_SESSION['checkout_intent'] = $intent;
    }
    return $intent;
}

function remember_checkout_receipt(string $checkoutKey, string $reference, string $token, bool $emailSent): void {
    if (!isset($_SESSION['checkout_receipts']) || !is_array($_SESSION['checkout_receipts'])) {
        $_SESSION['checkout_receipts'] = [];
    }
    $_SESSION['checkout_receipts'][$checkoutKey] = [
        'reference' => $reference,
        'token' => $token,
        'email_sent' => $emailSent,
    ];
    if (isset($_SESSION['checkout_intent']) && hash_equals((string)$_SESSION['checkout_intent']['key'], $checkoutKey)) {
        $_SESSION['checkout_intent']['used'] = true;
    }
}

function receipt_for_checkout_key(string $checkoutKey): ?array {
    $receipt = $_SESSION['checkout_receipts'][$checkoutKey] ?? null;
    return is_array($receipt) ? $receipt : null;
}

function validate_customer(array $input, bool $requireAddress): array {
    $name = trim((string)($input['name'] ?? ''));
    $email = strtolower(trim((string)($input['email'] ?? '')));
    $phone = trim((string)($input['phone'] ?? ''));
    $address = trim((string)($input['address'] ?? ''));
    $notes = trim((string)($input['notes'] ?? ''));
    $errors = [];

    if (mb_strlen($name) < 2 || mb_strlen($name) > 100 || !preg_match("/^[\\p{L}][\\p{L} .'\\-]{1,99}$/u", $name)) {
        $errors[] = 'Enter your full name using letters, spaces, apostrophes, or hyphens.';
    }
    if (mb_strlen($email) > 150 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Enter a valid email address.';
    }
    $digits = preg_replace('/\D/', '', $phone);
    if (!preg_match('/^[0-9+().\\-\\s]{7,20}$/', $phone) || strlen((string)$digits) < 7 || strlen((string)$digits) > 15) {
        $errors[] = 'Enter a valid phone number.';
    }
    if ($requireAddress && (mb_strlen($address) < 8 || mb_strlen($address) > 500)) {
        $errors[] = 'Enter a delivery address between 8 and 500 characters.';
    } elseif ($address !== '' && mb_strlen($address) > 500) {
        $errors[] = 'Delivery address must be 500 characters or fewer.';
    }
    if (mb_strlen($notes) > 500) {
        $errors[] = 'Order notes must be 500 characters or fewer.';
    }

    return [
        'errors' => $errors,
        'customer' => [
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'address' => $address,
            'notes' => $notes,
        ],
    ];
}

function buyer_customer(mysqli $conn, int $buyerId, string $address, string $notes): array {
    $stmt = $conn->prepare('SELECT name, email, phone FROM users WHERE id=? AND role=\'buyer\'');
    $stmt->bind_param('i', $buyerId);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$user) {
        throw new RuntimeException('Your buyer account could not be found.');
    }
    $address = trim($address);
    $notes = trim($notes);
    if ($address !== '' && mb_strlen($address) > 500) {
        throw new RuntimeException('Delivery address must be 500 characters or fewer.');
    }
    if (mb_strlen($notes) > 500) {
        throw new RuntimeException('Order notes must be 500 characters or fewer.');
    }
    return [
        'name' => mb_substr($user['name'], 0, 100),
        'email' => mb_substr(strtolower($user['email']), 0, 150),
        'phone' => mb_substr(trim((string)($user['phone'] ?? '')), 0, 30),
        'address' => $address,
        'notes' => $notes,
    ];
}

function place_order(mysqli $conn, ?int $buyerId, array $customer, string $checkoutKey, string $trackingToken): array {
    $existing = order_by_checkout_key($conn, $checkoutKey);
    if ($existing) {
        return ['order' => $existing, 'duplicate' => true];
    }

    $conn->begin_transaction();
    try {
        $existing = order_by_checkout_key($conn, $checkoutKey, true);
        if ($existing) {
            $conn->commit();
            return ['order' => $existing, 'duplicate' => true];
        }

        $lines = locked_cart_lines($conn, $buyerId);
        if (!$lines) {
            throw new RuntimeException('Your cart is empty.');
        }

        $total = 0.0;
        foreach ($lines as $line) {
            if ((int)$line['quantity'] < 1 || (int)$line['quantity'] > (int)$line['stock']) {
                throw new RuntimeException('Insufficient stock for ' . $line['title'] . '. Please update your cart.');
            }
            $total += (float)$line['price'] * (int)$line['quantity'];
        }
        $total = round($total, 2);

        $reference = allocate_order_reference($conn);
        $tokenHash = hash('sha256', $trackingToken);
        $status = 'pending';
        $payment = 'pending';
        $name = $customer['name'];
        $email = $customer['email'];
        $phone = $customer['phone'] !== '' ? $customer['phone'] : null;
        $address = $customer['address'] !== '' ? $customer['address'] : null;
        $notes = $customer['notes'] !== '' ? $customer['notes'] : null;

        $buyerSql = $buyerId === null ? 'NULL' : (string)(int)$buyerId;
        $stmt = $conn->prepare(
            'INSERT INTO orders
             (buyer_id, customer_name, customer_email, customer_phone, delivery_address, order_notes,
              total, status, payment_status, reference_code, tracking_token_hash, checkout_key)
             VALUES (' . $buyerSql . ',?,?,?,?,?,?,?,?,?,?,?)'
        );
        $stmt->bind_param(
            'sssssdsssss',
            $name,
            $email,
            $phone,
            $address,
            $notes,
            $total,
            $status,
            $payment,
            $reference,
            $tokenHash,
            $checkoutKey
        );
        $stmt->execute();
        $orderId = (int)$conn->insert_id;
        $stmt->close();

        $itemStmt = $conn->prepare(
            'INSERT INTO order_items
             (order_id, product_id, seller_id, quantity, unit_price, fulfilment_status,
              commission_bps, commission_amount_minor, seller_net_amount_minor)
             VALUES (?,?,?,?,?,?,?,?,?)'
        );
        $sellerLookup = $conn->prepare('SELECT seller_id FROM products WHERE id=? LIMIT 1');
        $stockStmt = $conn->prepare('UPDATE products SET stock = stock - ? WHERE id = ? AND stock >= ?');
        $fulfilment = 'awaiting_payment';
        $commissionBps = marketplace_commission_bps();
        foreach ($lines as $line) {
            $productId = (int)$line['product_id'];
            $quantity = (int)$line['quantity'];
            $price = (float)$line['price'];
            $sellerLookup->bind_param('i', $productId);
            $sellerLookup->execute();
            $sellerRow = $sellerLookup->get_result()->fetch_assoc();
            $sellerId = $sellerRow ? (int)$sellerRow['seller_id'] : 0;
            if ($sellerId < 1) {
                throw new RuntimeException('A cart item is missing its seller.');
            }
            $grossMinor = currency_to_minor_units($price) * $quantity;
            $commissionMinor = intdiv($grossMinor * $commissionBps, 10000);
            $sellerNetMinor = $grossMinor - $commissionMinor;
            $itemStmt->bind_param('iiiidsiii', $orderId, $productId, $sellerId, $quantity, $price, $fulfilment, $commissionBps, $commissionMinor, $sellerNetMinor);
            $itemStmt->execute();
            $stockStmt->bind_param('iii', $quantity, $productId, $quantity);
            $stockStmt->execute();
            if ($stockStmt->affected_rows !== 1) {
                throw new RuntimeException('Stock changed during checkout. Please try again.');
            }
        }
        $itemStmt->close();
        $sellerLookup->close();
        $stockStmt->close();

        if (!function_exists('payment_gateway_mode')) {
            require_once __DIR__ . '/payment_gateway.php';
        }
        $provider = payment_gateway_mode() === 'paystack' ? 'paystack' : 'manual_review';
        // Marketplace money is GHS (display GHC). Never store "GHC" — Paystack needs ISO GHS.
        if (!function_exists('app_payment_currency')) {
            require_once __DIR__ . '/currency.php';
        }
        $currency = $provider === 'paystack'
            ? (function_exists('paystack_currency') ? paystack_currency() : app_payment_currency())
            : app_payment_currency();
        $pay = $conn->prepare('INSERT INTO payments (order_id, amount, status, provider, currency) VALUES (?,?,?,?,?)');
        $pay->bind_param('idsss', $orderId, $total, $payment, $provider, $currency);
        $pay->execute();
        $pay->close();

        if ($buyerId) {
            $clear = $conn->prepare('DELETE FROM cart WHERE buyer_id=?');
            $clear->bind_param('i', $buyerId);
            $clear->execute();
            $clear->close();
        }

        record_order_event(
            $conn,
            $orderId,
            null,
            $buyerId,
            $buyerId ? 'buyer' : 'guest',
            'order_placed',
            'Order ' . $reference . ' placed. Payment is pending campus confirmation.'
        );

        $conn->commit();
        $order = order_by_id($conn, $orderId);
        return ['order' => $order, 'duplicate' => false];
    } catch (Throwable $e) {
        $conn->rollback();
        if ($e instanceof mysqli_sql_exception && (int)$e->getCode() === 1062) {
            $existing = order_by_checkout_key($conn, $checkoutKey);
            if ($existing) {
                return ['order' => $existing, 'duplicate' => true];
            }
        }
        throw $e;
    }
}

function locked_cart_lines(mysqli $conn, ?int $buyerId): array {
    if ($buyerId) {
        $stmt = $conn->prepare(
            'SELECT c.product_id, c.quantity, p.title, p.price, p.stock
             FROM cart c JOIN products p ON p.id = c.product_id
             WHERE c.buyer_id = ? ORDER BY c.product_id FOR UPDATE'
        );
        $stmt->bind_param('i', $buyerId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }

    $cart = guest_cart();
    if (!$cart) {
        return [];
    }
    ksort($cart);
    $ids = array_keys($cart);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));
    $stmt = $conn->prepare(
        "SELECT id, title, price, stock FROM products WHERE id IN ($placeholders) ORDER BY id FOR UPDATE"
    );
    $stmt->bind_param($types, ...$ids);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $lines = [];
    foreach ($rows as $row) {
        $productId = (int)$row['id'];
        if (!isset($cart[$productId])) {
            continue;
        }
        $lines[] = [
            'product_id' => $productId,
            'quantity' => (int)$cart[$productId],
            'title' => $row['title'],
            'price' => $row['price'],
            'stock' => $row['stock'],
        ];
    }
    return $lines;
}

/**
 * Public order label: CL-001, CL-002, … (zero-padded to at least 3 digits).
 * Tracking tokens stay separate — this value is never used for authorization.
 */
function format_order_reference(int $sequence): string {
    if ($sequence < 1) {
        $sequence = 1;
    }
    return 'CL-' . str_pad((string)$sequence, 3, '0', STR_PAD_LEFT);
}

/**
 * Ensure the sequence row exists without CREATE TABLE (avoids implicit commits).
 * Seeds at 1, or past the highest existing CL-### reference if any.
 */
function bootstrap_order_ref_seq(mysqli $conn): void {
    static $ready = false;
    if ($ready) {
        return;
    }

    $existing = $conn->query('SELECT next_val FROM order_ref_seq WHERE id = 1 LIMIT 1');
    if ($existing === false) {
        throw new RuntimeException('Order reference sequence is missing. Apply database/migrations/004_order_ref_seq.sql.');
    }
    if ($existing->num_rows === 0) {
        $max = 0;
        $scan = $conn->query("SELECT reference_code FROM orders WHERE reference_code REGEXP '^CL-[0-9]+$'");
        if ($scan) {
            while ($row = $scan->fetch_assoc()) {
                $n = (int)substr((string)$row['reference_code'], 3);
                if ($n > $max) {
                    $max = $n;
                }
            }
        }
        $next = max(1, $max + 1);
        $ins = $conn->prepare('INSERT IGNORE INTO order_ref_seq (id, next_val) VALUES (1, ?)');
        $ins->bind_param('i', $next);
        $ins->execute();
        $ins->close();
    }
    $ready = true;
}

/**
 * Allocate the next public reference inside the caller's transaction.
 * Locks order_ref_seq so concurrent checkouts serialize safely.
 */
function allocate_order_reference(mysqli $conn): string {
    bootstrap_order_ref_seq($conn);

    for ($attempt = 0; $attempt < 8; $attempt++) {
        $locked = $conn->query('SELECT next_val FROM order_ref_seq WHERE id = 1 FOR UPDATE');
        if (!$locked || !($row = $locked->fetch_assoc())) {
            throw new RuntimeException('Could not lock the order reference sequence.');
        }

        $n = (int)$row['next_val'];
        if ($n < 1) {
            $n = 1;
        }
        $reference = format_order_reference($n);
        $advance = $n + 1;
        $upd = $conn->prepare('UPDATE order_ref_seq SET next_val = ? WHERE id = 1');
        $upd->bind_param('i', $advance);
        $upd->execute();
        $upd->close();

        $chk = $conn->prepare('SELECT id FROM orders WHERE reference_code = ? LIMIT 1');
        $chk->bind_param('s', $reference);
        $chk->execute();
        $taken = $chk->get_result()->fetch_assoc();
        $chk->close();
        if (!$taken) {
            return $reference;
        }
        // Rare: sequential form already used historically — skip and try again.
    }

    throw new RuntimeException('Could not allocate a unique order reference.');
}


function order_by_checkout_key(mysqli $conn, string $checkoutKey, bool $lock = false): ?array {
    $sql = 'SELECT * FROM orders WHERE checkout_key=? LIMIT 1' . ($lock ? ' FOR UPDATE' : '');
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('s', $checkoutKey);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function order_by_id(mysqli $conn, int $orderId): ?array {
    $stmt = $conn->prepare('SELECT * FROM orders WHERE id=?');
    $stmt->bind_param('i', $orderId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function order_by_reference(mysqli $conn, string $reference): ?array {
    $stmt = $conn->prepare('SELECT * FROM orders WHERE reference_code=? LIMIT 1');
    $stmt->bind_param('s', $reference);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function order_line_items(mysqli $conn, int $orderId): array {
    $stmt = $conn->prepare(
        'SELECT oi.id, oi.quantity, oi.unit_price, oi.product_id, oi.seller_id, oi.fulfilment_status,
                oi.rejection_reason, oi.fulfilled_at,
                oi.commission_bps, oi.commission_amount_minor, oi.seller_net_amount_minor,
                COALESCE(p.title, \'Removed product\') AS title,
                p.image, COALESCE(u.name, su.name, \'Seller\') AS seller
         FROM order_items oi
         LEFT JOIN products p ON p.id = oi.product_id
         LEFT JOIN users u ON u.id = p.seller_id
         LEFT JOIN users su ON su.id = oi.seller_id
         WHERE oi.order_id=?'
    );
    $stmt->bind_param('i', $orderId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

/** Allowed vendor fulfilment statuses for a line item. */
function fulfilment_statuses(): array {
    return [
        'awaiting_payment',
        'awaiting_acceptance',
        'accepted',
        'processing',
        'ready_for_pickup',
        'shipped',
        'delivered',
        'rejected',
        'cancelled',
    ];
}

function fulfilment_label(string $status): string {
    // Display labels only — DB / state-machine values stay unchanged.
    $map = [
        'awaiting_payment' => 'Awaiting Payment',
        'awaiting_acceptance' => 'Awaiting Acceptance',
        'accepted' => 'Accepted',
        'processing' => 'Processing',
        'ready_for_pickup' => 'Ready for Pickup',
        'shipped' => 'Dispatched',
        'delivered' => 'Delivered',
        'rejected' => 'Rejected',
        'cancelled' => 'Cancelled',
    ];
    $s = strtolower(trim($status));
    return $map[$s] ?? ucfirst(str_replace('_', ' ', $s));
}

function record_order_event(
    mysqli $conn,
    int $orderId,
    ?int $orderItemId,
    ?int $actorUserId,
    string $actorRole,
    string $eventType,
    string $message
): void {
    $itemSql = $orderItemId === null ? 'NULL' : (string)(int)$orderItemId;
    $actorSql = $actorUserId === null ? 'NULL' : (string)(int)$actorUserId;
    $stmt = $conn->prepare(
        'INSERT INTO order_events (order_id, order_item_id, actor_user_id, actor_role, event_type, message)
         VALUES (?,' . $itemSql . ',' . $actorSql . ',?,?,?)'
    );
    $stmt->bind_param('isss', $orderId, $actorRole, $eventType, $message);
    $stmt->execute();
    $stmt->close();
}

function order_events(mysqli $conn, int $orderId): array {
    $stmt = $conn->prepare(
        'SELECT id, order_item_id, actor_user_id, actor_role, event_type, message, created_at
         FROM order_events WHERE order_id=? ORDER BY created_at ASC, id ASC'
    );
    $stmt->bind_param('i', $orderId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function notify_user(mysqli $conn, int $userId, string $title, string $body, ?int $orderId = null): void {
    if ($userId < 1) {
        return;
    }
    if ($orderId === null) {
        $stmt = $conn->prepare('INSERT INTO notifications (user_id, order_id, title, body) VALUES (?,NULL,?,?)');
        $stmt->bind_param('iss', $userId, $title, $body);
    } else {
        $stmt = $conn->prepare('INSERT INTO notifications (user_id, order_id, title, body) VALUES (?,?,?,?)');
        $stmt->bind_param('iiss', $userId, $orderId, $title, $body);
    }
    $stmt->execute();
    $stmt->close();
}

function notify_order_sellers(mysqli $conn, int $orderId, string $title, string $body): void {
    $stmt = $conn->prepare(
        'SELECT DISTINCT seller_id FROM order_items WHERE order_id=? AND seller_id IS NOT NULL'
    );
    $stmt->bind_param('i', $orderId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    foreach ($rows as $row) {
        notify_user($conn, (int)$row['seller_id'], $title, $body, $orderId);
    }
}

function notify_order_buyer(mysqli $conn, array $order, string $title, string $body): void {
    if (!empty($order['buyer_id'])) {
        notify_user($conn, (int)$order['buyer_id'], $title, $body, (int)$order['id']);
    }
}

function send_status_email(array $order, string $subject, string $body): bool {
    $email = trim((string)($order['customer_email'] ?? ''));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    $headers = "From: CampusLink <noreply@localhost>\r\nContent-Type: text/plain; charset=UTF-8";
    $sent = @mail($email, $subject, $body, $headers);
    if (!$sent) {
        error_log('CampusLink status email failed for ' . ($order['reference_code'] ?? $order['id']));
    }
    return $sent;
}

/**
 * Open vendor acceptance after payment is verified (manual or gateway).
 */
function open_vendor_fulfilment(mysqli $conn, array $order): void {
    $orderId = (int)$order['id'];
    $upd = $conn->prepare(
        "UPDATE order_items SET fulfilment_status='awaiting_acceptance'
         WHERE order_id=? AND fulfilment_status='awaiting_payment'"
    );
    $upd->bind_param('i', $orderId);
    $upd->execute();
    $upd->close();
}

/**
 * Roll order.status from line-item fulfilment (payment must stay separate).
 */
function sync_order_fulfilment_rollup(mysqli $conn, int $orderId): void {
    $stmt = $conn->prepare('SELECT fulfilment_status FROM order_items WHERE order_id=?');
    $stmt->bind_param('i', $orderId);
    $stmt->execute();
    $statuses = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'fulfilment_status');
    $stmt->close();
    if (!$statuses) {
        return;
    }

    $order = order_by_id($conn, $orderId);
    if (!$order || $order['payment_status'] !== 'paid') {
        return;
    }
    if ($order['status'] === 'cancelled') {
        return;
    }

    $active = array_values(array_filter(
        $statuses,
        static fn(string $s): bool => !in_array($s, ['cancelled', 'rejected'], true)
    ));

    if (!$active) {
        set_order_state($conn, $orderId, 'cancelled', 'paid');
        return;
    }

    $allDelivered = count(array_filter($active, static fn($s) => $s === 'delivered')) === count($active);
    $anyShipped = (bool)array_filter($active, static fn($s) => in_array($s, ['shipped', 'delivered', 'ready_for_pickup'], true));
    $anyProgress = (bool)array_filter($active, static fn($s) => in_array($s, ['accepted', 'processing', 'ready_for_pickup', 'shipped', 'delivered'], true));

    if ($allDelivered) {
        set_order_state($conn, $orderId, 'delivered', 'paid');
    } elseif ($anyShipped) {
        set_order_state($conn, $orderId, 'shipped', 'paid');
    } elseif ($anyProgress || in_array('awaiting_acceptance', $active, true)) {
        set_order_state($conn, $orderId, 'paid', 'paid');
    }
}

/**
 * Vendor updates fulfilment for their own line item only.
 */
function apply_vendor_item_action(
    mysqli $conn,
    int $sellerId,
    int $itemId,
    string $action,
    string $reason = ''
): string {
    $transitions = [
        'accept' => ['from' => ['awaiting_acceptance'], 'to' => 'accepted', 'event' => 'vendor_accepted'],
        'reject' => ['from' => ['awaiting_acceptance'], 'to' => 'rejected', 'event' => 'vendor_rejected'],
        'processing' => ['from' => ['accepted'], 'to' => 'processing', 'event' => 'vendor_processing'],
        'ready_for_pickup' => ['from' => ['accepted', 'processing'], 'to' => 'ready_for_pickup', 'event' => 'ready_for_pickup'],
        'shipped' => ['from' => ['accepted', 'processing', 'ready_for_pickup'], 'to' => 'shipped', 'event' => 'vendor_shipped'],
    ];
    if (!isset($transitions[$action])) {
        return 'That fulfilment action is not allowed.';
    }
    if ($action === 'reject') {
        $reason = trim($reason);
        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 500) {
            return 'Provide a short rejection reason (3–500 characters).';
        }
    }

    $conn->begin_transaction();
    try {
        $sellerStmt = $conn->prepare(
            "SELECT vendor_status FROM users WHERE id=? AND role='seller' AND is_active=1 LIMIT 1 FOR UPDATE"
        );
        $sellerStmt->bind_param('i', $sellerId);
        $sellerStmt->execute();
        $seller = $sellerStmt->get_result()->fetch_assoc();
        $sellerStmt->close();
        if (!$seller || $seller['vendor_status'] !== 'approved') {
            throw new RuntimeException('Vendor operations require administrator approval.');
        }

        $stmt = $conn->prepare(
            'SELECT oi.*, o.reference_code, o.payment_status, o.status AS order_status, o.buyer_id, o.customer_email, o.customer_name, o.id AS oid
             FROM order_items oi
             INNER JOIN orders o ON o.id = oi.order_id
             WHERE oi.id=? FOR UPDATE'
        );
        $stmt->bind_param('i', $itemId);
        $stmt->execute();
        $item = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$item) {
            throw new RuntimeException('Order line not found.');
        }
        if ((int)$item['seller_id'] !== $sellerId) {
            throw new RuntimeException('You can only manage your own products.');
        }
        if ($item['payment_status'] !== 'paid') {
            throw new RuntimeException('Fulfilment starts only after payment is confirmed.');
        }
        if (!in_array($item['fulfilment_status'], $transitions[$action]['from'], true)) {
            throw new RuntimeException('This line cannot move to that status from ' . fulfilment_label((string)$item['fulfilment_status']) . '.');
        }

        $to = $transitions[$action]['to'];
        $reasonSql = $action === 'reject' ? $reason : '';
        $upd = $conn->prepare(
            'UPDATE order_items SET fulfilment_status=?, rejection_reason=NULLIF(?, \'\'), fulfilled_at = IF(? IN (\'shipped\',\'delivered\',\'rejected\'), NOW(), fulfilled_at)
             WHERE id=? AND seller_id=?'
        );
        $upd->bind_param('sssii', $to, $reasonSql, $to, $itemId, $sellerId);
        $upd->execute();
        if ($upd->affected_rows !== 1) {
            $upd->close();
            throw new RuntimeException('Could not update that line item.');
        }
        $upd->close();

        if ($action === 'reject') {
            $qty = (int)$item['quantity'];
            $pid = (int)$item['product_id'];
            $stock = $conn->prepare('UPDATE products SET stock = stock + ? WHERE id=?');
            $stock->bind_param('ii', $qty, $pid);
            $stock->execute();
            $stock->close();
        }

        $ref = (string)$item['reference_code'];
        $msg = match ($action) {
            'accept' => "Vendor accepted their items on {$ref}.",
            'reject' => "Vendor rejected their items on {$ref}: {$reason}",
            'processing' => "Vendor started processing items on {$ref}.",
            'ready_for_pickup' => "Items on {$ref} are ready for pickup.",
            'shipped' => "Vendor marked items on {$ref} as shipped.",
            default => "Fulfilment updated on {$ref}.",
        };
        record_order_event(
            $conn,
            (int)$item['oid'],
            $itemId,
            $sellerId,
            'seller',
            $transitions[$action]['event'],
            $msg
        );

        sync_order_fulfilment_rollup($conn, (int)$item['oid']);

        $order = order_by_id($conn, (int)$item['oid']);
        if ($order) {
            notify_order_buyer($conn, $order, 'Order update · ' . $ref, $msg);
            send_status_email($order, 'CampusLink order ' . $ref, "Hello {$order['customer_name']},\n\n{$msg}\n\nCampusLink");
        }

        $conn->commit();
        return '';
    } catch (Throwable $e) {
        $conn->rollback();
        if ($e instanceof RuntimeException) {
            return $e->getMessage();
        }
        error_log('CampusLink vendor fulfilment failed: ' . $e->getMessage());
        return 'Could not update fulfilment.';
    }
}

/**
 * Buyer confirms delivery for shipped / ready items they own.
 */
function apply_buyer_confirm_delivery(mysqli $conn, int $buyerId, int $orderId): string {
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare('SELECT * FROM orders WHERE id=? AND buyer_id=? FOR UPDATE');
        $stmt->bind_param('ii', $orderId, $buyerId);
        $stmt->execute();
        $order = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$order) {
            throw new RuntimeException('Order not found.');
        }
        if ($order['payment_status'] !== 'paid') {
            throw new RuntimeException('Payment is not confirmed for this order.');
        }

        $upd = $conn->prepare(
            "UPDATE order_items SET fulfilment_status='delivered', fulfilled_at=NOW()
             WHERE order_id=? AND fulfilment_status IN ('shipped','ready_for_pickup')"
        );
        $upd->bind_param('i', $orderId);
        $upd->execute();
        $changed = $upd->affected_rows;
        $upd->close();
        if ($changed < 1) {
            throw new RuntimeException('No items are ready for delivery confirmation yet.');
        }

        record_order_event(
            $conn,
            $orderId,
            null,
            $buyerId,
            'buyer',
            'buyer_confirmed_delivery',
            'Buyer confirmed receipt of order ' . $order['reference_code'] . '.'
        );
        sync_order_fulfilment_rollup($conn, $orderId);
        notify_order_sellers(
            $conn,
            $orderId,
            'Buyer confirmed delivery',
            'The buyer confirmed delivery for ' . $order['reference_code'] . '.'
        );
        $conn->commit();
        return '';
    } catch (Throwable $e) {
        $conn->rollback();
        return $e instanceof RuntimeException ? $e->getMessage() : 'Could not confirm delivery.';
    }
}

function seller_order_items(mysqli $conn, int $orderId, int $sellerId): array {
    $stmt = $conn->prepare(
        'SELECT oi.id, oi.quantity, oi.unit_price, oi.product_id, oi.seller_id, oi.fulfilment_status,
                oi.rejection_reason, COALESCE(p.title, \'Removed product\') AS title
         FROM order_items oi
         LEFT JOIN products p ON p.id = oi.product_id
         WHERE oi.order_id=? AND oi.seller_id=?
         ORDER BY oi.id'
    );
    $stmt->bind_param('ii', $orderId, $sellerId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function seller_can_access_order(mysqli $conn, int $orderId, int $sellerId): bool {
    $stmt = $conn->prepare(
        'SELECT 1 FROM order_items WHERE order_id=? AND seller_id=? LIMIT 1'
    );
    $stmt->bind_param('ii', $orderId, $sellerId);
    $stmt->execute();
    $ok = (bool)$stmt->get_result()->fetch_row();
    $stmt->close();
    return $ok;
}

function tracking_url(string $reference, string $token): string {
    $origin = getenv('CAMPUSLINK_PUBLIC_ORIGIN') ?: '';
    if ($origin === '') {
        // Never build an absolute link from an unvalidated Host header: an
        // attacker could otherwise poison confirmation e-mails with their own
        // domain. Fall back to a relative link when the host is not trusted.
        $host = (string)($_SERVER['HTTP_HOST'] ?? '');
        if (function_exists('sec_valid_host') && sec_valid_host($host)) {
            $origin = (sec_is_https() ? 'https://' : 'http://') . $host;
        } else {
            $origin = '';
        }
    }
    return rtrim($origin, '/') . APP_BASE_URL . '/public/track.php?ref=' . rawurlencode($reference) . '&t=' . rawurlencode($token);
}

function send_order_email(array $order, array $items, string $trackUrl): bool {
    $lines = [];
    foreach ($items as $item) {
        $subtotal = (float)$item['unit_price'] * (int)$item['quantity'];
        $lines[] = $item['title'] . ' × ' . $item['quantity'] . ' — ' . format_currency($subtotal);
    }
    $body = "Hello {$order['customer_name']},\n\n"
        . "Your CampusLink order {$order['reference_code']} has been received.\n"
        . "Payment status: pending. An order is marked paid only after payment is confirmed.\n\n"
        . implode("\n", $lines) . "\n\n"
        . 'Total: ' . format_currency((float)$order['total']) . "\n\n"
        . "Track this order (keep this link private):\n{$trackUrl}\n\n"
        . "CampusLink";
    $headers = "From: CampusLink <noreply@localhost>\r\nContent-Type: text/plain; charset=UTF-8";
    $sent = @mail($order['customer_email'], 'CampusLink order ' . $order['reference_code'], $body, $headers);
    if (!$sent) {
        error_log('CampusLink order email could not be sent for ' . $order['reference_code']);
    }
    return $sent;
}

function mark_confirmation_sent(mysqli $conn, int $orderId): void {
    $stmt = $conn->prepare('UPDATE orders SET confirmation_sent=1 WHERE id=?');
    $stmt->bind_param('i', $orderId);
    $stmt->execute();
    $stmt->close();
}

/**
 * Authorize order tracking / pay / cancel.
 * Allowed: valid private tracking token, logged-in buyer who owns the order,
 * or a session that was previously proven with the token (same browser).
 * Email + sequential CL-### alone is NOT sufficient.
 */
function order_access_granted(array $order, string $token = '', string $email = ''): bool {
    unset($email); // retained in signature for call-site compatibility; never used for auth
    if ($token !== '' && !empty($order['tracking_token_hash']) && hash_equals($order['tracking_token_hash'], hash('sha256', $token))) {
        return true;
    }
    if (
        function_exists('is_logged_in') && is_logged_in()
        && function_exists('current_user_role') && current_user_role() === 'buyer'
        && function_exists('current_user_id')
        && !empty($order['buyer_id'])
        && (int)$order['buyer_id'] === (int)current_user_id()
    ) {
        return true;
    }
    $reference = (string)$order['reference_code'];
    return !empty($_SESSION['tracked_orders'][$reference]);
}

function vendor_sales_summary(mysqli $conn, int $sellerId): array {
    return seller_financial_summary($conn, $sellerId);
}

function remember_tracked_order(string $reference): void {
    if (!isset($_SESSION['tracked_orders']) || !is_array($_SESSION['tracked_orders'])) {
        $_SESSION['tracked_orders'] = [];
    }
    $_SESSION['tracked_orders'][$reference] = true;
}

function tracking_rate_limited(): bool {
    $now = time();
    $bucket = $_SESSION['track_failures'] ?? ['start' => $now, 'count' => 0];
    if (!is_array($bucket) || ($now - (int)$bucket['start']) > 600) {
        $bucket = ['start' => $now, 'count' => 0];
    }
    $_SESSION['track_failures'] = $bucket;
    return (int)$bucket['count'] >= 8;
}

function record_tracking_failure(): void {
    $now = time();
    $bucket = $_SESSION['track_failures'] ?? ['start' => $now, 'count' => 0];
    if (!is_array($bucket) || ($now - (int)$bucket['start']) > 600) {
        $bucket = ['start' => $now, 'count' => 0];
    }
    $bucket['count'] = (int)$bucket['count'] + 1;
    $_SESSION['track_failures'] = $bucket;
}

function apply_admin_order_action(mysqli $conn, int $orderId, string $action): string {
    $allowed = ['paid', 'shipped', 'delivered', 'cancelled', 'failed'];
    if (!in_array($action, $allowed, true)) {
        return 'That status is not valid.';
    }

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare('SELECT * FROM orders WHERE id=? FOR UPDATE');
        $stmt->bind_param('i', $orderId);
        $stmt->execute();
        $order = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$order) {
            throw new RuntimeException('Order not found.');
        }
        $payStmt = $conn->prepare('SELECT * FROM payments WHERE order_id=? FOR UPDATE');
        $payStmt->bind_param('i', $orderId);
        $payStmt->execute();
        $payment = $payStmt->get_result()->fetch_assoc();
        $payStmt->close();
        if (!$payment) {
            throw new RuntimeException('This order has no payment record.');
        }

        $paymentStatus = $payment['status'];
        $ref = (string)($order['reference_code'] ?? ('#' . $orderId));
        $actorId = function_exists('current_user_id') ? current_user_id() : null;

        if ($action === 'paid') {
            // Paystack orders become paid only after the verify API succeeds.
            // Campus payments (manual_review) can still be confirmed here.
            $provider = (string)($payment['provider'] ?? 'manual_review');
            if ($provider === 'paystack' && $paymentStatus !== 'paid') {
                throw new RuntimeException('This order uses Paystack. It can be marked paid only after Paystack verifies the payment.');
            }
            if ($paymentStatus === 'pending') {
                set_payment_status($conn, $orderId, 'paid');
                $mark = $conn->prepare('UPDATE payments SET verified_at=NOW() WHERE order_id=?');
                $mark->bind_param('i', $orderId);
                $mark->execute();
                $mark->close();
                set_order_state($conn, $orderId, 'paid', 'paid');
                            post_payment_capture($conn, $orderId, $actorId, 'manual_review');
                open_vendor_fulfilment($conn, $order);
                record_order_event($conn, $orderId, null, $actorId, 'admin', 'payment_confirmed', 'Payment confirmed for ' . $ref . '. Vendors may now accept their items.');
                notify_order_sellers($conn, $orderId, 'New paid order · ' . $ref, 'Payment was confirmed. Please accept or reject your items on ' . $ref . '.');
                notify_order_buyer($conn, $order, 'Payment confirmed · ' . $ref, 'Payment for ' . $ref . ' was confirmed. Vendors will review your items next.');
                send_status_email($order, 'CampusLink payment confirmed · ' . $ref, "Hello {$order['customer_name']},\n\nPayment for {$ref} has been confirmed.\nVendors will now accept or prepare your items.\n\nCampusLink");
            } elseif ($paymentStatus === 'paid' && $order['status'] === 'pending') {
                set_order_state($conn, $orderId, 'paid', 'paid');
                open_vendor_fulfilment($conn, $order);
            }
        } elseif ($action === 'failed' || $action === 'cancelled') {
            if ($paymentStatus === 'paid') {
                throw new RuntimeException('A paid order must use the refund workflow before cancellation.');
            }
            if ($paymentStatus === 'pending') {
                set_payment_status($conn, $orderId, $action === 'failed' ? 'failed' : 'cancelled');
                restore_order_stock($conn, $order);
                set_order_state($conn, $orderId, 'cancelled', $action === 'failed' ? 'failed' : 'cancelled');
                $cancelItems = $conn->prepare(
                    "UPDATE order_items SET fulfilment_status='cancelled' WHERE order_id=? AND fulfilment_status NOT IN ('rejected','cancelled')"
                );
                $cancelItems->bind_param('i', $orderId);
                $cancelItems->execute();
                $cancelItems->close();
                record_order_event($conn, $orderId, null, $actorId, 'admin', $action === 'failed' ? 'payment_failed' : 'order_cancelled', 'Order ' . $ref . ' marked ' . $action . '.');
                notify_order_buyer($conn, $order, 'Order ' . $action . ' · ' . $ref, 'Your order ' . $ref . ' was marked ' . $action . '.');
                notify_order_sellers($conn, $orderId, 'Order ' . $action . ' · ' . $ref, 'Order ' . $ref . ' was ' . $action . '.');
            } elseif ($action === 'cancelled' && $order['status'] !== 'cancelled') {
                set_order_state($conn, $orderId, 'cancelled', $paymentStatus);
                $cancelItems = $conn->prepare(
                    "UPDATE order_items SET fulfilment_status='cancelled' WHERE order_id=? AND fulfilment_status NOT IN ('rejected','cancelled','delivered')"
                );
                $cancelItems->bind_param('i', $orderId);
                $cancelItems->execute();
                $cancelItems->close();
                record_order_event($conn, $orderId, null, $actorId, 'admin', 'order_cancelled', 'Order ' . $ref . ' cancelled by administrator.');
                notify_order_buyer($conn, $order, 'Order cancelled · ' . $ref, 'Your order ' . $ref . ' was cancelled.');
                notify_order_sellers($conn, $orderId, 'Order cancelled · ' . $ref, 'Order ' . $ref . ' was cancelled by an administrator.');
            }
        } elseif ($action === 'shipped' || $action === 'delivered') {
            if ($paymentStatus !== 'paid') {
                throw new RuntimeException('Confirm payment before fulfilling this order.');
            }
            // Admin override for exceptional cases — prefer vendor line updates normally.
            $toItem = $action === 'shipped' ? 'shipped' : 'delivered';
            $bulk = $conn->prepare(
                "UPDATE order_items SET fulfilment_status=?, fulfilled_at=NOW()
                 WHERE order_id=? AND fulfilment_status NOT IN ('cancelled','rejected')"
            );
            $bulk->bind_param('si', $toItem, $orderId);
            $bulk->execute();
            $bulk->close();
            set_order_state($conn, $orderId, $action, 'paid');
            record_order_event($conn, $orderId, null, $actorId, 'admin', 'admin_' . $action, 'Administrator marked ' . $ref . ' as ' . $action . '.');
            notify_order_buyer($conn, $order, 'Order ' . $action . ' · ' . $ref, 'Your order ' . $ref . ' is now ' . $action . '.');
        } elseif ($action === 'pending') {
            if ($paymentStatus === 'paid') {
                throw new RuntimeException('A confirmed payment cannot be moved back to pending.');
            }
        }

        $conn->commit();
        return '';
    } catch (Throwable $e) {
        $conn->rollback();
        if ($e instanceof RuntimeException) {
            return $e->getMessage();
        }
        error_log('CampusLink order update failed: ' . $e->getMessage());
        return 'Could not update that order.';
    }
}

function cancel_pending_order(mysqli $conn, array $order): string {
    return apply_admin_order_action($conn, (int)$order['id'], 'cancelled');
}

function set_payment_status(mysqli $conn, int $orderId, string $status): void {
    $stmt = $conn->prepare('UPDATE payments SET status=? WHERE order_id=? AND status=\'pending\'');
    $stmt->bind_param('si', $status, $orderId);
    $stmt->execute();
    if ($stmt->affected_rows !== 1) {
        $stmt->close();
        throw new RuntimeException('Payment was already updated.');
    }
    $stmt->close();
}

function set_order_state(mysqli $conn, int $orderId, string $status, string $paymentStatus): void {
    $stmt = $conn->prepare('UPDATE orders SET status=?, payment_status=? WHERE id=?');
    $stmt->bind_param('ssi', $status, $paymentStatus, $orderId);
    $stmt->execute();
    $stmt->close();
}

function restore_order_stock(mysqli $conn, array $order): void {
    if ((int)$order['stock_restored'] === 1) {
        return;
    }
    $restore = $conn->prepare(
        "UPDATE products p
         INNER JOIN order_items oi ON oi.product_id=p.id
         SET p.stock=p.stock+oi.quantity
         WHERE oi.order_id=? AND oi.fulfilment_status NOT IN ('rejected','cancelled')"
    );
    $restore->bind_param('i', $order['id']);
    $restore->execute();
    $restore->close();
    $flag = $conn->prepare('UPDATE orders SET stock_restored=1 WHERE id=?');
    $id = (int)$order['id'];
    $flag->bind_param('i', $id);
    $flag->execute();
    $flag->close();
}

if (!function_exists('paystack_api')) {
    require_once __DIR__ . '/payment_gateway.php';
}
if (!function_exists('marketplace_commission_bps')) {
    require_once __DIR__ . '/currency.php';
}

function money_to_minor($amount): int {
    return (int)round(((float)$amount) * 100);
}

function minor_to_money(int $minor): float {
    return round($minor / 100, 2);
}

function line_gross_minor(array $line): int {
    return money_to_minor((float)$line['unit_price']) * (int)$line['quantity'];
}

function financial_csv_cell($value): string {
    $value = (string)$value;
    if (preg_match('/^[=+\-@\t\r]/', $value)) {
        return "'" . $value;
    }
    return $value;
}

function stream_financial_csv(string $filename, array $headers, iterable $rows): void {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $filename) . '"');
    header('Cache-Control: no-store');
    $out = fopen('php://output', 'w');
    fputcsv($out, array_map('financial_csv_cell', $headers));
    foreach ($rows as $row) {
        fputcsv($out, array_map('financial_csv_cell', $row));
    }
    fclose($out);
    exit;
}

function financial_post_batch(
    mysqli $conn,
    string $sourceType,
    string $sourceId,
    string $eventKey,
    ?int $orderId,
    string $currency,
    string $description,
    ?int $actorId,
    array $entries
): int {
    $debits = 0;
    $credits = 0;
    foreach ($entries as $entry) {
        $debit = max(0, (int)($entry['debit_minor'] ?? 0));
        $credit = max(0, (int)($entry['credit_minor'] ?? 0));
        if (($debit > 0 && $credit > 0) || ($debit === 0 && $credit === 0)) {
            throw new RuntimeException('A financial entry must have exactly one side.');
        }
        $debits += $debit;
        $credits += $credit;
    }
    if (!$entries || $debits !== $credits || $debits < 1) {
        throw new RuntimeException('Financial batch is not balanced.');
    }
    $stmt = $conn->prepare(
        'INSERT IGNORE INTO financial_batches
         (source_type, source_id, event_key, order_id, currency, description, created_by_user_id)
         VALUES (?,?,?,?,?,?,?)'
    );
    $stmt->bind_param('sssissi', $sourceType, $sourceId, $eventKey, $orderId, $currency, $description, $actorId);
    $stmt->execute();
    $inserted = $stmt->affected_rows === 1;
    $batchId = (int)$conn->insert_id;
    $stmt->close();
    if (!$inserted) {
        $find = $conn->prepare('SELECT id FROM financial_batches WHERE event_key=?');
        $find->bind_param('s', $eventKey);
        $find->execute();
        $batchId = (int)($find->get_result()->fetch_assoc()['id'] ?? 0);
        $find->close();
        return $batchId;
    }
    $ins = $conn->prepare(
        'INSERT INTO financial_entries
         (batch_id, account, seller_id, order_item_id, debit_minor, credit_minor)
         VALUES (?,?,?,?,?,?)'
    );
    foreach ($entries as $entry) {
        $sellerId = isset($entry['seller_id']) ? (int)$entry['seller_id'] : null;
        $itemId = isset($entry['order_item_id']) ? (int)$entry['order_item_id'] : null;
        $debit = (int)($entry['debit_minor'] ?? 0);
        $credit = (int)($entry['credit_minor'] ?? 0);
        $ins->bind_param('isiiii', $batchId, $entry['account'], $sellerId, $itemId, $debit, $credit);
        $ins->execute();
    }
    $ins->close();
    return $batchId;
}

function post_payment_capture(mysqli $conn, int $orderId, ?int $actorId, string $provider): void {
    $pay = $conn->prepare('SELECT * FROM payments WHERE order_id=? FOR UPDATE');
    $pay->bind_param('i', $orderId);
    $pay->execute();
    $payment = $pay->get_result()->fetch_assoc();
    $pay->close();
    if (!$payment || $payment['status'] !== 'paid') {
        throw new RuntimeException('Cannot post a capture for an unpaid order.');
    }
    $items = $conn->query('SELECT * FROM order_items WHERE order_id=' . (int)$orderId . ' FOR UPDATE')->fetch_all(MYSQLI_ASSOC);
    $entries = [];
    $gross = 0;
    $commissions = 0;
    $sellerNet = 0;
    foreach ($items as $item) {
        $lineGross = line_gross_minor($item);
        $net = (int)$item['seller_net_amount_minor'];
        $commission = (int)$item['commission_amount_minor'];
        if ($net === 0 && $commission === 0 && $lineGross > 0) {
            $net = $lineGross;
            $upd = $conn->prepare('UPDATE order_items SET seller_net_amount_minor=? WHERE id=?');
            $upd->bind_param('ii', $net, $item['id']);
            $upd->execute();
            $upd->close();
        }
        $seller = (int)$item['seller_id'];
        $gross += $lineGross;
        $commissions += $commission;
        $sellerNet += $net;
        if ($net > 0) {
            $entries[] = ['account' => 'seller_payable', 'seller_id' => $seller, 'order_item_id' => (int)$item['id'], 'credit_minor' => $net];
        }
        if ($commission > 0) {
            $entries[] = ['account' => 'commission_income', 'seller_id' => $seller, 'order_item_id' => (int)$item['id'], 'credit_minor' => $commission];
        }
    }
    if ($gross !== $sellerNet + $commissions || $gross !== money_to_minor($payment['amount'])) {
        throw new RuntimeException('Payment capture does not reconcile to order lines.');
    }
    $entries[] = ['account' => $provider === 'paystack' ? 'gateway_receivable' : 'campus_cash', 'debit_minor' => $gross];
    financial_post_batch(
        $conn,
        'payment_capture',
        (string)$payment['id'],
        'capture:' . (int)$payment['id'],
        $orderId,
        (string)$payment['currency'],
        'Payment captured',
        $actorId,
        $entries
    );
}

function refund_active_statuses(): array {
    return ['pending', 'processing', 'needs_attention'];
}

function refresh_payment_refund_summary(mysqli $conn, int $paymentId): void {
    $pay = $conn->prepare('SELECT amount, currency FROM payments WHERE id=? FOR UPDATE');
    $pay->bind_param('i', $paymentId);
    $pay->execute();
    $payment = $pay->get_result()->fetch_assoc();
    $pay->close();
    if (!$payment) {
        throw new RuntimeException('Payment not found.');
    }
    $active = implode(',', array_map(static fn($s) => "'" . $s . "'", refund_active_statuses()));
    $stmt = $conn->prepare(
        "SELECT
            COALESCE(SUM(CASE WHEN status='processed' THEN amount_minor ELSE 0 END),0) processed,
            COALESCE(SUM(CASE WHEN status IN ($active) THEN amount_minor ELSE 0 END),0) active,
            COALESCE(SUM(CASE WHEN status='needs_attention' THEN 1 ELSE 0 END),0) attention
         FROM refunds WHERE payment_id=?"
    );
    $stmt->bind_param('i', $paymentId);
    $stmt->execute();
    $sums = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $processed = (int)$sums['processed'];
    $captured = money_to_minor($payment['amount']);
    $status = 'none';
    if ((int)$sums['attention'] > 0) {
        $status = 'needs_attention';
    } elseif ((int)$sums['active'] > 0) {
        $status = 'pending';
    } elseif ($processed > 0) {
        $status = $processed >= $captured ? 'full' : 'partial';
    }
    $upd = $conn->prepare('UPDATE payments SET refund_status=?, refunded_amount_minor=? WHERE id=?');
    $upd->bind_param('sii', $status, $processed, $paymentId);
    $upd->execute();
    $upd->close();
}

function allocate_refund_amounts(mysqli $conn, int $orderId, int $amountMinor): array {
    $items = $conn->query('SELECT * FROM order_items WHERE order_id=' . (int)$orderId . ' FOR UPDATE')->fetch_all(MYSQLI_ASSOC);
    if (!$items) {
        throw new RuntimeException('This order has no items to refund.');
    }
    $active = implode(',', array_map(static fn($s) => "'" . $s . "'", refund_active_statuses()));
    $allocations = [];
    foreach ($items as $item) {
        $stmt = $conn->prepare(
            "SELECT COALESCE(SUM(buyer_refund_minor),0) buyer,
                    COALESCE(SUM(commission_reversal_minor),0) commission
             FROM refund_allocations ra
             INNER JOIN refunds r ON r.id=ra.refund_id
             WHERE ra.order_item_id=? AND r.status IN ('processed',$active)"
        );
        $stmt->bind_param('i', $item['id']);
        $stmt->execute();
        $used = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $gross = line_gross_minor($item);
        $buyerRemaining = max(0, $gross - (int)$used['buyer']);
        $allocations[] = [
            'order_item_id' => (int)$item['id'],
            'seller_id' => (int)$item['seller_id'],
            'gross_minor' => $gross,
            'buyer_remaining_minor' => $buyerRemaining,
            'used_buyer_minor' => (int)$used['buyer'],
            'used_commission_minor' => (int)$used['commission'],
            'commission_total_minor' => (int)$item['commission_amount_minor'],
        ];
    }
    $remaining = array_sum(array_column($allocations, 'buyer_remaining_minor'));
    if ($amountMinor > $remaining) {
        throw new RuntimeException('Refund exceeds the remaining refundable amount.');
    }
    $grossRemaining = array_sum(array_column($allocations, 'buyer_remaining_minor'));
    $left = $amountMinor;
    foreach ($allocations as &$allocation) {
        $share = $grossRemaining > 0
            ? (int)floor($amountMinor * $allocation['buyer_remaining_minor'] / $grossRemaining)
            : 0;
        $share = min($share, $allocation['buyer_remaining_minor'], $left);
        $allocation['buyer_refund_minor'] = $share;
        $left -= $share;
    }
    unset($allocation);
    $index = 0;
    while ($left > 0 && $allocations) {
        $allocation = &$allocations[$index % count($allocations)];
        if ($allocation['buyer_refund_minor'] < $allocation['buyer_remaining_minor']) {
            $allocation['buyer_refund_minor']++;
            $left--;
        }
        $index++;
        if ($index > count($allocations) * 3 + $left) {
            throw new RuntimeException('Could not allocate the refund exactly.');
        }
    }
    unset($allocation);
    foreach ($allocations as &$allocation) {
        $cumulativeBuyer = $allocation['used_buyer_minor'] + $allocation['buyer_refund_minor'];
        $targetCommission = $allocation['gross_minor'] > 0
            ? (int)floor($cumulativeBuyer * $allocation['commission_total_minor'] / $allocation['gross_minor'])
            : 0;
        $commission = max(0, $targetCommission - $allocation['used_commission_minor']);
        $commission = min($commission, $allocation['buyer_refund_minor']);
        $allocation['commission_reversal_minor'] = $commission;
        $allocation['seller_net_reversal_minor'] = $allocation['buyer_refund_minor'] - $commission;
    }
    unset($allocation);
    return $allocations;
}

function request_refund(
    mysqli $conn,
    int $orderId,
    int $amountMinor,
    string $reason,
    ?int $actorId,
    string $idempotencyKey,
    string $provider = ''
): array {
    if ($amountMinor < 1 || trim($reason) === '' || mb_strlen($reason) > 500) {
        throw new RuntimeException('Enter a refund amount and a reason of 1–500 characters.');
    }
    if (!preg_match('/^[A-Za-z0-9_.:-]{8,100}$/', $idempotencyKey)) {
        throw new RuntimeException('Invalid refund idempotency key.');
    }
    $conn->begin_transaction();
    try {
        $order = $conn->query('SELECT * FROM orders WHERE id=' . (int)$orderId . ' FOR UPDATE')->fetch_assoc();
        if (!$order) {
            throw new RuntimeException('Order not found.');
        }
        $payment = $conn->query('SELECT * FROM payments WHERE order_id=' . (int)$orderId . ' FOR UPDATE')->fetch_assoc();
        if (!$payment || $payment['status'] !== 'paid') {
            throw new RuntimeException('Only captured payments can be refunded.');
        }
        $existing = $conn->prepare('SELECT * FROM refunds WHERE idempotency_key=?');
        $existing->bind_param('s', $idempotencyKey);
        $existing->execute();
        $refund = $existing->get_result()->fetch_assoc();
        $existing->close();
        if ($refund) {
            $conn->commit();
            return $refund;
        }
        $allocations = allocate_refund_amounts($conn, $orderId, $amountMinor);
        $provider = $provider !== '' ? $provider : (string)$payment['provider'];
        $status = $provider === 'paystack' ? 'pending' : 'processing';
        $stmt = $conn->prepare(
            "INSERT INTO refunds
             (order_id,payment_id,amount_minor,currency,status,provider,reason,idempotency_key,created_by)
             VALUES (?,?,?,?,?,?,?,?,?)"
        );
        $stmt->bind_param('iiisssssi', $orderId, $payment['id'], $amountMinor, $payment['currency'], $status, $provider, $reason, $idempotencyKey, $actorId);
        $stmt->execute();
        $refundId = (int)$conn->insert_id;
        $stmt->close();
        $alloc = $conn->prepare(
            'INSERT INTO refund_allocations
             (refund_id,order_item_id,buyer_refund_minor,commission_reversal_minor,seller_net_reversal_minor)
             VALUES (?,?,?,?,?)'
        );
        foreach ($allocations as $allocation) {
            $alloc->bind_param(
                'iiiii',
                $refundId,
                $allocation['order_item_id'],
                $allocation['buyer_refund_minor'],
                $allocation['commission_reversal_minor'],
                $allocation['seller_net_reversal_minor']
            );
            $alloc->execute();
        }
        $alloc->close();
        refresh_payment_refund_summary($conn, (int)$payment['id']);
        record_order_event($conn, $orderId, null, $actorId, $actorId ? 'admin' : 'system', 'refund_requested', 'Refund requested: ' . format_currency(minor_to_money($amountMinor), $payment['currency']) . '.');
        notify_order_buyer($conn, $order, 'Refund requested · ' . $order['reference_code'], 'A refund of ' . format_currency(minor_to_money($amountMinor), $payment['currency']) . ' is being processed.');
        $conn->commit();
        return ['id' => $refundId, 'order_id' => $orderId, 'payment_id' => (int)$payment['id'], 'amount_minor' => $amountMinor, 'currency' => $payment['currency'], 'provider' => $provider, 'status' => $status];
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}

function post_refund_processed(mysqli $conn, int $refundId, ?int $actorId, string $externalReference): void {
    $refund = $conn->query('SELECT * FROM refunds WHERE id=' . (int)$refundId . ' FOR UPDATE')->fetch_assoc();
    if (!$refund) {
        throw new RuntimeException('Refund not found.');
    }
    if ($refund['status'] === 'processed') {
        return;
    }
    $allocations = $conn->prepare(
        'SELECT * FROM refund_allocations WHERE refund_id=? FOR UPDATE'
    );
    $allocations->bind_param('i', $refundId);
    $allocations->execute();
    $rows = $allocations->get_result()->fetch_all(MYSQLI_ASSOC);
    $allocations->close();
    $entries = [];
    $buyerTotal = 0;
    $commissionTotal = 0;
    $sellerTotal = 0;
    foreach ($rows as $row) {
        $item = $conn->query('SELECT seller_id FROM order_items WHERE id=' . (int)$row['order_item_id'] . ' FOR UPDATE')->fetch_assoc();
        $seller = (int)($item['seller_id'] ?? 0);
        $buyer = (int)$row['buyer_refund_minor'];
        $commission = (int)$row['commission_reversal_minor'];
        $sellerDebit = (int)$row['seller_net_reversal_minor'];
        $buyerTotal += $buyer;
        $commissionTotal += $commission;
        $sellerTotal += $sellerDebit;
        if ($sellerDebit > 0) {
            $entries[] = ['account' => 'seller_payable', 'seller_id' => $seller, 'order_item_id' => (int)$row['order_item_id'], 'debit_minor' => $sellerDebit];
        }
        if ($commission > 0) {
            $entries[] = ['account' => 'commission_income', 'seller_id' => $seller, 'order_item_id' => (int)$row['order_item_id'], 'debit_minor' => $commission];
        }
    }
    if ($buyerTotal !== (int)$refund['amount_minor'] || $buyerTotal !== $sellerTotal + $commissionTotal) {
        throw new RuntimeException('Refund allocations do not reconcile.');
    }
    $entries[] = ['account' => $refund['provider'] === 'paystack' ? 'gateway_receivable' : 'manual_refund_cash', 'credit_minor' => $buyerTotal];
    financial_post_batch($conn, 'refund', (string)$refundId, 'refund:' . $refundId, (int)$refund['order_id'], (string)$refund['currency'], 'Refund processed', $actorId, $entries);
    $upd = $conn->prepare("UPDATE refunds SET status='processed', external_reference=COALESCE(NULLIF(?, ''),external_reference), processed_at=NOW() WHERE id=?");
    $upd->bind_param('si', $externalReference, $refundId);
    $upd->execute();
    $upd->close();
    refresh_payment_refund_summary($conn, (int)$refund['payment_id']);
}

function apply_refund_status(mysqli $conn, int $refundId, string $status, string $failureReason = '', string $gatewayId = '', string $gatewayReference = ''): void {
    $allowed = ['pending', 'processing', 'needs_attention', 'processed', 'failed', 'cancelled'];
    if (!in_array($status, $allowed, true)) {
        throw new RuntimeException('Unknown refund status.');
    }
    $conn->begin_transaction();
    try {
        $refund = $conn->query('SELECT * FROM refunds WHERE id=' . (int)$refundId . ' FOR UPDATE')->fetch_assoc();
        if (!$refund) {
            throw new RuntimeException('Refund not found.');
        }
        if ($refund['status'] === 'processed' && $status !== 'processed') {
            throw new RuntimeException('A processed refund cannot be reversed by a webhook.');
        }
        if ($status === 'processed' && $refund['status'] !== 'processed') {
            post_refund_processed($conn, $refundId, null, $gatewayReference !== '' ? $gatewayReference : $gatewayId);
        }
        $upd = $conn->prepare(
            "UPDATE refunds SET status=?, failure_reason=NULLIF(?, ''), gateway_refund_id=COALESCE(NULLIF(?, ''),gateway_refund_id),
             gateway_reference=COALESCE(NULLIF(?, ''),gateway_reference), processed_at=IF(?='processed',COALESCE(processed_at,NOW()),processed_at),
             failed_at=IF(?='failed',NOW(),failed_at) WHERE id=?"
        );
        $upd->bind_param('ssssssi', $status, $failureReason, $gatewayId, $gatewayReference, $status, $status, $refundId);
        $upd->execute();
        $upd->close();
        if (in_array($status, ['failed', 'cancelled'], true)) {
            $refund['status'] = $status;
        }
        refresh_payment_refund_summary($conn, (int)$refund['payment_id']);
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}

function submit_paystack_refund(mysqli $conn, int $refundId): array {
    $refund = $conn->query('SELECT r.*, p.gateway_reference AS transaction_reference FROM refunds r INNER JOIN payments p ON p.id=r.payment_id WHERE r.id=' . (int)$refundId)->fetch_assoc();
    if (!$refund || $refund['provider'] !== 'paystack') {
        return ['ok' => false, 'error' => 'This is not a Paystack refund.'];
    }
    if (in_array($refund['status'], ['processed', 'failed', 'cancelled'], true)) {
        return ['ok' => true, 'status' => $refund['status']];
    }
    if (empty($refund['gateway_reference']) && empty($refund['gateway_refund_id']) && empty($refund['transaction_reference'])) {
        return ['ok' => false, 'error' => 'The payment reference is missing.'];
    }
    if (empty($refund['gateway_reference'])) {
        $providerReference = 'CLREF-' . strtoupper(substr(hash('sha256', (string)$refund['idempotency_key']), 0, 24));
        $conn->begin_transaction();
        $locked = $conn->query('SELECT gateway_reference FROM refunds WHERE id=' . (int)$refundId . ' FOR UPDATE')->fetch_assoc();
        $refund['gateway_reference'] = (string)($locked['gateway_reference'] ?? '');
        if ($refund['gateway_reference'] === '') {
            $set = $conn->prepare('UPDATE refunds SET gateway_reference=? WHERE id=?');
            $set->bind_param('si', $providerReference, $refundId);
            $set->execute();
            $set->close();
            $refund['gateway_reference'] = $providerReference;
        }
        $conn->commit();
    }
    $body = [
        'transaction' => (string)$refund['transaction_reference'],
        'amount' => (int)$refund['amount_minor'],
        'currency' => (string)$refund['currency'],
        'customer_note' => 'CampusLink refund for order #' . (int)$refund['order_id'],
        'merchant_note' => (string)$refund['reason'],
        'reference' => (string)$refund['gateway_reference'],
    ];
    $result = paystack_api('POST', '/refund', $body);
    if (!$result['ok']) {
        $http = (int)($result['status'] ?? 0);
        if ($http === 409) {
            $lookup = paystack_api('GET', '/refund?reference=' . rawurlencode((string)$refund['gateway_reference']));
            if (!empty($lookup['ok']) && is_array($lookup['data'] ?? null) && ($lookup['data'][0] ?? null)) {
                $data = (array)$lookup['data'][0];
                $status = (string)($data['status'] ?? 'processing');
                apply_refund_status($conn, $refundId, $status === 'needs-attention' ? 'needs_attention' : $status, (string)($data['failure_reason'] ?? ''), (string)($data['id'] ?? ''), (string)($data['reference'] ?? ''));
                return ['ok' => true, 'status' => $status, 'reconciled' => true];
            }
        }
        if ($http >= 400 && $http < 500 && $http !== 409 && $http !== 429) {
            apply_refund_status($conn, $refundId, 'failed', (string)($result['error'] ?? 'Paystack rejected the refund.'));
        } else {
            apply_refund_status($conn, $refundId, 'processing');
        }
        return ['ok' => false, 'error' => $result['error'] ?? 'Refund verification is pending.', 'unknown' => $http === 0 || $http >= 500 || $http === 409 || $http === 429];
    }
    $data = (array)($result['data'] ?? []);
    $status = (string)($data['status'] ?? 'pending');
    if (!in_array($status, array_merge(refund_active_statuses(), ['processed', 'failed']), true)) {
        $status = 'pending';
    }
    apply_refund_status($conn, $refundId, $status, (string)($data['failure_reason'] ?? ''), (string)($data['id'] ?? ''), (string)($data['reference'] ?? ''));
    return ['ok' => true, 'status' => $status];
}

function refresh_paystack_refund(mysqli $conn, int $refundId): array {
    $refund = $conn->query('SELECT * FROM refunds WHERE id=' . (int)$refundId)->fetch_assoc();
    if (!$refund || $refund['provider'] !== 'paystack') {
        return ['ok' => false, 'error' => 'This is not a Paystack refund.'];
    }
    if (!empty($refund['gateway_refund_id'])) {
        $result = paystack_api('GET', '/refund/' . rawurlencode((string)$refund['gateway_refund_id']));
        $data = (array)($result['data'] ?? []);
    } elseif (!empty($refund['gateway_reference'])) {
        $result = paystack_api('GET', '/refund?reference=' . rawurlencode((string)$refund['gateway_reference']));
        $listed = (array)($result['data'] ?? []);
        $data = (array)($listed[0] ?? []);
    } else {
        return ['ok' => false, 'error' => 'No Paystack refund identity is available yet.'];
    }
    if (empty($result['ok']) || !$data) {
        return ['ok' => false, 'error' => (string)($result['error'] ?? 'Paystack could not find this refund.')];
    }
    $status = (string)($data['status'] ?? 'processing');
    apply_refund_status($conn, $refundId, $status === 'needs-attention' ? 'needs_attention' : $status, (string)($data['failure_reason'] ?? ''), (string)($data['id'] ?? ''), (string)($data['reference'] ?? ''));
    return ['ok' => true, 'status' => $status];
}

function reconcile_paystack_refund(mysqli $conn, array $data): array {
    $transactionRef = trim((string)($data['transaction_reference'] ?? ''));
    $refundReference = trim((string)($data['refund_reference'] ?? ''));
    $gatewayId = trim((string)($data['id'] ?? ''));
    $amount = (int)($data['amount'] ?? 0);
    $payment = null;
    if ($transactionRef !== '') {
        $payment = $conn->query("SELECT * FROM payments WHERE gateway_reference='" . $conn->real_escape_string($transactionRef) . "' LIMIT 1")->fetch_assoc();
    }
    if (!$payment) {
        return ['ok' => false, 'error' => 'Refund transaction could not be matched.'];
    }
    $refund = null;
    if ($gatewayId !== '' || $refundReference !== '') {
        $conditions = [];
        $params = [(int)$payment['id']];
        $types = 'i';
        if ($gatewayId !== '') { $conditions[] = 'gateway_refund_id=?'; $params[] = $gatewayId; $types .= 's'; }
        if ($refundReference !== '') { $conditions[] = 'gateway_reference=?'; $params[] = $refundReference; $types .= 's'; }
        $stmt = $conn->prepare('SELECT * FROM refunds WHERE payment_id=? AND (' . implode(' OR ', $conditions) . ') LIMIT 1');
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $refund = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    } else {
        $stmt = $conn->prepare("SELECT * FROM refunds WHERE payment_id=? AND amount_minor=? AND status IN ('pending','processing','needs_attention','processed') ORDER BY id DESC");
        $stmt->bind_param('ii', $payment['id'], $amount);
        $stmt->execute();
        $matches = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        if (count($matches) > 1) {
            return ['ok' => false, 'error' => 'Refund webhook is ambiguous; gateway identity is required.'];
        }
        $refund = $matches[0] ?? null;
    }
    if (!$refund) {
        $eventId = $gatewayId !== '' ? $gatewayId : ($refundReference !== '' ? $refundReference : hash('sha256', $transactionRef . ':' . $amount . ':' . (string)($data['status'] ?? '')));
        $refund = request_refund($conn, (int)$payment['order_id'], $amount, 'Refund received from Paystack webhook', null, 'webhook:' . $eventId, 'paystack');
    }
    $status = (string)($data['status'] ?? 'pending');
    if ($status === 'needs-attention') { $status = 'needs_attention'; }
    if ($status === 'pending' || $status === 'processing' || $status === 'processed' || $status === 'failed' || $status === 'needs_attention') {
        apply_refund_status($conn, (int)$refund['id'], $status, (string)($data['failure_reason'] ?? ''), $gatewayId, $refundReference);
    }
    return ['ok' => true, 'refund_id' => (int)$refund['id']];
}

function payout_available_lines(mysqli $conn, int $sellerId, bool $lock = false, string $currency = ''): array {
    $currency = strtoupper(trim($currency)) ?: app_payment_currency();
    $sql = "SELECT oi.*, o.id AS order_id, o.reference_code, o.payment_status, pm.currency,
            (SELECT COALESCE(SUM(ra.seller_net_reversal_minor),0)
             FROM refund_allocations ra INNER JOIN refunds r ON r.id=ra.refund_id
             WHERE ra.order_item_id=oi.id AND r.status='processed') processed_refunds,
            (SELECT COALESCE(SUM(ra.seller_net_reversal_minor),0)
             FROM refund_allocations ra INNER JOIN refunds r ON r.id=ra.refund_id
             WHERE ra.order_item_id=oi.id AND r.status IN ('pending','processing','needs_attention')) reserved_refunds,
            (SELECT COALESCE(SUM(pa.amount_minor),0)
             FROM payout_allocations pa INNER JOIN payouts p ON p.id=pa.payout_id
             WHERE pa.order_item_id=oi.id AND p.status IN ('pending','completed')) allocated_payouts,
            EXISTS(SELECT 1 FROM disputes d WHERE d.order_id=o.id AND d.status IN ('open','under_review')) disputed
            FROM order_items oi
            INNER JOIN orders o ON o.id=oi.order_id
            INNER JOIN payments pm ON pm.order_id=o.id
            WHERE oi.seller_id=? AND pm.currency=? AND o.payment_status='paid' AND oi.fulfilment_status='delivered'
            ORDER BY o.id, oi.id";
    if ($lock) { $sql .= ' FOR UPDATE'; }
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('si', $sellerId, $currency);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    foreach ($rows as &$row) {
        $row['balance_minor'] = (int)$row['disputed'] === 1
            ? 0
            : (int)$row['seller_net_amount_minor'] - (int)$row['processed_refunds'] - (int)$row['reserved_refunds'] - (int)$row['allocated_payouts'];
        $row['available_minor'] = max(0, $row['balance_minor']);
    }
    unset($row);
    return $rows;
}

function create_vendor_payout(mysqli $conn, int $sellerId, int $amountMinor, string $method, string $note, string $settlementReference, ?int $actorId): array {
    if ($amountMinor < 1 || $method === '' || mb_strlen($note) > 500 || !preg_match('/^[A-Za-z0-9_-]{8,100}$/', $settlementReference)) {
        throw new RuntimeException('Enter a valid payout amount, method, and settlement reference.');
    }
    $conn->begin_transaction();
    try {
        $seller = $conn->query('SELECT id FROM users WHERE id=' . (int)$sellerId . " AND role='seller' FOR UPDATE")->fetch_assoc();
        if (!$seller) { throw new RuntimeException('Seller not found.'); }
        $currency = app_payment_currency();
        $conn->query("SELECT oi.id FROM order_items oi INNER JOIN orders o ON o.id=oi.order_id INNER JOIN payments pm ON pm.order_id=o.id WHERE oi.seller_id=" . (int)$sellerId . " AND pm.currency='" . $conn->real_escape_string($currency) . "' ORDER BY oi.id FOR UPDATE");
        $lines = payout_available_lines($conn, $sellerId, true, $currency);
        $available = max(0, array_sum(array_column($lines, 'balance_minor')));
        if ($amountMinor > $available) {
            throw new RuntimeException('Payout exceeds the delivered, undisputed available balance after recovery debt.');
        }
        $stmt = $conn->prepare('INSERT INTO payouts (seller_id,amount_minor,currency,status,settlement_reference,method,note,created_by) VALUES (?,?,?,\'pending\',?,?,?,?)');
        $stmt->bind_param('iissssi', $sellerId, $amountMinor, $currency, $settlementReference, $method, $note, $actorId);
        $stmt->execute();
        $payoutId = (int)$conn->insert_id;
        $stmt->close();
        $left = $amountMinor;
        $alloc = $conn->prepare('INSERT INTO payout_allocations (payout_id,order_item_id,amount_minor) VALUES (?,?,?)');
        foreach ($lines as $line) {
            if ($left < 1) { break; }
            $take = min($left, (int)$line['available_minor']);
            if ($take < 1) { continue; }
            $alloc->bind_param('iii', $payoutId, $line['id'], $take);
            $alloc->execute();
            $left -= $take;
        }
        $alloc->close();
        if ($left !== 0) { throw new RuntimeException('Could not allocate the payout exactly.'); }
        $conn->commit();
        return ['id' => $payoutId, 'amount_minor' => $amountMinor, 'status' => 'pending'];
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}

function apply_payout_action(mysqli $conn, int $payoutId, string $action, string $externalReference, ?int $actorId): void {
    if (!in_array($action, ['complete', 'void'], true)) { throw new RuntimeException('Invalid payout action.'); }
    $conn->begin_transaction();
    try {
        $payout = $conn->query('SELECT * FROM payouts WHERE id=' . (int)$payoutId . ' FOR UPDATE')->fetch_assoc();
        if (!$payout || $payout['status'] !== 'pending') { throw new RuntimeException('Only pending payouts can be updated.'); }
        if ($action === 'void') {
            $upd = $conn->prepare("UPDATE payouts SET status='void', note=CONCAT(COALESCE(note,''), ' | Void reason: ', ?), updated_at=NOW() WHERE id=?");
            $upd->bind_param('si', $externalReference, $payoutId);
            $upd->execute();
            $upd->close();
        } else {
            if (trim($externalReference) === '') { throw new RuntimeException('An external payment reference is required to complete a payout.'); }
            $alloc = $conn->prepare('SELECT * FROM payout_allocations WHERE payout_id=? FOR UPDATE');
            $alloc->bind_param('i', $payoutId);
            $alloc->execute();
            $rows = $alloc->get_result()->fetch_all(MYSQLI_ASSOC);
            $alloc->close();
            $entries = [];
            $total = 0;
            foreach ($rows as $row) {
                $total += (int)$row['amount_minor'];
                $entries[] = ['account' => 'seller_payable', 'seller_id' => (int)$payout['seller_id'], 'order_item_id' => (int)$row['order_item_id'], 'debit_minor' => (int)$row['amount_minor']];
            }
            if ($total !== (int)$payout['amount_minor']) { throw new RuntimeException('Payout allocations do not reconcile.'); }
            $entries[] = ['account' => 'campus_cash', 'credit_minor' => $total];
            financial_post_batch($conn, 'payout', (string)$payoutId, 'payout:' . $payoutId, null, (string)$payout['currency'], 'Vendor payout completed', $actorId, $entries);
            $upd = $conn->prepare("UPDATE payouts SET status='completed',external_reference=?,completed_by=?,completed_at=NOW() WHERE id=?");
            $upd->bind_param('sii', $externalReference, $actorId, $payoutId);
            $upd->execute();
            $upd->close();
            notify_user($conn, (int)$payout['seller_id'], 'Payout completed', 'Settlement ' . $payout['settlement_reference'] . ' was marked paid.');
        }
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}

function complete_manual_refund(mysqli $conn, int $refundId, string $externalReference, ?int $actorId): void {
    if (trim($externalReference) === '') { throw new RuntimeException('Enter the external refund reference.'); }
    $conn->begin_transaction();
    try {
        $refund = $conn->query('SELECT * FROM refunds WHERE id=' . (int)$refundId . ' FOR UPDATE')->fetch_assoc();
        if (!$refund || $refund['provider'] === 'paystack') { throw new RuntimeException('Only manual refunds can be completed here.'); }
        if ($refund['status'] === 'processed') { $conn->commit(); return; }
        post_refund_processed($conn, $refundId, $actorId, $externalReference);
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}

function submit_order_dispute(mysqli $conn, int $orderId, string $reason, string $message, ?int $actorId): int {
    $reason = trim($reason);
    $message = trim($message);
    if ($reason === '' || mb_strlen($reason) > 100 || mb_strlen($message) < 10 || mb_strlen($message) > 2000) {
        throw new RuntimeException('Choose a reason and provide a message of 10–2000 characters.');
    }
    $conn->begin_transaction();
    try {
        $order = $conn->query('SELECT * FROM orders WHERE id=' . (int)$orderId . ' FOR UPDATE')->fetch_assoc();
        if (!$order || $order['payment_status'] !== 'paid') { throw new RuntimeException('Disputes require a paid order.'); }
        $payment = $conn->query('SELECT * FROM payments WHERE order_id=' . (int)$orderId . ' FOR UPDATE')->fetch_assoc();
        $active = $conn->prepare("SELECT id FROM disputes WHERE order_id=? AND status IN ('open','under_review') LIMIT 1");
        $active->bind_param('i', $orderId);
        $active->execute();
        if ($active->get_result()->fetch_assoc()) { $active->close(); throw new RuntimeException('This order already has an open dispute.'); }
        $active->close();
        $stmt = $conn->prepare("INSERT INTO disputes (order_id,payment_id,status,disputed_amount_minor,currency,reason,customer_message,created_by_user_id) VALUES (?,?,'open',?,?,?,?,?)");
        $amountMinor = money_to_minor($payment['amount']);
        $stmt->bind_param('iiisssi', $orderId, $payment['id'], $amountMinor, $payment['currency'], $reason, $message, $actorId);
        $stmt->execute();
        $id = (int)$conn->insert_id;
        $stmt->close();
        record_order_event($conn, $orderId, null, $actorId, $actorId ? 'buyer' : 'guest', 'dispute_opened', 'A payment dispute was opened: ' . $reason . '.');
        $admins = $conn->query("SELECT id FROM users WHERE role='admin' AND is_active=1");
        foreach ($admins as $admin) { notify_user($conn, (int)$admin['id'], 'Payment dispute opened', 'Order ' . $order['reference_code'] . ' requires review.', $orderId); }
        $conn->commit();
        return $id;
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}

function apply_gateway_dispute(mysqli $conn, string $eventType, array $data): array {
    $gatewayId = trim((string)($data['id'] ?? ''));
    $transaction = is_array($data['transaction'] ?? null) ? $data['transaction'] : [];
    $transactionRef = trim((string)($transaction['reference'] ?? $data['transaction_reference'] ?? ''));
    if ($gatewayId === '' || $transactionRef === '') { return ['ok' => false, 'error' => 'Dispute identity is incomplete.']; }
    $payment = $conn->query("SELECT * FROM payments WHERE gateway_reference='" . $conn->real_escape_string($transactionRef) . "' LIMIT 1")->fetch_assoc();
    if (!$payment) { return ['ok' => false, 'error' => 'Dispute transaction could not be matched.']; }
    $resolved = $eventType === 'charge.dispute.resolve';
    $resolution = $resolved ? mb_substr((string)($data['resolution'] ?? 'resolved'), 0, 30) : null;
    $refundLink = null;
    if ($resolved && $resolution !== null && stripos($resolution, 'merchant-won') === false && stripos($resolution, 'won') === false) {
        $activeRefund = $conn->prepare("SELECT id FROM refunds WHERE payment_id=? AND status IN ('pending','processing','needs_attention','processed') LIMIT 1");
        $activeRefund->bind_param('i', $payment['id']);
        $activeRefund->execute();
        $existingRefund = $activeRefund->get_result()->fetch_assoc();
        $activeRefund->close();
        if ($existingRefund) {
            $refundLink = (int)$existingRefund['id'];
        } else {
            $refund = request_refund($conn, (int)$payment['order_id'], money_to_minor($payment['amount']), 'Gateway dispute resolved against merchant', null, 'dispute:' . $gatewayId, 'chargeback');
            complete_manual_refund($conn, (int)$refund['id'], 'chargeback:' . $gatewayId, null);
            $refundLink = (int)$refund['id'];
        }
    }
    $conn->begin_transaction();
    try {
        $order = $conn->query('SELECT * FROM orders WHERE id=' . (int)$payment['order_id'] . ' FOR UPDATE')->fetch_assoc();
        $stmt = $conn->prepare('SELECT * FROM disputes WHERE gateway_dispute_id=? FOR UPDATE');
        $stmt->bind_param('s', $gatewayId);
        $stmt->execute();
        $dispute = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $status = $resolved ? 'resolved' : ($eventType === 'charge.dispute.remind' ? 'under_review' : 'open');
        if ($dispute) {
            if (in_array($dispute['status'], ['resolved', 'rejected'], true)) { $conn->commit(); return ['ok' => true, 'already_resolved' => true]; }
            $upd = $conn->prepare('UPDATE disputes SET status=?, resolution=?, resolved_at=IF(? IS NOT NULL,NOW(),resolved_at) WHERE id=?');
            $upd->bind_param('sssi', $status, $resolution, $resolution, $dispute['id']);
            $upd->execute();
            $upd->close();
        } else {
            $reason = mb_substr((string)($data['reason'] ?? 'Gateway chargeback'), 0, 100);
            $ins = $conn->prepare("INSERT INTO disputes (order_id,payment_id,gateway_dispute_id,status,resolution,disputed_amount_minor,currency,reason) VALUES (?,?,?,?,?,?,?,?)");
            $amount = (int)($data['amount'] ?? $transaction['amount'] ?? money_to_minor($payment['amount']));
            $currency = (string)($data['currency'] ?? $transaction['currency'] ?? $payment['currency']);
            $ins->bind_param('iiississ', $payment['order_id'], $payment['id'], $gatewayId, $status, $resolution, $amount, $currency, $reason);
            $ins->execute();
            $dispute = ['id' => (int)$conn->insert_id];
            $ins->close();
        }
        if ($refundLink !== null) {
            $link = $conn->prepare('UPDATE disputes SET refund_id=? WHERE id=?');
            $link->bind_param('ii', $refundLink, $dispute['id']);
            $link->execute();
            $link->close();
        }
        record_order_event($conn, (int)$payment['order_id'], null, null, 'system', 'gateway_dispute_' . $status, 'Paystack dispute ' . $gatewayId . ': ' . $status . ($resolution ? ' (' . $resolution . ')' : '') . '.');
        $conn->commit();
        return ['ok' => true, 'dispute_id' => (int)$dispute['id']];
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}

function resolve_order_dispute(mysqli $conn, int $disputeId, string $action, string $note, ?int $actorId): void {
    if (!in_array($action, ['review', 'resolve', 'reject'], true) || trim($note) === '' || mb_strlen($note) > 2000) {
        throw new RuntimeException('Choose an action and enter a resolution note.');
    }
    $conn->begin_transaction();
    try {
        $dispute = $conn->query('SELECT * FROM disputes WHERE id=' . (int)$disputeId . ' FOR UPDATE')->fetch_assoc();
        if (!$dispute || in_array($dispute['status'], ['resolved', 'rejected'], true)) { throw new RuntimeException('This dispute is already closed.'); }
        $status = $action === 'review' ? 'under_review' : ($action === 'resolve' ? 'resolved' : 'rejected');
        $upd = $conn->prepare('UPDATE disputes SET status=?,admin_note=?,resolution_note=?,resolved_by_user_id=?,resolved_at=IF(?<>\'under_review\',NOW(),resolved_at) WHERE id=?');
        $upd->bind_param('sssisi', $status, $note, $note, $actorId, $status, $disputeId);
        $upd->execute();
        $upd->close();
        record_order_event($conn, (int)$dispute['order_id'], null, $actorId, 'admin', 'dispute_' . $status, 'Dispute ' . $status . ': ' . $note);
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}

function seller_financial_summary(mysqli $conn, int $sellerId, string $currency = ''): array {
    $currency = strtoupper(trim($currency)) ?: app_payment_currency();
    $stmt = $conn->prepare(
        "SELECT COALESCE(SUM(CASE WHEN o.payment_status='paid' THEN oi.quantity*oi.unit_price ELSE 0 END),0) gross,
            COALESCE(SUM(CASE WHEN o.payment_status='paid' THEN oi.commission_amount_minor/100 ELSE 0 END),0) commission,
            COALESCE(SUM(CASE WHEN o.payment_status IN ('pending','failed') THEN oi.quantity*oi.unit_price ELSE 0 END),0) unpaid,
            COUNT(DISTINCT CASE WHEN o.payment_status='paid' THEN o.id END) paid_orders
         FROM order_items oi INNER JOIN orders o ON o.id=oi.order_id INNER JOIN payments pm ON pm.order_id=o.id
         WHERE oi.seller_id=? AND pm.currency=?"
    );
    $stmt->bind_param('si', $sellerId, $currency);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $refund = $conn->prepare("SELECT COALESCE(SUM(ra.seller_net_reversal_minor),0) seller_debits, COALESCE(SUM(ra.buyer_refund_minor),0) buyer_refunds FROM refund_allocations ra INNER JOIN refunds r ON r.id=ra.refund_id INNER JOIN order_items oi ON oi.id=ra.order_item_id WHERE oi.seller_id=? AND r.currency=? AND r.status='processed'");
    $refund->bind_param('si', $sellerId, $currency);
    $refund->execute();
    $refundRow = $refund->get_result()->fetch_assoc();
    $refund->close();
    $payout = $conn->prepare("SELECT COALESCE(SUM(CASE WHEN status='completed' THEN amount_minor ELSE 0 END),0) completed, COALESCE(SUM(CASE WHEN status='pending' THEN amount_minor ELSE 0 END),0) pending FROM payouts WHERE seller_id=? AND currency=?");
    $payout->bind_param('si', $sellerId, $currency);
    $payout->execute();
    $payoutRow = $payout->get_result()->fetch_assoc();
    $payout->close();
    $gross = round((float)$row['gross'], 2);
    $commission = round((float)$row['commission'], 2);
    $sellerDebits = round((int)$refundRow['seller_debits'] / 100, 2);
    $net = round($gross - $commission - $sellerDebits, 2);
    $completed = round((int)$payoutRow['completed'] / 100, 2);
    $pending = round((int)$payoutRow['pending'] / 100, 2);
    $available = round(max(0, array_sum(array_column(payout_available_lines($conn, $sellerId, false, $currency), 'balance_minor'))) / 100, 2);
    return [
        'gross_paid_sales' => $gross,
        'commissions' => $commission,
        'refunds_credits' => round((int)$refundRow['buyer_refunds'] / 100, 2),
        'seller_refund_debits' => $sellerDebits,
        'net_sales' => $net,
        'vendor_payouts' => $completed,
        'pending_payouts' => $pending,
        'outstanding_settlement' => $available,
        'recovery_debt' => max(0, round($completed - $net, 2)),
        'paid_order_count' => (int)$row['paid_orders'],
        'unpaid_line_total' => round((float)$row['unpaid'], 2),
    ];
}

function refunds_for_order(mysqli $conn, int $orderId): array {
    $stmt = $conn->prepare('SELECT * FROM refunds WHERE order_id=? ORDER BY id DESC');
    $stmt->bind_param('i', $orderId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function disputes_for_order(mysqli $conn, int $orderId): array {
    $stmt = $conn->prepare('SELECT * FROM disputes WHERE order_id=? ORDER BY id DESC');
    $stmt->bind_param('i', $orderId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function expire_abandoned_orders(mysqli $conn, int $hours, int $limit = 100, bool $dryRun = true): array {
    if ($hours < 1 || $limit < 1 || $limit > 500) { throw new RuntimeException('Invalid expiry threshold or batch limit.'); }
    $stmt = $conn->prepare("SELECT o.*,p.provider,p.gateway_reference FROM orders o INNER JOIN payments p ON p.order_id=o.id WHERE o.status='pending' AND o.payment_status='pending' AND p.status='pending' AND o.created_at < DATE_SUB(NOW(), INTERVAL ? HOUR) ORDER BY o.id LIMIT ?");
    $stmt->bind_param('ii', $hours, $limit);
    $stmt->execute();
    $orders = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $results = [];
    $cancelOrder = static function (array $order) use ($conn, $dryRun): array {
        if ($dryRun) {
            return ['action' => 'would_cancel'];
        }
        $error = cancel_pending_order($conn, $order);
        return $error === ''
            ? ['action' => 'cancelled']
            : ['action' => 'cancel_failed', 'reason' => $error];
    };
    foreach ($orders as $order) {
        $entry = ['order_id' => (int)$order['id'], 'reference' => $order['reference_code'], 'action' => 'skipped'];
        if ($order['provider'] === 'paystack') {
            $reference = trim((string)$order['gateway_reference']);
            if ($reference === '') {
                $entry = array_merge($entry, $cancelOrder($order));
            } else {
                $verify = paystack_api('GET', '/transaction/verify/' . rawurlencode($reference));
                if (!$verify['ok']) {
                    $entry['reason'] = 'Paystack could not be reached; outcome unknown.';
                } elseif ((string)($verify['data']['status'] ?? '') === 'success') {
                    if ($dryRun) {
                        $entry['action'] = 'would_verify_paid';
                    } else {
                        $error = paystack_verify_and_apply($conn, $reference, (int)$order['id']);
                        $entry['action'] = $error === '' ? 'verified_paid' : 'verify_failed';
                        $entry['reason'] = $error;
                    }
                } else {
                    $entry = array_merge($entry, $cancelOrder($order));
                }
            }
        } else {
            $entry = array_merge($entry, $cancelOrder($order));
        }
        $results[] = $entry;
    }
    return $results;
}

if (PHP_SAPI === 'cli' && ($scriptPath = realpath($_SERVER['SCRIPT_FILENAME'] ?? '')) !== false && strcasecmp($scriptPath, __FILE__) === 0) {
    require_once __DIR__ . '/../config/db.php';
    $conn->set_charset('utf8mb4');
    $migrationFiles = [
        __DIR__ . '/../database/migrations/011_payment_operations.sql',
        __DIR__ . '/../database/migrations/012_vendor_approval.sql',
        __DIR__ . '/../database/migrations/013_marketplace_commission.sql',
    ];
    try {
        foreach ($migrationFiles as $migrationFile) {
            $migrationSql = file_get_contents($migrationFile);
            if ($migrationSql === false || !$conn->multi_query($migrationSql)) {
                throw new RuntimeException($conn->error ?: 'Migration could not start.');
            }
            do {
                if ($result = $conn->store_result()) {
                    $result->free();
                }
                if (!$conn->more_results()) {
                    break;
                }
                if (!$conn->next_result()) {
                    throw new RuntimeException($conn->error ?: 'Migration statement failed.');
                }
            } while (true);
        }
    } catch (Throwable $e) {
        fwrite(STDERR, 'Migration failed: ' . $e->getMessage() . "\n");
        exit(1);
    }

    if (in_array('--migrate', $argv, true)) {
        echo "Payment operations, vendor approval, and commission setting migrations applied.\n";
        exit(0);
    }

    require_once __DIR__ . '/payment_gateway.php';

    if (in_array('--backfill-captures', $argv, true)) {
        $limit = 100;
        foreach ($argv as $argument) {
            if (preg_match('/^--limit=(\d+)$/', $argument, $matches)) {
                $limit = (int)$matches[1];
            }
        }
        if ($limit < 1 || $limit > 500) {
            fwrite(STDERR, "Use --limit=1..500.\n");
            exit(1);
        }
        $apply = in_array('--apply', $argv, true);
        $stmt = $conn->prepare(
            "SELECT p.id, p.order_id, p.provider, o.reference_code
             FROM payments p
             INNER JOIN orders o ON o.id=p.order_id
             LEFT JOIN financial_batches fb ON fb.event_key=CONCAT('capture:',p.id)
             WHERE p.status='paid' AND o.payment_status='paid' AND fb.id IS NULL
             ORDER BY p.id ASC LIMIT ?"
        );
        $stmt->bind_param('i', $limit);
        $stmt->execute();
        $payments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        $posted = 0;
        $failed = 0;
        foreach ($payments as $payment) {
            $label = (string)$payment['reference_code'] . ' (payment #' . (int)$payment['id'] . ')';
            if (!$apply) {
                echo 'would post: ' . $label . "\n";
                continue;
            }
            $conn->begin_transaction();
            try {
                post_payment_capture($conn, (int)$payment['order_id'], null, (string)$payment['provider']);
                $conn->commit();
                $posted++;
                echo 'posted: ' . $label . "\n";
            } catch (Throwable $e) {
                $conn->rollback();
                $failed++;
                echo 'failed: ' . $label . ' - ' . $e->getMessage() . "\n";
            }
        }
        echo $apply ? "Done: {$posted} posted, {$failed} failed.\n" : "Dry run only; use --apply to post these captures.\n";
        exit($failed === 0 ? 0 : 1);
    }

    if (in_array('--expire-orders', $argv, true)) {
        $hours = 24;
        $limit = 100;
        foreach ($argv as $argument) {
            if (preg_match('/^--hours=(\d+)$/', $argument, $matches)) {
                $hours = (int)$matches[1];
            }
            if (preg_match('/^--limit=(\d+)$/', $argument, $matches)) {
                $limit = (int)$matches[1];
            }
        }
        if ($hours < 1 || $hours > 8760 || $limit < 1 || $limit > 500) {
            fwrite(STDERR, "Use --hours=1..8760 and --limit=1..500.\n");
            exit(1);
        }
        try {
            $results = expire_abandoned_orders($conn, $hours, $limit, !in_array('--apply', $argv, true));
            foreach ($results as $result) {
                echo ($result['reference'] ?? ('#' . $result['order_id'])) . ': ' . $result['action'];
                if (!empty($result['reason'])) {
                    echo ' - ' . $result['reason'];
                }
                echo "\n";
            }
            echo 'DONE: ' . count($results) . " order(s) examined\n";
            exit(0);
        } catch (Throwable $e) {
            fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n");
            exit(1);
        }
    }

    fwrite(STDERR, "Use --migrate, --backfill-captures, or --expire-orders.\n");
    exit(1);
}
