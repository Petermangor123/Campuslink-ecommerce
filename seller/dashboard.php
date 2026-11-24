<?php
// CampusLink – Seller Dashboard
require_once __DIR__ . '/../includes/auth_guard.php';
require_once __DIR__ . '/../includes/ui.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/order_service.php';
require_role('seller');

$uid = current_user_id();

$s = $conn->prepare('SELECT COUNT(*) AS c FROM products WHERE seller_id=?');
$s->bind_param('i', $uid); $s->execute();
$product_count = (int)$s->get_result()->fetch_assoc()['c']; $s->close();

$s = $conn->prepare('SELECT COUNT(*) AS c FROM products WHERE seller_id=? AND stock > 0');
$s->bind_param('i', $uid); $s->execute();
$active_count = (int)$s->get_result()->fetch_assoc()['c']; $s->close();

$s = $conn->prepare('SELECT COUNT(*) AS c FROM products WHERE seller_id=? AND stock=0');
$s->bind_param('i', $uid); $s->execute();
$oos = (int)$s->get_result()->fetch_assoc()['c']; $s->close();

$s = $conn->prepare(
    "SELECT COUNT(DISTINCT o.id) AS c FROM order_items oi
     INNER JOIN orders o ON o.id = oi.order_id
     WHERE oi.seller_id=? AND o.payment_status='paid' AND oi.fulfilment_status='awaiting_acceptance'"
);
$s->bind_param('i', $uid); $s->execute();
$new_orders = (int)$s->get_result()->fetch_assoc()['c']; $s->close();

$s = $conn->prepare(
    "SELECT COUNT(*) AS c FROM order_items oi
     INNER JOIN orders o ON o.id = oi.order_id
     WHERE oi.seller_id=? AND o.payment_status='paid'
       AND oi.fulfilment_status IN ('accepted','processing','ready_for_pickup','shipped')"
);
$s->bind_param('i', $uid); $s->execute();
$processing = (int)$s->get_result()->fetch_assoc()['c']; $s->close();

$currency = app_payment_currency();
$finance = seller_financial_summary($conn, $uid, $currency);

$s = $conn->prepare(
    "SELECT oi.id, oi.quantity, oi.unit_price, oi.fulfilment_status, oi.product_id,
            p.title, p.image, o.id AS order_id, o.reference_code, o.created_at, o.payment_status
     FROM order_items oi
     INNER JOIN orders o ON o.id = oi.order_id
     LEFT JOIN products p ON p.id = oi.product_id
     WHERE oi.seller_id=?
     ORDER BY o.created_at DESC, oi.id DESC
     LIMIT 8"
);
$s->bind_param('i', $uid); $s->execute();
$recentOrders = $s->get_result()->fetch_all(MYSQLI_ASSOC); $s->close();

$s = $conn->prepare('SELECT id,title,price,stock,image,created_at FROM products WHERE seller_id=? ORDER BY created_at DESC LIMIT 6');
$s->bind_param('i', $uid); $s->execute();
$recent = $s->get_result()->fetch_all(MYSQLI_ASSOC); $s->close();

$dashTitle   = 'Overview';
$dashSub     = 'Vendor Dashboard';
$dashActions = '<a href="' . APP_BASE_URL . '/seller/add_product.php" class="btn btn-primary btn-sm">'
             . ui_icon('plus', 'icn-sm') . ' Add Product</a>';
?>
<?php require_once __DIR__ . '/../includes/header.php'; ?>

<div class="dash-welcome">
  <div>
    <h2>Welcome back, <?= e(current_user_name()) ?></h2>
    <p>Manage your products, fulfil paid orders, and review your proceeds after commissions, refunds and payouts. CampusLink does not transfer funds automatically.</p>
  </div>
</div>

