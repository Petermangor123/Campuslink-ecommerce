<?php
/**
 * Paystack + manual payment adapters.
 * Secrets come from sec_env / .env only — never from the browser.
 */
require_once __DIR__ . '/order_service.php';
if (!function_exists('sec_env')) {
    require_once __DIR__ . '/security_core.php';
}

function paystack_secret_key(): string {
    return trim(sec_env('PAYSTACK_SECRET_KEY', ''));
}

function paystack_public_key(): string {
    return trim(sec_env('PAYSTACK_PUBLIC_KEY', ''));
}

function paystack_base_url(): string {
    $base = rtrim(trim(sec_env('PAYSTACK_BASE_URL', 'https://api.paystack.co')), '/');
    return $base !== '' ? $base : 'https://api.paystack.co';
}

function paystack_currency(): string {
    // Must match the Paystack merchant’s supported settlement currency.
    $c = strtoupper(trim(sec_env('PAYSTACK_CURRENCY', 'GHS')));
    return preg_match('/^[A-Z]{3}$/', $c) ? $c : 'GHS';
}

function payment_gateway_mode(): string {
    $secret = paystack_secret_key();
    $public = paystack_public_key();
    $secretPlaceholder = preg_match('/^sk_(test|live)_x+$/i', $secret) === 1;
    $publicPlaceholder = preg_match('/^pk_(test|live)_x+$/i', $public) === 1;
    if (
        $secret !== ''
        && $public !== ''
        && !$secretPlaceholder
        && !$publicPlaceholder
        && (str_starts_with($secret, 'sk_test_') || str_starts_with($secret, 'sk_live_'))
    ) {
        return 'paystack';
    }
    return 'manual_review';
}

function paystack_callback_url(): string {
    $origin = trim(sec_env('CAMPUSLINK_PUBLIC_ORIGIN', ''));
    if ($origin === '') {
        $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
        if (function_exists('sec_valid_host') && sec_valid_host($host)) {
            $origin = (function_exists('sec_is_https') && sec_is_https() ? 'https://' : 'http://') . $host;
        } else {
            $origin = 'http://localhost';
        }
    }
    return rtrim($origin, '/') . APP_BASE_URL . '/public/paystack_callback.php';
}

/**
 * @return array{ok:bool,error?:string,status?:int,data?:array}
 */
function paystack_api(string $method, string $path, ?array $body = null): array {
    $secret = paystack_secret_key();
    if ($secret === '') {
        return ['ok' => false, 'error' => 'Paystack is not configured on this server.'];
    }
    $url = paystack_base_url() . $path;
    $ch = curl_init($url);
    $headers = [
        'Authorization: Bearer ' . $secret,
        'Accept: application/json',
    ];
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => $headers,
    ];
    if ($body !== null) {
        $json = json_encode($body, JSON_UNESCAPED_SLASHES);
        $headers[] = 'Content-Type: application/json';
        $opts[CURLOPT_HTTPHEADER] = $headers;
        $opts[CURLOPT_POSTFIELDS] = $json;
    }
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($errno || $raw === false) {
        error_log('CampusLink Paystack transport error ' . $errno);
        return ['ok' => false, 'error' => 'Could not reach Paystack.', 'status' => $http];
    }
    $payload = json_decode($raw, true);
    if (!is_array($payload)) {
        return ['ok' => false, 'error' => 'Invalid Paystack response.', 'status' => $http];
    }
    if ($http >= 400 || empty($payload['status'])) {
        $msg = (string)($payload['message'] ?? 'Paystack request failed.');
        error_log('CampusLink Paystack API ' . $http . ': ' . $msg);
        return ['ok' => false, 'error' => $msg, 'status' => $http, 'data' => $payload];
    }
    return ['ok' => true, 'status' => $http, 'data' => $payload['data'] ?? []];
}

function paystack_amount_subunits(float $major): int {
    if (!function_exists('currency_to_minor_units')) {
        require_once __DIR__ . '/currency.php';
    }
    return currency_to_minor_units($major);
}

/**
 * Initialize a Paystack transaction for a pending order.
 * @return array{ok:bool,authorization_url?:string,reference?:string,error?:string}
 */
