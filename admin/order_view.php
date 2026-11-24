<?php
// CampusLink – Admin: order detail
require_once __DIR__ . '/../includes/auth_guard.php';
require_once __DIR__ . '/../includes/ui.php';
require_once __DIR__ . '/../config/db.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST') { verify_csrf(); }
require_role('admin');
require_once __DIR__ . '/../includes/order_service.php';
require_once __DIR__ . '/../includes/order_service.php';

$id = (int)($_GET['id'] ?? $_POST['order_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'create_refund') {
            $refund = request_refund($conn, $id, money_to_minor($_POST['amount'] ?? 0), trim((string)($_POST['reason'] ?? '')), current_user_id(), 'admin:' . bin2hex(random_bytes(12)));
            if ($refund['provider'] === 'paystack') {
                $result = submit_paystack_refund($conn, (int)$refund['id']);
                if (empty($result['ok'])) {
                    throw new RuntimeException((string)($result['error'] ?? 'Refund submission needs reconciliation.'));
                }
            }
            set_flash('success', 'Refund recorded. Track its status in Refunds.');
        } elseif ($action === 'complete_refund') {
            complete_manual_refund($conn, (int)($_POST['refund_id'] ?? 0), trim((string)($_POST['external_reference'] ?? '')), current_user_id());
            set_flash('success', 'Manual refund marked processed.');
        } elseif ($action === 'reconcile_refund') {
            $rid = (int)($_POST['refund_id'] ?? 0);
            $result = refresh_paystack_refund($conn, $rid);
            if (empty($result['ok'])) { throw new RuntimeException((string)($result['error'] ?? 'Paystack could not be reached.')); }
            set_flash('success', 'Paystack refund status refreshed.');
        } elseif (isset($_POST['status'])) {
            $message = apply_admin_order_action($conn, $id, (string)$_POST['status']);
            if ($message !== '') { throw new RuntimeException($message); }
            set_flash('success', 'Order status updated.');
        }
    } catch (Throwable $e) {
        set_flash('error', $e->getMessage());
    }
    redirect(APP_BASE_URL . '/admin/order_view.php?id=' . $id);
}

$order = order_by_id($conn, $id);
if (!$order) {
    set_flash('error', 'Order not found.');
    redirect(APP_BASE_URL . '/admin/orders.php');
}

$items = order_line_items($conn, $id);

$pay = $conn->prepare('SELECT status, provider, amount, gateway_reference, currency, verified_at, created_at, updated_at, refund_status, refunded_amount_minor FROM payments WHERE order_id = ? LIMIT 1');
$pay->bind_param('i', $id);
$pay->execute();
$payment = $pay->get_result()->fetch_assoc();
$pay->close();
$refunds = refunds_for_order($conn, $id);
$disputes = disputes_for_order($conn, $id);
$events = order_events($conn, $id);
$awaitingVendors = 0;
foreach ($items as $it) {
    if (($it['fulfilment_status'] ?? '') === 'awaiting_acceptance') {
        $awaitingVendors++;
    }
}

$buyer = null;
$buyerName = $order['customer_name'] ?: 'Guest';
if (!empty($order['buyer_id'])) {
    $b = $conn->prepare('SELECT id, name, email FROM users WHERE id = ? LIMIT 1');
    $bid = (int)$order['buyer_id'];
    $b->bind_param('i', $bid);
    $b->execute();
    $buyer = $b->get_result()->fetch_assoc() ?: null;
    $b->close();
    if ($buyer) {
        $buyerName = $buyer['name'];
    }
}

$dashTitle = $order['reference_code'] ?: ('Order #' . $order['id']);
$dashSub   = 'Placed ' . date('M j, Y g:i A', strtotime($order['created_at']));
$dashActions = '<a class="btn btn-secondary btn-sm" href="' . APP_BASE_URL . '/admin/orders.php">'
             . ui_icon('arrow-left', 'icn-sm') . ' Back to orders</a>';
?>
<?php require_once __DIR__ . '/../includes/header.php'; ?>

<div class="stats-row">
  <?= ui_stat(format_currency($order['total'], $payment['currency'] ?? null), 'Order total', 'dollar', 'success') ?>
  <?= ui_stat(ui_status_label((string)$order['payment_status']), 'Payment', 'card') ?>
  <?= ui_stat(ui_status_label((string)$order['status']), 'Fulfilment', 'truck') ?>
</div>

