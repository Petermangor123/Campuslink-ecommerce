<?php
/**
 * Paystack return URL. Never trusts the redirect alone — always verifies server-side.
 */
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/payment_gateway.php';

$reference = trim((string)($_GET['reference'] ?? $_GET['trxref'] ?? ''));
$token = trim((string)($_GET['t'] ?? ''));
$orderId = (int)($_GET['order_id'] ?? 0);

if ($reference === '') {
    set_flash('error', 'Missing Paystack payment reference.');
    redirect(APP_BASE_URL . '/public/track.php');
}

$message = paystack_verify_and_apply($conn, $reference, $orderId);
$order = null;
if ($orderId > 0) {
    $order = order_by_id($conn, $orderId);
}
if (!$order) {
    $pay = $conn->prepare('SELECT order_id FROM payments WHERE gateway_reference=? LIMIT 1');
    $pay->bind_param('s', $reference);
    $pay->execute();
    $row = $pay->get_result()->fetch_assoc();
    $pay->close();
    if ($row) {
        $order = order_by_id($conn, (int)$row['order_id']);
    }
}

$refCode = $order['reference_code'] ?? '';
if ($order && $token !== '') {
    remember_tracked_order($refCode);
}

if ($message === '') {
    set_flash('success', 'Payment verified successfully.');
    $qs = 'ref=' . rawurlencode($refCode) . '&paid=1';
    if ($token !== '') {
        $qs .= '&t=' . rawurlencode($token);
    }
    redirect(APP_BASE_URL . '/public/track.php?' . $qs);
}

set_flash('error', $message);
$qs = 'ref=' . rawurlencode($refCode) . '&pay_failed=1';
if ($token !== '') {
    $qs .= '&t=' . rawurlencode($token);
}
if ($reference !== '') {
    $qs .= '&trx=' . rawurlencode($reference);
}
redirect(APP_BASE_URL . '/public/track.php?' . $qs);
