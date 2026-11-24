<?php
// CampusLink – Seller: manage fulfilment for own line items only
require_once __DIR__ . '/../includes/auth_guard.php';
require_once __DIR__ . '/../includes/ui.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/order_service.php';
require_role('seller');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
}

$uid = current_user_id();
$id = (int)($_GET['id'] ?? $_POST['order_id'] ?? 0);

if (!seller_can_access_order($conn, $id, $uid)) {
    set_flash('error', 'You can only open orders that include your products.');
    redirect(APP_BASE_URL . '/seller/orders.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $itemId = (int)($_POST['item_id'] ?? 0);
    $action = (string)($_POST['action'] ?? '');
    $reason = (string)($_POST['reason'] ?? '');
    // Never accept payment mutations from vendors.
    if (in_array($action, ['paid', 'failed', 'payment'], true)) {
        set_flash('error', 'Vendors cannot change payment status.');
        redirect(APP_BASE_URL . '/seller/order_view.php?id=' . $id);
    }
    $message = apply_vendor_item_action($conn, $uid, $itemId, $action, $reason);
    set_flash($message === '' ? 'success' : 'error', $message === '' ? 'Fulfilment updated.' : $message);
    redirect(APP_BASE_URL . '/seller/order_view.php?id=' . $id);
}

$order = order_by_id($conn, $id);
$items = seller_order_items($conn, $id, $uid);
$events = order_events($conn, $id);
$payCur = $conn->prepare('SELECT currency FROM payments WHERE order_id=? LIMIT 1');
$payCur->bind_param('i', $id);
$payCur->execute();
$paymentCurrency = $payCur->get_result()->fetch_assoc()['currency'] ?? null;
$payCur->close();

$dashTitle = $order['reference_code'] ?: ('Order #' . $id);
$dashSub = 'Your items only · payment ' . fulfilment_label((string)$order['payment_status']);
$dashActions = '<a class="btn btn-secondary btn-sm" href="' . APP_BASE_URL . '/seller/orders.php">'
    . ui_icon('arrow-left', 'icn-sm') . ' Back</a>';
?>
<?php require_once __DIR__ . '/../includes/header.php'; ?>

<div class="stats-row">
  <?= ui_stat(ui_status_label((string)$order['payment_status']), 'Payment', 'card') ?>
  <?= ui_stat(ui_status_label((string)$order['status']), 'Order rollup', 'truck') ?>
  <?= ui_stat((string)count($items), 'Your lines', 'package') ?>
</div>

<div class="two-col">
  <div class="card">
    <div class="card-title">Customer &amp; delivery</div>
    <p><strong><?= e($order['customer_name'] ?: 'Customer') ?></strong></p>
    <p class="text-muted"><?= e($order['customer_email'] ?: '') ?></p>
    <p class="text-muted"><?= e($order['customer_phone'] ?: 'No phone') ?></p>
    <p><?= e($order['delivery_address'] ?: 'No delivery address on file') ?></p>
    <?php if ($order['payment_status'] !== 'paid'): ?>
      <p class="text-muted" style="margin-top:.75rem;">Fulfilment unlocks after payment is confirmed. Vendors cannot mark a payment as paid.</p>
    <?php endif; ?>
  </div>
  <div class="card">
    <div class="card-title">Activity</div>
    <?php
      $steps = [];
      foreach ($events as $ev) {
          $steps[] = [
              'label' => $ev['message'],
              'time' => date('M j, g:i A', strtotime($ev['created_at'])),
              'state' => 'done',
          ];
      }
      if (!$steps) {
          echo '<p class="text-muted">No events yet.</p>';
      } else {
          echo ui_timeline($steps);
      }
    ?>
  </div>
</div>

<div class="card">
  <div class="card-title">Your line items</div>
  <div class="table-wrap">
    <table>
      <thead>
        <tr><th>Product</th><th>Qty</th><th>Unit</th><th>Fulfilment</th><th>Actions</th></tr>
      </thead>
      <tbody>
        <?php foreach ($items as $item): ?>
          <?php $fs = (string)$item['fulfilment_status']; ?>
          <tr>
            <td><?= e($item['title']) ?></td>
            <td class="num"><?= (int)$item['quantity'] ?></td>
            <td class="num"><?= format_currency($item['unit_price'], $paymentCurrency) ?></td>
            <td>
              <?= ui_badge($fs, fulfilment_label($fs), false) ?>
              <?php if ($fs === 'rejected' && !empty($item['rejection_reason'])): ?>
                <div class="text-muted" style="font-size:.82rem;"><?= e($item['rejection_reason']) ?></div>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($order['payment_status'] === 'paid' && $fs === 'awaiting_acceptance'): ?>
                <form method="POST" class="inline-form" style="display:inline;">
                  <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                  <input type="hidden" name="order_id" value="<?= $id ?>">
                  <input type="hidden" name="item_id" value="<?= (int)$item['id'] ?>">
                  <input type="hidden" name="action" value="accept">
                  <button class="btn btn-primary btn-sm" data-confirm="Accept this line?">Accept</button>
                </form>
                <form method="POST" style="margin-top:.4rem;">
                  <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                  <input type="hidden" name="order_id" value="<?= $id ?>">
                  <input type="hidden" name="item_id" value="<?= (int)$item['id'] ?>">
                  <input type="hidden" name="action" value="reject">
                  <label class="sr-only" for="reason-<?= (int)$item['id'] ?>">Rejection reason</label>
                  <input id="reason-<?= (int)$item['id'] ?>" name="reason" required maxlength="500" placeholder="Reason for rejection" style="min-width:12rem;">
                  <button class="btn btn-secondary btn-sm" data-confirm="Reject this line and restore stock?">Reject</button>
                </form>
              <?php elseif ($order['payment_status'] === 'paid' && $fs === 'accepted'): ?>
                <form method="POST" class="inline-form">
                  <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                  <input type="hidden" name="order_id" value="<?= $id ?>">
                  <input type="hidden" name="item_id" value="<?= (int)$item['id'] ?>">
                  <input type="hidden" name="action" value="processing">
                  <button class="btn btn-secondary btn-sm">Start processing</button>
                </form>
              <?php elseif ($order['payment_status'] === 'paid' && in_array($fs, ['accepted', 'processing'], true)): ?>
                <form method="POST" class="inline-form" style="display:inline;">
                  <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                  <input type="hidden" name="order_id" value="<?= $id ?>">
                  <input type="hidden" name="item_id" value="<?= (int)$item['id'] ?>">
                  <input type="hidden" name="action" value="ready_for_pickup">
                  <button class="btn btn-secondary btn-sm">Ready for pickup</button>
                </form>
                <form method="POST" class="inline-form" style="display:inline;">
                  <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                  <input type="hidden" name="order_id" value="<?= $id ?>">
                  <input type="hidden" name="item_id" value="<?= (int)$item['id'] ?>">
                  <input type="hidden" name="action" value="shipped">
                  <button class="btn btn-primary btn-sm" data-confirm="Mark as shipped/dispatched?">Mark shipped</button>
                </form>
              <?php elseif ($order['payment_status'] === 'paid' && $fs === 'ready_for_pickup'): ?>
                <form method="POST" class="inline-form">
                  <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                  <input type="hidden" name="order_id" value="<?= $id ?>">
                  <input type="hidden" name="item_id" value="<?= (int)$item['id'] ?>">
                  <input type="hidden" name="action" value="shipped">
                  <button class="btn btn-primary btn-sm">Mark shipped</button>
                </form>
              <?php else: ?>
                <span class="text-muted">—</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