function paystack_initialize_payment(mysqli $conn, array $order, string $trackingToken = ''): array {
    if (payment_gateway_mode() !== 'paystack') {
        return ['ok' => false, 'error' => 'Paystack is not configured.'];
    }
    $orderId = (int)$order['id'];
    $payStmt = $conn->prepare('SELECT * FROM payments WHERE order_id=? LIMIT 1');
    $payStmt->bind_param('i', $orderId);
    $payStmt->execute();
    $payment = $payStmt->get_result()->fetch_assoc();
    $payStmt->close();
    if (!$payment) {
        return ['ok' => false, 'error' => 'Payment record missing.'];
    }
    if ($payment['status'] === 'paid') {
        return ['ok' => false, 'error' => 'This order is already paid.'];
    }

    $email = trim((string)($order['customer_email'] ?? ''));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'A valid customer email is required for Paystack.'];
    }

    $currency = paystack_currency();
    $amountMajor = round((float)$payment['amount'], 2);
    $amount = paystack_amount_subunits($amountMajor);
    if ($amount < 100) {
        // Paystack often rejects tiny amounts; keep a clear error for empty carts etc.
        return ['ok' => false, 'error' => 'Order amount is too small for Paystack checkout.'];
    }

    // Stable-ish unique reference tying to CampusLink order id (never reuse after success).
    $reference = 'CLPAY' . $orderId . 'T' . strtoupper(bin2hex(random_bytes(4)));

    $callback = paystack_callback_url();
    if ($trackingToken !== '') {
        $callback .= (str_contains($callback, '?') ? '&' : '?') . 't=' . rawurlencode($trackingToken);
    }

    $init = paystack_api('POST', '/transaction/initialize', [
        'email' => $email,
        'amount' => $amount,
        'currency' => $currency,
        'reference' => $reference,
        'callback_url' => $callback,
        'metadata' => [
            'order_id' => $orderId,
            'order_reference' => (string)$order['reference_code'],
            'custom_fields' => [
                [
                    'display_name' => 'Order',
                    'variable_name' => 'order_reference',
                    'value' => (string)$order['reference_code'],
                ],
            ],
        ],
    ]);
    if (!$init['ok']) {
        return ['ok' => false, 'error' => $init['error'] ?? 'Could not start Paystack checkout.'];
    }
    $data = $init['data'];
    $authUrl = (string)($data['authorization_url'] ?? '');
    $ref = (string)($data['reference'] ?? $reference);
    if ($authUrl === '' || $ref === '') {
        return ['ok' => false, 'error' => 'Paystack did not return a checkout URL.'];
    }

    $prov = 'paystack';
    $upd = $conn->prepare(
        "UPDATE payments SET provider=?, gateway_reference=?, currency=?, status='pending' WHERE order_id=? AND status IN ('pending','failed')"
    );
    $upd->bind_param('sssi', $prov, $ref, $currency, $orderId);
    $upd->execute();
    $upd->close();

    $orderUpd = $conn->prepare("UPDATE orders SET payment_status='pending' WHERE id=? AND payment_status='failed'");
    $orderUpd->bind_param('i', $orderId);
    $orderUpd->execute();
    $orderUpd->close();

    record_order_event(
        $conn,
        $orderId,
        null,
        null,
        'system',
        'paystack_initialized',
        'Paystack checkout started for ' . $order['reference_code'] . ' (ref ' . $ref . ').'
    );

    return ['ok' => true, 'authorization_url' => $authUrl, 'reference' => $ref];
}

/**
 * Verify via Paystack API and mark paid once. $orderId may be 0 to resolve via reference/metadata.
 */
