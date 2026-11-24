<?php
/**
 * Paystack webhook receiver.
 * - HMAC signature required
 * - Claim/apply/fail process_status so failed verifies remain retryable
 * - Payment marked paid only inside paystack_verify_and_apply() after API verify
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/payment_gateway.php';

header('Content-Type: application/json; charset=utf-8');

$raw = file_get_contents('php://input');
if ($raw === false || $raw === '') {
    http_response_code(400);
    echo json_encode(['status' => false, 'message' => 'Empty body']);
    exit;
}

$signature = (string)($_SERVER['HTTP_X_PAYSTACK_SIGNATURE'] ?? '');
if (!paystack_valid_signature($raw, $signature)) {
    error_log('CampusLink Paystack webhook: invalid signature');
    http_response_code(401);
    echo json_encode(['status' => false, 'message' => 'Invalid signature']);
    exit;
}

$payload = json_decode($raw, true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['status' => false, 'message' => 'Invalid JSON']);
    exit;
}

$eventType = (string)($payload['event'] ?? '');
$data = $payload['data'] ?? [];
$eventId = '';
if (is_array($data)) {
    $identity = (string)($data['refund_reference'] ?? '');
    if ($identity === '') {
        $identity = (string)($data['id'] ?? '');
    }
    if ($identity === '') {
        $eventId = hash('sha256', $raw);
    } else {
        $eventId = $eventType . ':' . $identity;
    }
} else {
    $eventId = hash('sha256', $raw);
}

$gatewayRef = is_array($data) ? (string)($data['reference'] ?? '') : '';
$transactionRef = is_array($data) ? (string)($data['transaction_reference'] ?? '') : '';
if ($transactionRef !== '') {
    $gatewayRef = $transactionRef;
}
$metaOrderId = is_array($data) ? (int)($data['metadata']['order_id'] ?? 0) : 0;
$orderId = $metaOrderId > 0 ? $metaOrderId : null;
$hash = hash('sha256', $raw);

$claim = paystack_claim_webhook_event(
    $conn,
    $eventId,
    $eventType !== '' ? $eventType : 'unknown',
    $orderId,
    $hash
);

if ($claim === 'already_applied') {
    http_response_code(200);
    echo json_encode(['status' => true, 'message' => 'Already processed']);
    exit;
}
if ($claim === 'inflight') {
    // Concurrent delivery while another worker holds the claim — ask Paystack to retry briefly.
    http_response_code(409);
    echo json_encode(['status' => false, 'message' => 'Processing in progress']);
    exit;
}

if ($eventType === 'charge.success' || $eventType === 'paymentrequest.success') {
    if ($gatewayRef === '') {
        paystack_mark_webhook_applied($conn, $eventId);
        http_response_code(200);
        echo json_encode(['status' => true, 'message' => 'No reference']);
        exit;
    }

    $msg = paystack_verify_and_apply($conn, $gatewayRef, $metaOrderId);
    if ($msg === '') {
        paystack_mark_webhook_applied($conn, $eventId);
        http_response_code(200);
        echo json_encode(['status' => true, 'message' => 'Applied']);
        exit;
    }

    error_log('CampusLink Paystack webhook verify: ' . $msg);

    if (paystack_verify_message_is_terminal($msg)) {
        // Definitive non-success (failed charge, mismatch, etc.) — do not loop forever.
        paystack_mark_webhook_applied($conn, $eventId);
        http_response_code(200);
        echo json_encode(['status' => true, 'message' => 'Recorded without paid status', 'detail' => $msg]);
        exit;
    }

    // Transient / transport — leave failed so a later delivery can reclaim.
    paystack_mark_webhook_failed($conn, $eventId, $msg);
    http_response_code(500);
    echo json_encode(['status' => false, 'message' => 'Verification deferred', 'detail' => $msg]);
    exit;
}

require_once __DIR__ . '/../includes/order_service.php';
try {
    if (str_starts_with($eventType, 'refund.')) {
        $result = reconcile_paystack_refund($conn, is_array($data) ? $data : []);
        if (empty($result['ok'])) {
            throw new RuntimeException((string)($result['error'] ?? 'Refund could not be reconciled.'));
        }
        paystack_mark_webhook_applied($conn, $eventId);
        http_response_code(200);
        echo json_encode(['status' => true, 'message' => 'Refund recorded']);
        exit;
    }
    if (in_array($eventType, ['charge.dispute.create', 'charge.dispute.remind', 'charge.dispute.resolve'], true)) {
        $result = apply_gateway_dispute($conn, $eventType, is_array($data) ? $data : []);
        if (empty($result['ok'])) {
            throw new RuntimeException((string)($result['error'] ?? 'Dispute could not be recorded.'));
        }
        paystack_mark_webhook_applied($conn, $eventId);
        http_response_code(200);
        echo json_encode(['status' => true, 'message' => 'Dispute recorded']);
        exit;
    }
} catch (Throwable $e) {
    error_log('CampusLink Paystack financial webhook: ' . $e->getMessage());
    paystack_mark_webhook_failed($conn, $eventId, $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => false, 'message' => 'Financial event deferred']);
    exit;
}

// Failed / abandoned — record locally; never invent paid status.
if (in_array($eventType, ['charge.failed', 'paymentrequest.failed'], true) && $gatewayRef !== '') {
    $stmt = $conn->prepare(
        "UPDATE payments SET status='failed' WHERE gateway_reference=? AND status='pending'"
    );
    $stmt->bind_param('s', $gatewayRef);
    $stmt->execute();
    $stmt->close();

    $os = $conn->prepare(
        "UPDATE orders o
         INNER JOIN payments p ON p.order_id = o.id
         SET o.payment_status='failed'
         WHERE p.gateway_reference=? AND o.payment_status='pending'"
    );
    $os->bind_param('s', $gatewayRef);
    $os->execute();
    $os->close();
}

paystack_mark_webhook_applied($conn, $eventId);
http_response_code(200);
echo json_encode(['status' => true, 'message' => 'Ignored']);
