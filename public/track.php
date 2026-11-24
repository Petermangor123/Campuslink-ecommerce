<?php
// Guest and buyer order tracking.
// Access requires the private tracking token, a logged-in buyer who owns the order,
// or a same-browser session already proven with that token — never email + CL-### alone.
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/order_service.php';
require_once __DIR__ . '/../includes/payment_gateway.php';
require_once __DIR__ . '/../includes/order_service.php';

$noindex = true;
$reference = strtoupper(trim((string)($_GET['ref'] ?? $_POST['reference'] ?? '')));
$token = trim((string)($_GET['t'] ?? $_POST['token'] ?? ''));
$order = null;
$items = [];
$lookupError = '';
$justPlaced = (($_GET['placed'] ?? '') === '1');
$justPaid = (($_GET['paid'] ?? '') === '1');
$payFailed = (($_GET['pay_failed'] ?? '') === '1');
$trxShown = trim((string)($_GET['trx'] ?? ''));
$payMode = payment_gateway_mode();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (isset($_POST['cancel_order'])) {
        $reference = strtoupper(trim((string)($_POST['reference'] ?? '')));
        $token = trim((string)($_POST['token'] ?? ''));
        $order = $reference !== '' ? order_by_reference($conn, $reference) : null;
        if (!$order || !order_access_granted($order, $token)) {
            $lookupError = 'We could not update that order.';
            $order = null;
        } else {
            $message = cancel_pending_order($conn, $order);
            if ($message !== '') {
                set_flash('error', $message);
            } else {
                set_flash('success', 'The unpaid order was cancelled and reserved stock was returned.');
            }
            redirect(APP_BASE_URL . '/public/track.php?ref=' . rawurlencode($reference) . ($token !== '' ? '&t=' . rawurlencode($token) : ''));
        }
    } elseif (($_POST['action'] ?? '') === 'open_dispute') {
        $reference = strtoupper(trim((string)($_POST['reference'] ?? '')));
        $token = trim((string)($_POST['token'] ?? ''));
        $found = $reference !== '' ? order_by_reference($conn, $reference) : null;
        $rate = sec_rate('dispute-ip:' . sec_client_ip(), 5, 600, true);
        if (!$found || !order_access_granted($found, $token) || $rate['limited']) {
            set_flash('error', $rate['limited'] ? 'Too many dispute submissions. Please try again later.' : 'We could not update that order.');
        } else {
            try {
                $actor = is_logged_in() && current_user_role() === 'buyer' ? current_user_id() : null;
                submit_order_dispute($conn, (int)$found['id'], (string)($_POST['reason'] ?? ''), (string)($_POST['message'] ?? ''), $actor);
                set_flash('success', 'Your dispute was submitted for review.');
            } catch (Throwable $e) {
                set_flash('error', $e->getMessage());
            }
        }
        redirect(APP_BASE_URL . '/public/track.php?ref=' . rawurlencode($reference) . ($token !== '' ? '&t=' . rawurlencode($token) : ''));
    } elseif ($reference !== '') {
        $ipThrottle = sec_rate('track-ip:' . sec_client_ip(), 20, 600, true);
        if (tracking_rate_limited() || $ipThrottle['limited']) {
            sec_log('track_rate_limited', ['ip_limited' => $ipThrottle['limited']]);
            http_response_code(429);
            header('Retry-After: ' . max(60, (int)$ipThrottle['retry']));
            $lookupError = 'Too many lookup attempts. Wait a few minutes and try again.';
        } else {
            $found = order_by_reference($conn, $reference);
            if (!$found || !order_access_granted($found, $token)) {
                record_tracking_failure();
                $lookupError = 'We could not find an order with those details. Use the private tracking link from checkout (it includes a secure token), or sign in as the buyer who placed the order.';
            } else {
                remember_tracked_order($found['reference_code']);
                $order = $found;
            }
        }
    }
}

if (!$order && $reference !== '') {
    $found = order_by_reference($conn, $reference);
    if ($found && order_access_granted($found, $token)) {
        remember_tracked_order($found['reference_code']);
        $order = $found;
    } elseif (($token !== '' || is_logged_in()) && $lookupError === '') {
        $lookupError = 'We could not find an order with those details.';
    }
}

$events = [];
$paymentRow = null;
$refunds = [];
$disputes = [];
if ($order) {
    $items = order_line_items($conn, (int)$order['id']);
    $events = order_events($conn, (int)$order['id']);
    $oid = (int)$order['id'];
    $refunds = refunds_for_order($conn, (int)$order['id']);
    $disputes = disputes_for_order($conn, (int)$order['id']);
    $ps = $conn->prepare('SELECT status, provider, gateway_reference, currency, amount, verified_at, refund_status, refunded_amount_minor FROM payments WHERE order_id=? LIMIT 1');
    $ps->bind_param('i', $oid);
    $ps->execute();
    $paymentRow = $ps->get_result()->fetch_assoc() ?: null;
    $ps->close();
    if ($trxShown === '' && !empty($paymentRow['gateway_reference'])) {
        $trxShown = (string)$paymentRow['gateway_reference'];
    }
}