function paystack_verify_and_apply(mysqli $conn, string $gatewayReference, int $orderId = 0): string {
    if (payment_gateway_mode() !== 'paystack' && paystack_secret_key() === '') {
        return 'Paystack is not configured on this server.';
    }
    $gatewayReference = trim($gatewayReference);
    if ($gatewayReference === '' || !preg_match('/^[A-Za-z0-9_\-]{6,100}$/', $gatewayReference)) {
        return 'Invalid payment reference.';
    }

    $verify = paystack_api('GET', '/transaction/verify/' . rawurlencode($gatewayReference));
    if (!$verify['ok']) {
        return $verify['error'] ?? 'Paystack verification failed.';
    }
    $data = $verify['data'];
    if (!is_array($data)) {
        return 'Paystack returned an unexpected response.';
    }

    $metaOrderId = (int)($data['metadata']['order_id'] ?? 0);
    if ($orderId < 1) {
        $orderId = $metaOrderId;
    }
    if ($orderId < 1) {
        $find = $conn->prepare('SELECT order_id FROM payments WHERE gateway_reference=? LIMIT 1');
        $find->bind_param('s', $gatewayReference);
        $find->execute();
        $row = $find->get_result()->fetch_assoc();
        $find->close();
        $orderId = $row ? (int)$row['order_id'] : 0;
    }
    if ($orderId < 1) {
        return 'Could not match this payment to an order.';
    }
    if ($metaOrderId > 0 && $metaOrderId !== $orderId) {
        return 'Paystack metadata order does not match the requested order.';
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
            throw new RuntimeException('Payment record missing.');
        }

        $txStatus = (string)($data['status'] ?? '');
        if ($txStatus !== 'success') {
            if ($payment['status'] === 'pending') {
                $fail = $conn->prepare("UPDATE payments SET status='failed', gateway_reference=? WHERE order_id=? AND status='pending'");
                $fail->bind_param('si', $gatewayReference, $orderId);
                $fail->execute();
                $fail->close();
                $os = $conn->prepare("UPDATE orders SET payment_status='failed' WHERE id=? AND payment_status='pending'");
                $os->bind_param('i', $orderId);
                $os->execute();
                $os->close();
                record_order_event($conn, $orderId, null, null, 'system', 'payment_failed', 'Paystack status: ' . ($txStatus !== '' ? $txStatus : 'unsuccessful') . '.');
            }
            $conn->commit();
            return 'Payment was not successful.';
        }

        $amountPaid = ((int)($data['amount'] ?? 0)) / 100.0;
        $currency = strtoupper((string)($data['currency'] ?? ''));
        $expected = round((float)$payment['amount'], 2);
        $expectedCurrency = strtoupper((string)($payment['currency'] ?: paystack_currency()));

        if (abs($amountPaid - $expected) > 0.009) {
            throw new RuntimeException('Paid amount does not match the order total.');
        }
        if ($currency !== '' && $currency !== $expectedCurrency) {
            throw new RuntimeException('Paid currency does not match the order.');
        }

        if ($payment['status'] === 'paid') {
            $existingRef = trim((string)($payment['gateway_reference'] ?? ''));
            if ($existingRef !== '' && !hash_equals($existingRef, $gatewayReference)) {
                throw new RuntimeException('Payment reference does not match this order’s checkout.');
            }
            if ($existingRef === '') {
                $fix = $conn->prepare('UPDATE payments SET gateway_reference=? WHERE order_id=?');
                $fix->bind_param('si', $gatewayReference, $orderId);
                $fix->execute();
                $fix->close();
            }
            post_payment_capture($conn, $orderId, null, 'paystack');
            $conn->commit();
            return '';
        }

        $dup = $conn->prepare('SELECT id FROM payments WHERE gateway_reference=? AND order_id<>? LIMIT 1');
        $dup->bind_param('si', $gatewayReference, $orderId);
        $dup->execute();
        if ($dup->get_result()->fetch_assoc()) {
            $dup->close();
            throw new RuntimeException('This Paystack reference belongs to another order.');
        }
        $dup->close();

        // Existing gateway_reference on this payment must match if already set.
        $existingRef = trim((string)($payment['gateway_reference'] ?? ''));
        if ($existingRef !== '' && !hash_equals($existingRef, $gatewayReference)) {
            throw new RuntimeException('Payment reference does not match this order’s checkout.');
        }

        set_payment_status($conn, $orderId, 'paid');
        $upd = $conn->prepare(
            "UPDATE payments SET provider='paystack', gateway_reference=?, currency=?, verified_at=NOW() WHERE order_id=?"
        );
        $upd->bind_param('ssi', $gatewayReference, $expectedCurrency, $orderId);
        $upd->execute();
        $upd->close();
        set_order_state($conn, $orderId, 'paid', 'paid');
        post_payment_capture($conn, $orderId, null, 'paystack');
        open_vendor_fulfilment($conn, $order);
        $ref = (string)$order['reference_code'];
        record_order_event($conn, $orderId, null, null, 'system', 'payment_confirmed', 'Paystack verified payment for ' . $ref . '.');
        notify_order_sellers($conn, $orderId, 'New paid order · ' . $ref, 'Payment verified. Please accept or reject your items.');
        notify_order_buyer($conn, $order, 'Payment confirmed · ' . $ref, 'Your payment for ' . $ref . ' was verified.');
        send_status_email($order, 'CampusLink payment confirmed · ' . $ref, "Hello {$order['customer_name']},\n\nPayment for {$ref} was verified via Paystack.\nVendors can now fulfil your items.\n\nCampusLink");
        $conn->commit();
        return '';
    } catch (Throwable $e) {
        $conn->rollback();
        error_log('CampusLink Paystack verify apply: ' . $e->getMessage());
        return $e instanceof RuntimeException ? $e->getMessage() : 'Payment verification failed.';
    }
}

/**
 * Legacy helper: insert-once audit row (used by older tests).
 * Prefer paystack_claim_webhook_event() for webhook handling.
 */
function paystack_log_event(mysqli $conn, string $eventId, string $eventType, ?int $orderId, string $payloadHash): bool {
    $stmt = $conn->prepare(
        "INSERT IGNORE INTO payment_events (event_id, event_type, order_id, payload_hash, process_status)
         VALUES (?,?,?,?, 'applied')"
    );
    $stmt->bind_param('ssis', $eventId, $eventType, $orderId, $payloadHash);
    $stmt->execute();
    $inserted = $stmt->affected_rows === 1;
    $stmt->close();
    return $inserted;
}