<div class="two-col">
  <div class="card">
    <div class="card-title">Buyer &amp; delivery</div>
    <div class="table-wrap">
      <table>
        <tbody>
          <tr><th>Buyer</th>
            <td>
              <?php if (!empty($buyer['id'])): ?>
                <a href="<?= APP_BASE_URL ?>/admin/user_view.php?id=<?= (int)$buyer['id'] ?>"><?= htmlspecialchars($buyerName) ?></a>
              <?php else: ?>
                <?= htmlspecialchars($buyerName) ?> <span class="text-muted">(guest)</span>
              <?php endif; ?>
            </td>
          </tr>
          <tr><th>Email</th><td><?= htmlspecialchars($order['customer_email'] ?: ($buyer['email'] ?? '—')) ?></td></tr>
          <tr><th>Phone</th><td><?= htmlspecialchars($order['customer_phone'] ?: '—') ?></td></tr>
          <tr><th>Address</th><td><?= htmlspecialchars($order['delivery_address'] ?: '—') ?></td></tr>
          <tr><th>Notes</th><td><?= htmlspecialchars($order['order_notes'] ?: '—') ?></td></tr>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card">
    <div class="card-title">Payment &amp; status</div>
    <div class="table-wrap">
      <table>
        <tbody>
          <tr><th>Reference</th><td><?= htmlspecialchars($order['reference_code'] ?: '—') ?></td></tr>
          <tr><th>Payment</th><td><?= ui_badge((string)$order['payment_status'], null, false) ?></td></tr>
          <tr><th>Fulfilment</th><td><?= ui_badge((string)$order['status']) ?></td></tr>
          <tr><th>Provider</th><td><?= htmlspecialchars($payment['provider'] ?? 'manual_review') ?></td></tr>
          <tr><th>Gateway ref</th><td><?= htmlspecialchars($payment['gateway_reference'] ?? '—') ?></td></tr>
          <tr><th>Currency (ISO)</th><td><?= htmlspecialchars($payment['currency'] ?? app_currency_iso()) ?>
            <?php if (!empty($payment['currency']) && strtoupper((string)$payment['currency']) === 'GHS'): ?>
              <span class="text-muted">(display <?= e(currency_display_label('GHS')) ?>)</span>
            <?php elseif (!empty($payment['currency']) && strtoupper((string)$payment['currency']) !== 'GHS'): ?>
              <span class="text-muted">(historical — amounts not relabelled as GHC)</span>
            <?php endif; ?>
          </td></tr>
          <tr><th>Verified at</th><td><?= !empty($payment['verified_at']) ? date('M j, Y g:i A', strtotime($payment['verified_at'])) : '—' ?></td></tr>
          <tr><th>Payment amount</th><td><?= format_currency($payment['amount'] ?? $order['total'], $payment['currency'] ?? null) ?></td></tr>
          <tr><th>Refund</th><td><?= ui_badge((string)($payment['refund_status'] ?? 'none')) ?> · <?= format_currency(minor_to_money((int)($payment['refunded_amount_minor'] ?? 0)), $payment['currency'] ?? null) ?> processed</td></tr>
          <tr><th>Awaiting vendors</th><td><?= (int)$awaitingVendors ?> line(s)</td></tr>
          <tr><th>Created</th><td><?= date('M j, Y g:i A', strtotime($order['created_at'])) ?></td></tr>
        </tbody>
      </table>
    </div>
    <p class="text-muted" style="font-size:.82rem;margin-top:.75rem;">
      <?php if (($payment['provider'] ?? '') === 'paystack'): ?>
        This order uses Paystack. Payment is recorded only after Paystack verifies it. You can cancel a pending order, but you cannot mark it paid by hand.
      <?php else: ?>
        Campus payments are confirmed here. Marking paid records the payment and lets vendors accept their items.
      <?php endif; ?>
      Prefer vendors updating their own lines. Use shipped or delivered here only for exceptions.
    </p>
    <form method="POST" class="inline-form" style="margin-top:1rem;">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="order_id" value="<?= (int)$order['id'] ?>">
      <label class="sr-only" for="status">Update status</label>
      <select id="status" name="status" class="select-inline">
        <?php foreach (['paid', 'failed', 'shipped', 'delivered', 'cancelled'] as $s): ?>
          <option value="<?= $s ?>" <?= $order['status'] === $s ? 'selected' : '' ?>><?= ui_status_label($s) ?></option>
        <?php endforeach; ?>
      </select>
      <button class="btn btn-primary btn-sm" data-confirm="Update this order’s status?">Update status</button>
    </form>
  </div>
</div>