// Timeline from persisted order_events only (never mark future stages complete).
$steps = [];
if ($order) {
    foreach ($events as $ev) {
        $steps[] = [
            'label' => $ev['message'],
            'time' => date('M j, Y g:i A', strtotime($ev['created_at'])),
            'state' => 'done',
        ];
    }
    if ($steps) {
        $steps[count($steps) - 1]['state'] = 'current';
    } else {
        $steps[] = [
            'label' => 'Order placed',
            'time' => date('M j, Y', strtotime($order['created_at'])),
            'state' => 'current',
        ];
    }
}
?>
<?php require_once __DIR__ . '/../includes/header.php'; ?>

<?php if ($justPaid && $order && ($order['payment_status'] ?? '') === 'paid'): ?>
    <div class="card confirmation-banner">
        <h1 class="page-title" style="display:flex;align-items:center;gap:.55rem;"><?= ui_icon('check-circle', 'icn icn-lg') ?>Payment verified</h1>
        <p>Order <strong><?= e($order['reference_code']) ?></strong> is paid. Vendors can now accept and fulfil their items.</p>
        <?php if ($trxShown !== ''): ?><p class="text-muted">Paystack reference: <code><?= e($trxShown) ?></code></p><?php endif; ?>
    </div>
<?php elseif ($payFailed && $order): ?>
    <div class="card" style="border-color:#c45c5c;">
        <h1 class="page-title">Payment not completed</h1>
        <p>We could not verify a successful payment for <strong><?= e($order['reference_code']) ?></strong>. You can try again below. The order stays unpaid until server-side verification succeeds.</p>
        <?php if ($trxShown !== ''): ?><p class="text-muted">Last Paystack reference: <code><?= e($trxShown) ?></code></p><?php endif; ?>
    </div>
<?php elseif ($justPlaced && $order): ?>
    <div class="card confirmation-banner">
        <h1 class="page-title" style="display:flex;align-items:center;gap:.55rem;"><?= ui_icon('check-circle', 'icn icn-lg') ?>Order received</h1>
        <p>Your reference is <strong><?= e($order['reference_code']) ?></strong>.
          <?php if ($payMode === 'paystack' && ($order['payment_status'] ?? '') !== 'paid'): ?>
            Complete payment with Paystack to start vendor fulfilment.
          <?php else: ?>
            Payment is <strong>pending</strong> until it is verified<?= $payMode === 'paystack' ? '' : ' (campus confirmation)' ?>.
          <?php endif; ?>
          Keep this page or the private tracking link; the order number alone is not enough to view the order.</p>
        <?php if (empty($order['confirmation_sent'])): ?>
            <p class="text-muted">A confirmation email could not be sent from this server. Save the tracking link below.</p>
        <?php else: ?>
            <p class="text-muted">A confirmation email was sent to <?= e($order['customer_email']) ?>.</p>
        <?php endif; ?>
    </div>
<?php else: ?>
    <h1 class="page-title">Track Order</h1>
<?php endif; ?>

<?php if ($lookupError): ?>
    <div class="form-errors"><ul><li><?= e($lookupError) ?></li></ul></div>
<?php endif; ?>

<?php if (!$order): ?>
    <div class="card" style="max-width:560px;">
        <div class="card-title">Find your order</div>
        <p class="text-muted mb-2">
          Use the private tracking link from checkout (recommended), or enter the order reference plus the secure tracking token from that link.
          The order number alone is never enough. Registered buyers can also open orders from <a href="<?= APP_BASE_URL ?>/buyer/orders.php">My orders</a>.
        </p>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <div class="form-group">
                <label for="reference">Order reference</label>
                <input type="text" id="reference" name="reference" required maxlength="20" value="<?= e($reference) ?>" autocomplete="off">
            </div>
            <div class="form-group">
                <label for="token">Tracking token</label>
                <input type="text" id="token" name="token" required maxlength="128" value="<?= e($token) ?>" autocomplete="off" spellcheck="false">
            </div>
            <button class="btn btn-primary" type="submit">Check status</button>
        </form>
    </div>
