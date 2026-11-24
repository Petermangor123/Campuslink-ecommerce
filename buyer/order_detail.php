<?php
// CampusLink – Buyer order detail + delivery confirmation
require_once __DIR__ . '/../includes/auth_guard.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/order_service.php';
require_once __DIR__ . '/../includes/order_service.php';
require_once __DIR__ . '/../includes/ui.php';
require_role('buyer');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
}

$uid = current_user_id();
$oid = (int)($_GET['id'] ?? $_POST['order_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'confirm_delivery') {
    // Buyers cannot set payment status.
    $message = apply_buyer_confirm_delivery($conn, $uid, $oid);
    set_flash($message === '' ? 'success' : 'error', $message === '' ? 'Thanks — delivery confirmed.' : $message);
    redirect(APP_BASE_URL . '/buyer/order_detail.php?id=' . $oid);
}

$stmt = $conn->prepare('SELECT * FROM orders WHERE id=? AND buyer_id=?');
$stmt->bind_param('ii', $oid, $uid);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$order) {
    set_flash('error', 'Order not found.');
    redirect(APP_BASE_URL . '/buyer/orders.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'open_dispute') {
    $rate = sec_rate('dispute-buyer:' . $uid, 5, 600, true);
    if ($rate['limited']) {
        set_flash('error', 'Too many dispute submissions. Please try again later.');
    } else {
        try {
            submit_order_dispute($conn, $oid, (string)($_POST['reason'] ?? ''), (string)($_POST['message'] ?? ''), $uid);
            set_flash('success', 'Your dispute was submitted for review.');
        } catch (Throwable $e) {
            set_flash('error', $e->getMessage());
        }
    }
    redirect(APP_BASE_URL . '/buyer/order_detail.php?id=' . $oid);
}

$items = order_line_items($conn, $oid);
$events = order_events($conn, $oid);
$refunds = refunds_for_order($conn, $oid);
$disputes = disputes_for_order($conn, $oid);
$payCurStmt = $conn->prepare('SELECT currency, refund_status, refunded_amount_minor FROM payments WHERE order_id=? LIMIT 1');
$payCurStmt->bind_param('i', $oid);
$payCurStmt->execute();
$payment = $payCurStmt->get_result()->fetch_assoc() ?: [];
$paymentCurrency = $payment['currency'] ?? null;
$payCurStmt->close();
$canConfirm = false;
foreach ($items as $i) {
    if (in_array((string)$i['fulfilment_status'], ['shipped', 'ready_for_pickup'], true)) {
        $canConfirm = true;
        break;
    }
}

$steps = [];
foreach ($events as $ev) {
    $steps[] = [
        'label' => $ev['message'],
        'time' => date('M j, Y g:i A', strtotime($ev['created_at'])),
        'state' => 'done',
    ];
}
if ($steps) {
    $steps[count($steps) - 1]['state'] = 'current';
}
?>
<?php require_once __DIR__ . '/../includes/header.php'; ?>

<div class="mb-2">
    <?= ui_breadcrumbs([
        ['My Orders', APP_BASE_URL . '/buyer/orders.php'],
        ['Order ' . ($order['reference_code'] ?: '#' . $order['id']), null],
    ]) ?>
</div>

<div class="page-bar">
    <div>
        <h1 class="page-title">Order <?= e($order['reference_code'] ?: ('#' . $order['id'])) ?></h1>
        <p class="text-muted">Placed <?= e(date('M j, Y', strtotime($order['created_at']))) ?></p>
    </div>
    <div style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;">
      <?= ui_badge((string)$order['payment_status'], 'Payment: ' . ui_status_label((string)$order['payment_status']), false) ?>
      <?= ui_badge((string)$order['status'], 'Order: ' . ui_status_label((string)$order['status'])) ?>
    </div>
</div>

<div class="card order-meta">
    <div>
        <div class="meta-label">Date placed</div>
        <div style="font-weight:600;"><?= e(date('M j, Y', strtotime($order['created_at']))) ?></div>
    </div>
    <div>
        <div class="meta-label">Order total</div>
        <div style="font-weight:600;color:var(--forest);font-variant-numeric:tabular-nums;"><?= format_currency($order['total'], $paymentCurrency) ?></div>
    </div>
    <div>
        <div class="meta-label">Payment</div>
        <?= ui_badge((string)$order['payment_status']) ?>
    </div>
    <div>
        <div class="meta-label">Fulfilment</div>
        <?= ui_badge((string)$order['status'], null, false) ?>
    </div>
</div>
<?php if ($order['payment_status'] === 'pending'): ?>
  <p class="text-muted" style="font-size:.85rem;margin:-.5rem 0 1.25rem;">Payment is still pending campus confirmation. Vendors cannot prepare your items until payment is verified.</p>
<?php elseif ($order['payment_status'] === 'paid'): ?>
  <p class="text-muted" style="font-size:.85rem;margin:-.5rem 0 1.25rem;">Payment is confirmed. Each vendor manages only their own products on this order.</p>
<?php endif; ?>

<div class="card">
    <div class="card-title">Items ordered</div>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Product</th><th>Vendor</th><th>Qty</th><th>Unit</th><th>Vendor progress</th><th>Subtotal</th></tr></thead>
            <tbody>
                <?php foreach ($items as $i): ?>
                <tr>
                    <td>
                        <div class="checkout-line">
                            <?= ui_thumb($i['image'] ?? null, $i['title']) ?>
                            <div style="font-weight:600;"><?= e($i['title']) ?></div>
                        </div>
                    </td>
                    <td><?= e($i['seller'] ?? 'Vendor') ?></td>
                    <td><?= (int)$i['quantity'] ?></td>
                    <td class="text-muted"><?= format_currency($i['unit_price'], $paymentCurrency) ?></td>
                    <td><?= ui_badge((string)$i['fulfilment_status'], fulfilment_label((string)$i['fulfilment_status']), false) ?></td>
                    <td style="font-weight:600;color:var(--forest);font-variant-numeric:tabular-nums;">
                        <?= format_currency($i['unit_price'] * $i['quantity'], $paymentCurrency) ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="summary-total"><span class="l">Order total</span><span class="v"><?= format_currency($order['total'], $paymentCurrency) ?></span></div>
    <?php if ($paymentCurrency && strtoupper((string)$paymentCurrency) !== 'GHS' && strtoupper((string)$paymentCurrency) !== 'GHC'): ?>
      <p class="text-muted" style="font-size:.8rem;margin-top:.35rem;">Recorded payment currency: <?= e(strtoupper((string)$paymentCurrency)) ?> (historical — not relabelled as GHC).</p>
    <?php endif; ?>
    <?php if ($canConfirm): ?>
      <form method="POST" style="margin-top:1rem;">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="order_id" value="<?= $oid ?>">
        <input type="hidden" name="action" value="confirm_delivery">
        <button class="btn btn-primary" data-confirm="Confirm that you have received these items?">Confirm receipt</button>
      </form>
    <?php endif; ?>
</div>

<?php $canSubmitDispute = $order['payment_status'] === 'paid'; require __DIR__ . '/../includes/order_financial_panel.php'; ?>

<div class="two-col">
    <div class="card">
        <div class="card-title">Order timeline</div>
        <?php if ($steps): ?>
          <?= ui_timeline($steps) ?>
        <?php else: ?>
          <p class="text-muted">Waiting for the first status update.</p>
        <?php endif; ?>
        <p class="text-muted" style="font-size:.8rem;margin-top:.25rem;">Only real events from CampusLink are shown — nothing is marked complete early.</p>
    </div>

    <div class="card">
        <div class="card-title">Delivery</div>
        <?php if (!empty($order['delivery_address'])): ?>
            <div class="meta-label">Address</div>
            <p><?= nl2br(e($order['delivery_address'])) ?></p>
        <?php else: ?>
            <p class="text-muted">No delivery address was provided. The seller will coordinate pickup or handover with you.</p>
        <?php endif; ?>
        <?php if (!empty($order['order_notes'])): ?>
            <div class="meta-label" style="margin-top:1rem;">Notes</div>
            <p><?= nl2br(e($order['order_notes'])) ?></p>
        <?php endif; ?>
        <p class="text-muted" style="margin-top:1rem;font-size:.85rem;">
          Need help? Contact CampusLink support or the vendor listed on each item. You cannot mark payment as paid yourself.
        </p>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