<div class="two-col">
  <div class="card">
    <div class="card-title">Refund operations</div>
    <?php if (($payment['status'] ?? '') === 'paid'): ?>
      <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="create_refund">
        <input type="hidden" name="order_id" value="<?= (int)$id ?>">
        <div class="form-group"><label for="refund_amount">Amount (<?= e(currency_display_label($payment['currency'] ?? null)) ?>)</label><input id="refund_amount" type="number" name="amount" min="0.01" step="0.01" max="<?= e($payment['amount']) ?>" required></div>
        <div class="form-group"><label for="refund_reason">Reason</label><input id="refund_reason" name="reason" required maxlength="500"></div>
        <button class="btn btn-danger btn-sm" data-confirm="Create this refund? Paystack refunds are sent immediately.">Request refund</button>
      </form>
    <?php else: ?><p class="text-muted">Only paid orders can be refunded.</p><?php endif; ?>
    <?php foreach ($refunds as $refund): ?>
      <p style="margin-top:.75rem;"><?= ui_badge((string)$refund['status']) ?> <?= e(format_currency(minor_to_money((int)$refund['amount_minor']), $refund['currency'])) ?><br><span class="text-muted"><?= e($refund['reason']) ?></span></p>
      <?php if ($refund['provider'] !== 'paystack' && !in_array($refund['status'], ['processed','failed','cancelled'], true)): ?>
        <form method="POST" class="inline-form">
          <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="complete_refund"><input type="hidden" name="refund_id" value="<?= (int)$refund['id'] ?>">
          <input name="external_reference" required maxlength="100" placeholder="External refund reference" aria-label="External refund reference"><button class="btn btn-secondary btn-sm">Mark sent</button>
        </form>
      <?php elseif ($refund['provider'] === 'paystack' && (!empty($refund['gateway_refund_id']) || !empty($refund['gateway_reference'])) && !in_array($refund['status'], ['processed','failed','cancelled'], true)): ?>
        <form method="POST"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="reconcile_refund"><input type="hidden" name="refund_id" value="<?= (int)$refund['id'] ?>"><button class="btn btn-secondary btn-sm">Refresh Paystack status</button></form>
      <?php endif; ?>
    <?php endforeach; ?>
    <p><a href="<?= APP_BASE_URL ?>/admin/refunds.php">All refunds &amp; export</a></p>
  </div>
  <div class="card">
    <div class="card-title">Disputes</div>
    <?php foreach ($disputes as $dispute): ?>
      <p><?= ui_badge((string)$dispute['status']) ?> <?= e($dispute['reason']) ?><br><?= nl2br(e($dispute['customer_message'] ?: $dispute['admin_note'] ?: '—')) ?></p>
    <?php endforeach; ?>
    <?php if (!$disputes): ?><p class="text-muted">No disputes recorded.</p><?php endif; ?>
    <p><a href="<?= APP_BASE_URL ?>/admin/disputes.php">Review disputes</a></p>
  </div>
</div>

<div class="card">
  <div class="card-title">Line items &amp; vendor fulfilment</div>
  <?php if (empty($items)): ?>
    <p class="text-muted">No line items.</p>
  <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Product</th><th>Vendor</th><th>Qty</th><th>Unit</th><th>Fulfilment</th><th>Line</th><th>Commission</th><th>Seller net</th></tr></thead>
        <tbody>
          <?php foreach ($items as $item): ?>
          <tr>
            <td>
              <?php if (!empty($item['product_id'])): ?>
                <a href="<?= APP_BASE_URL ?>/public/product.php?id=<?= (int)$item['product_id'] ?>"><?= htmlspecialchars($item['title'] ?? ('#' . $item['product_id'])) ?></a>
              <?php else: ?>
                <?= htmlspecialchars($item['title'] ?? 'Item') ?>
              <?php endif; ?>
            </td>
            <td>
              <?php
                $sid = (int)($item['seller_id'] ?: 0);
              ?>
              <?php if ($sid > 0): ?>
                <a href="<?= APP_BASE_URL ?>/admin/vendor_view.php?id=<?= $sid ?>"><?= htmlspecialchars($item['seller'] ?? $item['seller_name'] ?? 'Vendor') ?></a>
              <?php else: ?>
                <?= htmlspecialchars($item['seller'] ?? $item['seller_name'] ?? '—') ?>
              <?php endif; ?>
            </td>
            <td class="num"><?= (int)$item['quantity'] ?></td>
            <td class="num"><?= format_currency($item['unit_price'], $payment['currency'] ?? null) ?></td>
            <td><?= ui_badge((string)($item['fulfilment_status'] ?? ''), fulfilment_label((string)($item['fulfilment_status'] ?? '')), false) ?></td>
            <td class="num"><?= format_currency((float)$item['unit_price'] * (int)$item['quantity'], $payment['currency'] ?? null) ?></td>
            <td class="num"><?= format_currency(minor_to_money((int)$item['commission_amount_minor']), $payment['currency'] ?? null) ?></td>
            <td class="num"><?= format_currency(minor_to_money((int)$item['seller_net_amount_minor']), $payment['currency'] ?? null) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<div class="card">
  <div class="card-title">Event log</div>
  <?php
    $steps = [];
    foreach ($events as $ev) {
        $steps[] = [
            'label' => '[' . $ev['actor_role'] . '] ' . $ev['message'],
            'time' => date('M j, g:i A', strtotime($ev['created_at'])),
            'state' => 'done',
        ];
    }
    echo $steps ? ui_timeline($steps) : '<p class="text-muted">No events recorded.</p>';
  ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