<div class="stats-row">
  <?= ui_stat((string)$product_count, 'Products', 'tag') ?>
  <?= ui_stat((string)$active_count, 'In stock', 'package', 'success') ?>
  <?= ui_stat((string)$oos, 'Sold out', 'alert', 'accent') ?>
  <?= ui_stat((string)$new_orders, 'New paid orders', 'orders', 'info', 'Awaiting your acceptance') ?>
  <?= ui_stat((string)$processing, 'In progress', 'truck', '', 'Accepted → dispatched') ?>
  <?= ui_stat(format_currency($finance['net_sales'], $currency), 'Net seller proceeds', 'dollar', 'success', 'After commissions and seller refund debits') ?>
</div>

<?php
  $alerts = trim(
      ui_alert_chip(APP_BASE_URL . '/seller/orders.php?filter=awaiting', 'Orders need acceptance', $new_orders, 'warn')
    . ui_alert_chip(APP_BASE_URL . '/seller/my_products.php', 'Sold-out products', $oos, 'info')
  );
?>
<?php if ($alerts !== ''): ?>
  <div class="dash-alerts"><?= $alerts ?></div>
<?php endif; ?>

<h3 class="dash-section-title">Quick actions</h3>
<div class="dash-shortcuts">
  <?= ui_shortcut(['href' => APP_BASE_URL . '/seller/add_product.php', 'icon' => 'plus', 'label' => 'Add Product', 'hint' => 'Create a new product']) ?>
  <?= ui_shortcut(['href' => APP_BASE_URL . '/seller/my_products.php', 'icon' => 'package', 'label' => 'My Products', 'hint' => 'Edit stock and photos']) ?>
  <?= ui_shortcut(['href' => APP_BASE_URL . '/seller/orders.php', 'icon' => 'orders', 'label' => 'Orders', 'hint' => 'Your line items only']) ?>
  <?= ui_shortcut(['href' => APP_BASE_URL . '/seller/sales.php', 'icon' => 'dollar', 'label' => 'Sales & Earnings', 'hint' => 'Paid sales and settlement']) ?>
</div>

<div class="card" style="margin-bottom:var(--s-5);">
  <div class="card-title">Sales &amp; settlement</div>
  <p class="text-muted card-lead">All figures use <?= e(currency_display_label($currency)) ?> and only your own order lines. Processed refunds reduce seller proceeds; pending payouts reserve delivered funds.</p>
  <div class="settle-grid">
    <div class="settle-item"><div class="k">Gross paid sales</div><div class="v"><?= format_currency($finance['gross_paid_sales'], $currency) ?></div></div>
    <div class="settle-item"><div class="k">Commissions</div><div class="v"><?= format_currency($finance['commissions'], $currency) ?></div></div>
    <div class="settle-item"><div class="k">Buyer refunds (processed)</div><div class="v"><?= format_currency($finance['refunds_credits'], $currency) ?></div></div>
    <div class="settle-item"><div class="k">Seller refund debits</div><div class="v"><?= format_currency($finance['seller_refund_debits'], $currency) ?></div></div>
    <div class="settle-item"><div class="k">Net seller proceeds</div><div class="v"><?= format_currency($finance['net_sales'], $currency) ?></div></div>
    <div class="settle-item"><div class="k">Completed payouts</div><div class="v"><?= format_currency($finance['vendor_payouts'], $currency) ?></div></div>
    <div class="settle-item"><div class="k">Pending payouts</div><div class="v"><?= format_currency($finance['pending_payouts'], $currency) ?></div></div>
    <div class="settle-item"><div class="k">Available delivered settlement</div><div class="v"><?= format_currency($finance['outstanding_settlement'], $currency) ?></div></div>
    <div class="settle-item"><div class="k">Recovery debt</div><div class="v"><?= format_currency($finance['recovery_debt'], $currency) ?></div></div>
  </div>
  <?php if ($finance['unpaid_line_total'] > 0): ?>
    <p class="settle-note">Unpaid / failed lines (excluded from paid sales): <?= format_currency($finance['unpaid_line_total'], $currency) ?></p>
  <?php endif; ?>
  <?php if ($finance['recovery_debt'] > 0): ?>
    <p class="settle-note">Completed payouts exceed current net proceeds by <?= format_currency($finance['recovery_debt'], $currency) ?>; this is tracked as recovery debt.</p>
  <?php endif; ?>
