<?php
/**
 * Start or retry Paystack checkout for a pending order (token / buyer access required).
 */
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/payment_gateway.php';

$reference = strtoupper(trim((string)($_GET['ref'] ?? $_POST['reference'] ?? '')));
$token = trim((string)($_GET['t'] ?? $_POST['token'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
}

if ($reference === '') {
    set_flash('error', 'Missing order reference.');
    redirect(APP_BASE_URL . '/public/track.php');
}

$order = order_by_reference($conn, $reference);
if (!$order || !order_access_granted($order, $token, '')) {
    set_flash('error', 'You are not allowed to pay for that order.');
    redirect(APP_BASE_URL . '/public/track.php?ref=' . rawurlencode($reference));
}

if (($order['payment_status'] ?? '') === 'paid') {
    set_flash('info', 'This order is already paid.');
    redirect(APP_BASE_URL . '/public/track.php?ref=' . rawurlencode($reference) . ($token !== '' ? '&t=' . rawurlencode($token) : '') . '&paid=1');
}

if (payment_gateway_mode() !== 'paystack') {
    set_flash('error', 'Online card payment is not configured. An administrator can confirm campus payment.');
    redirect(APP_BASE_URL . '/public/track.php?ref=' . rawurlencode($reference) . ($token !== '' ? '&t=' . rawurlencode($token) : ''));
}

// Flush session before the Paystack HTTP round-trip (avoids lock stacking on retry).
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$result = paystack_initialize_payment($conn, $order, $token);
if (!$result['ok']) {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    set_flash('error', $result['error'] ?? 'Could not start Paystack checkout.');
    redirect(APP_BASE_URL . '/public/track.php?ref=' . rawurlencode($reference) . ($token !== '' ? '&t=' . rawurlencode($token) : '') . '&pay_failed=1');
}

redirect($result['authorization_url']);