/**
 * Claim a webhook event for processing.
 * @return 'proceed'|'already_applied'|'inflight'
 */
function paystack_claim_webhook_event(
    mysqli $conn,
    string $eventId,
    string $eventType,
    ?int $orderId,
    string $payloadHash
): string {
    $staleSeconds = 120;
    $conn->begin_transaction();
    try {
        $ins = $conn->prepare(
            "INSERT IGNORE INTO payment_events
                (event_id, event_type, order_id, payload_hash, process_status)
             VALUES (?,?,?,?, 'claimed')"
        );
        $ins->bind_param('ssis', $eventId, $eventType, $orderId, $payloadHash);
        $ins->execute();
        $inserted = $ins->affected_rows === 1;
        $ins->close();
        if ($inserted) {
            $conn->commit();
            return 'proceed';
        }

        $sel = $conn->prepare(
            'SELECT process_status,
                    TIMESTAMPDIFF(SECOND, created_at, NOW()) AS age_sec
             FROM payment_events WHERE event_id=? LIMIT 1 FOR UPDATE'
        );
        $sel->bind_param('s', $eventId);
        $sel->execute();
        $row = $sel->get_result()->fetch_assoc();
        $sel->close();
        if (!$row) {
            // Race: row vanished — allow insert path next time.
            $conn->commit();
            return 'proceed';
        }

        $status = (string)$row['process_status'];
        if ($status === 'applied') {
            $conn->commit();
            return 'already_applied';
        }

        $ageSec = (int)($row['age_sec'] ?? 0);
        // Recent claim held by another worker — use MySQL NOW() to avoid PHP/DB TZ skew.
        if ($status === 'claimed' && $ageSec < $staleSeconds) {
            $conn->commit();
            return 'inflight';
        }

        // failed or stale claimed → reclaim for retry
        if ($orderId === null) {
            $upd = $conn->prepare(
                "UPDATE payment_events
                 SET process_status='claimed', last_error=NULL, payload_hash=?, event_type=?
                 WHERE event_id=?"
            );
            $upd->bind_param('sss', $payloadHash, $eventType, $eventId);
        } else {
            $upd = $conn->prepare(
                "UPDATE payment_events
                 SET process_status='claimed', last_error=NULL, payload_hash=?, order_id=?, event_type=?
                 WHERE event_id=?"
            );
            $upd->bind_param('siss', $payloadHash, $orderId, $eventType, $eventId);
        }
        $upd->execute();
        $upd->close();
        $conn->commit();
        return 'proceed';
    } catch (Throwable $e) {
        $conn->rollback();
        error_log('CampusLink webhook claim failed: ' . $e->getMessage());
        return 'inflight';
    }
}

function paystack_mark_webhook_applied(mysqli $conn, string $eventId): void {
    $stmt = $conn->prepare(
        "UPDATE payment_events
         SET process_status='applied', last_error=NULL, applied_at=NOW()
         WHERE event_id=?"
    );
    $stmt->bind_param('s', $eventId);
    $stmt->execute();
    $stmt->close();
}

function paystack_mark_webhook_failed(mysqli $conn, string $eventId, string $error): void {
    $err = mb_substr(trim($error), 0, 255);
    $stmt = $conn->prepare(
        "UPDATE payment_events
         SET process_status='failed', last_error=?
         WHERE event_id=? AND process_status <> 'applied'"
    );
    $stmt->bind_param('ss', $err, $eventId);
    $stmt->execute();
    $stmt->close();
}

/** Terminal non-success messages from verify — do not keep retrying forever. */
function paystack_verify_message_is_terminal(string $message): bool {
    $m = strtolower(trim($message));
    if ($m === '') {
        return true;
    }
    $terminals = [
        'payment was not successful.',
        'invalid payment reference.',
        'could not match this payment to an order.',
        'paystack metadata order does not match the requested order.',
        'this paystack reference belongs to another order.',
        'payment reference does not match this order',
        'paid amount does not match the order total.',
        'paid currency does not match the order.',
        'order not found.',
        'payment record missing.',
        'this order is already paid.',
    ];
    foreach ($terminals as $t) {
        if (str_contains($m, $t)) {
            return true;
        }
    }
    return false;
}

function paystack_valid_signature(string $rawBody, string $signatureHeader): bool {
    $secret = paystack_secret_key();
    if ($secret === '' || $signatureHeader === '') {
        return false;
    }
    $expected = hash_hmac('sha512', $rawBody, $secret);
    return hash_equals($expected, $signatureHeader);
}