<?php else: ?>
    <div class="page-bar">
        <div>
            <h2 class="page-title" style="font-size:1.4rem;margin:0;">Order <?= e($order['reference_code']) ?></h2>
            <p class="text-muted">Placed <?= e(date('M j, Y', strtotime($order['created_at']))) ?></p>
        </div>
        <?= ui_badge((string)$order['payment_status'], ucfirst($order['payment_status']) . ' payment') ?>
    </div>

    <div class="card order-meta">
        <div>
            <div class="meta-label">Placed</div>
            <div style="font-weight:600;"><?= e(date('M j, Y', strtotime($order['created_at']))) ?></div>
        </div>
        <div>
            <div class="meta-label">Payment</div>
            <?= ui_badge((string)$order['payment_status'], null, false) ?>
        </div>
        <div>
            <div class="meta-label">Fulfilment</div>
            <?= ui_badge((string)$order['status']) ?>
        </div>
        <div>
            <div class="meta-label">Total</div>
            <div style="font-weight:600;color:var(--forest);font-variant-numeric:tabular-nums;"><?= format_currency($order['total'], $paymentRow['currency'] ?? null) ?></div>
        </div>
        <div>
            <div class="meta-label">Customer</div>
            <div><?= e($order['customer_name']) ?><?= $order['buyer_id'] ? '' : ' (guest)' ?></div>
        </div>
    </div>

    <?php if ($order['payment_status'] === 'pending' || $order['payment_status'] === 'failed'): ?>
      <div class="card" style="margin-bottom:1rem;">
        <div class="card-title">Payment</div>
        <p class="text-muted">Payment is not verified yet. Fulfilment cannot start until payment is verified on the server.</p>
        <?php if (!empty($paymentRow['gateway_reference'])): ?>
          <p>Paystack reference: <code><?= e($paymentRow['gateway_reference']) ?></code></p>
        <?php endif; ?>
        <?php if ($payMode === 'paystack' && order_access_granted($order, $token, '')): ?>
          <a class="btn btn-primary" href="<?= APP_BASE_URL ?>/public/pay.php?ref=<?= rawurlencode($order['reference_code']) ?><?= $token !== '' ? '&t=' . rawurlencode($token) : '' ?>">
            <?= $order['payment_status'] === 'failed' ? 'Retry payment' : 'Pay now' ?>
          </a>
        <?php elseif ($payMode !== 'paystack'): ?>
          <p class="text-muted">Online Paystack checkout is not configured. An administrator can confirm campus payment.</p>
        <?php endif; ?>
      </div>
    <?php elseif ($order['payment_status'] === 'paid'): ?>
      <p class="text-muted">Payment is confirmed<?= !empty($paymentRow['gateway_reference']) ? ' (Paystack ref <code>' . e($paymentRow['gateway_reference']) . '</code>)' : '' ?>. Vendors will accept and prepare their own items on this order.</p>
    <?php endif; ?>

    <div class="two-col">
        <div class="card">
            <div class="card-title">Progress</div>
            <?= ui_timeline($steps) ?>
            <p class="text-muted" style="font-size:.8rem;margin-top:.5rem;">Timeline shows recorded events only.</p>
        </div>
        <div class="card">
            <div class="card-title">Delivery</div>
            <?php if ($order['delivery_address']): ?>
                <div class="meta-label">Address</div>
                <p><?= nl2br(e($order['delivery_address'])) ?></p>
            <?php else: ?>
                <p class="text-muted">No delivery address was provided. The vendor will coordinate handover.</p>
            <?php endif; ?>
            <?php if ($order['order_notes']): ?>
                <div class="meta-label" style="margin-top:1rem;">Notes</div>
                <p><?= nl2br(e($order['order_notes'])) ?></p>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <div class="card-title">Items</div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Product</th><th>Vendor</th><th>Qty</th><th>Vendor progress</th><th>Subtotal</th></tr></thead>
                <tbody>
                    <?php foreach ($items as $item): ?>
                    <tr>
                        <td>
                            <div class="checkout-line">
                                <?= ui_thumb($item['image'] ?? null, $item['title']) ?>
                                <div style="font-weight:600;"><?= e($item['title']) ?></div>
                            </div>
                        </td>
                        <td><?= e($item['seller'] ?? 'Vendor') ?></td>
                        <td><?= (int)$item['quantity'] ?></td>
                        <td><?= ui_badge((string)($item['fulfilment_status'] ?? 'pending'), fulfilment_label((string)($item['fulfilment_status'] ?? 'pending')), false) ?></td>
                        <td style="font-weight:600;color:var(--forest);font-variant-numeric:tabular-nums;"><?= format_currency((float)$item['unit_price'] * (int)$item['quantity'], $paymentRow['currency'] ?? null) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="summary-total"><span class="l">Order total</span><span class="v"><?= format_currency($order['total'], $paymentRow['currency'] ?? null) ?></span></div>
    </div>
    <?php $canSubmitDispute = $order['payment_status'] === 'paid'; $trackingContext = true; $trackingToken = $token; require __DIR__ . '/../includes/order_financial_panel.php'; ?>
    <?php if ($order['payment_status'] === 'pending' && $order['status'] === 'pending'): ?>
        <form method="POST" class="card">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="reference" value="<?= e($order['reference_code']) ?>">
            <?php if ($token !== ''): ?><input type="hidden" name="token" value="<?= e($token) ?>"><?php endif; ?>
            <p class="text-muted mb-2">Payment has not been confirmed. Cancelling releases the reserved stock.</p>
            <button class="btn btn-danger" type="submit" name="cancel_order" value="1" data-confirm="Cancel this unpaid order? This releases the reserved stock.">Cancel unpaid order</button>
        </form>
    <?php endif; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