</div>

<div class="two-col">
  <div class="card">
    <div class="card-title">Recent orders</div>
    <?php if (empty($recentOrders)): ?>
      <?= ui_empty('orders', 'No orders yet', 'When students buy your products, they appear here.', '', true) ?>
    <?php else: ?>
      <div class="table-wrap">
        <table>
          <thead>
            <tr><th>Order</th><th>Product</th><th>Qty</th><th>Date</th><th>Status</th></tr>
          </thead>
          <tbody>
            <?php foreach ($recentOrders as $row): ?>
            <tr>
              <td style="font-weight:600;">
                <a href="<?= APP_BASE_URL ?>/seller/order_view.php?id=<?= (int)$row['order_id'] ?>"><?= e($row['reference_code'] ?: ('#' . $row['order_id'])) ?></a>
                <?php if ($row['payment_status'] !== 'paid'): ?>
                  <div class="text-muted" style="font-size:.72rem;"><?= e(ui_status_label((string)$row['payment_status'])) ?> payment</div>
                <?php endif; ?>
              </td>
              <td>
                <div class="cell-media">
                  <?= ui_thumb($row['image'] ?? null, (string)($row['title'] ?? 'Item')) ?>
                  <span class="t"><?= e($row['title'] ?? 'Item') ?></span>
                </div>
              </td>
              <td class="num"><?= (int)$row['quantity'] ?></td>
              <td class="text-muted"><?= e(date('M j', strtotime($row['created_at']))) ?></td>
              <td><?= ui_badge((string)$row['fulfilment_status'], fulfilment_label((string)$row['fulfilment_status']), false) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="mt-3">
        <a href="<?= APP_BASE_URL ?>/seller/orders.php" class="btn btn-secondary btn-sm">All orders <?= ui_icon('arrow-right', 'icn-sm') ?></a>
      </div>
    <?php endif; ?>
  </div>

  <div class="card">
    <div class="card-title">Your products</div>
    <?php if (empty($recent)): ?>
      <?= ui_empty(
            'package',
            'No products yet',
            'Add your first product to start selling to students on campus.',
            '<a href="' . APP_BASE_URL . '/seller/add_product.php" class="btn btn-primary btn-sm">' . ui_icon('plus', 'icn-sm') . ' Add Product</a>',
            true
          ) ?>
    <?php else: ?>
      <div class="table-wrap">
        <table>
          <thead><tr><th>Product</th><th>Price</th><th>Stock</th><th></th></tr></thead>
          <tbody>
            <?php foreach ($recent as $p): ?>
            <tr>
              <td>
                <div class="cell-media">
                  <?= ui_thumb($p['image'] ?? null, (string)$p['title']) ?>
                  <span class="t"><?= e($p['title']) ?></span>
                </div>
              </td>
              <td class="num" style="font-weight:600;color:var(--forest);"><?= format_currency($p['price']) ?></td>
              <td>
                <?php if ((int)$p['stock'] === 0): ?>
                  <?= ui_badge('cancelled', 'Sold out') ?>
                <?php else: ?>
                  <span style="font-weight:600;"><?= (int)$p['stock'] ?></span>
                <?php endif; ?>
              </td>
              <td class="table-actions">
                <a href="<?= APP_BASE_URL ?>/public/product.php?id=<?= (int)$p['id'] ?>" class="btn btn-secondary btn-sm">View</a>
                <a href="<?= APP_BASE_URL ?>/seller/edit_product.php?id=<?= (int)$p['id'] ?>" class="btn btn-secondary btn-sm"><?= ui_icon('pencil', 'icn-sm') ?> Edit</a>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="mt-3">
        <a href="<?= APP_BASE_URL ?>/seller/my_products.php" class="btn btn-secondary btn-sm">My Products <?= ui_icon('arrow-right', 'icn-sm') ?></a>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
