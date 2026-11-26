<?php
// CampusLink – Admin Dashboard
require_once __DIR__ . '/../includes/auth_guard.php';
require_once __DIR__ . '/../includes/ui.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/order_service.php';
require_role('admin');

$currency = app_payment_currency();

$user_count    = (int)$conn->query('SELECT COUNT(*) AS c FROM users')->fetch_assoc()['c'];
$buyer_count   = (int)$conn->query("SELECT COUNT(*) AS c FROM users WHERE role='buyer'")->fetch_assoc()['c'];
$seller_count  = (int)$conn->query("SELECT COUNT(*) AS c FROM users WHERE role='seller'")->fetch_assoc()['c'];
$product_count = (int)$conn->query('SELECT COUNT(*) AS c FROM products')->fetch_assoc()['c'];
$order_count   = (int)$conn->query('SELECT COUNT(*) AS c FROM orders')->fetch_assoc()['c'];
$pending_pay   = (int)$conn->query("SELECT COUNT(*) AS c FROM orders WHERE payment_status='pending'")->fetch_assoc()['c'];
$failed_pay    = (int)$conn->query("SELECT COUNT(*) AS c FROM payments WHERE status='failed'")->fetch_assoc()['c'];
$paidStmt = $conn->prepare(
    "SELECT COUNT(*) AS c FROM payments p
     INNER JOIN orders o ON o.id=p.order_id
     WHERE p.status='paid' AND o.payment_status='paid' AND p.currency=?"
);
$paidStmt->bind_param('s', $currency);
$paidStmt->execute();
$paid_orders = (int)$paidStmt->get_result()->fetch_assoc()['c'];
$paidStmt->close();

$financialStmt = $conn->prepare(
    "SELECT
        (SELECT COALESCE(SUM(p.amount),0) FROM payments p
         INNER JOIN orders o ON o.id=p.order_id
         WHERE p.status='paid' AND o.payment_status='paid' AND p.currency=?) AS gross_paid,
        (SELECT COALESCE(SUM(r.amount_minor),0) FROM refunds r
         WHERE r.status='processed' AND r.currency=?) AS refund_minor,
        (SELECT COALESCE(SUM(oi.commission_amount_minor),0) FROM order_items oi
         INNER JOIN orders o ON o.id=oi.order_id
         INNER JOIN payments p ON p.order_id=o.id
         WHERE o.payment_status='paid' AND p.status='paid' AND p.currency=?) AS commission_minor,
        (SELECT COALESCE(SUM(ra.seller_net_reversal_minor),0) FROM refund_allocations ra
         INNER JOIN refunds r ON r.id=ra.refund_id
         WHERE r.status='processed' AND r.currency=?) AS seller_refund_minor"
);
$financialStmt->bind_param('ssss', $currency, $currency, $currency, $currency);
$financialStmt->execute();
$financial = $financialStmt->get_result()->fetch_assoc();
$financialStmt->close();
$grossPaidSales = (float)$financial['gross_paid'];
$processedRefunds = minor_to_money((int)$financial['refund_minor']);
$commissions = minor_to_money((int)$financial['commission_minor']);
$sellerRefundDebits = minor_to_money((int)$financial['seller_refund_minor']);
$paid_sales = round($grossPaidSales - $processedRefunds, 2);
$seller_proceeds = round($grossPaidSales - $commissions - $sellerRefundDebits, 2);

$awaiting_fulfil = (int)$conn->query(
    "SELECT COUNT(*) AS c FROM order_items WHERE fulfilment_status='awaiting_acceptance'"
)->fetch_assoc()['c'];

$users = $conn->query(
    'SELECT id, name, email, role, is_active, created_at FROM users ORDER BY created_at DESC LIMIT 6'
)->fetch_all(MYSQLI_ASSOC);

$orders = $conn->query(
    'SELECT o.id, o.reference_code, o.total, o.status, o.payment_status, o.created_at,
            p.currency, p.refund_status, p.refunded_amount_minor,
            COALESCE(u.name, o.customer_name, \'Guest\') AS buyer
     FROM orders o
     LEFT JOIN users u ON u.id = o.buyer_id
     LEFT JOIN payments p ON p.order_id = o.id
     ORDER BY o.created_at DESC LIMIT 6'
)->fetch_all(MYSQLI_ASSOC);

$payments = $conn->query(
    "SELECT p.id, p.amount, p.status, p.provider, p.currency, p.refund_status, p.refunded_amount_minor, p.created_at,
            o.reference_code, o.id AS order_id
     FROM payments p
     INNER JOIN orders o ON o.id = p.order_id
     ORDER BY p.created_at DESC LIMIT 6"
)->fetch_all(MYSQLI_ASSOC);

$vendorActivity = $conn->query(
    "SELECT u.id, u.name, COUNT(p.id) AS listings,
            MAX(p.created_at) AS last_listed
     FROM users u
     LEFT JOIN products p ON p.seller_id = u.id
     WHERE u.role = 'seller'
     GROUP BY u.id, u.name
     ORDER BY last_listed DESC, listings DESC
     LIMIT 6"
)->fetch_all(MYSQLI_ASSOC);

$dashTitle = 'Overview';
$dashSub   = 'Marketplace control centre · ' . date('D, M j');
?>
<?php require_once __DIR__ . '/../includes/header.php'; ?>

<div class="dash-welcome">
  <div>
    <h2>Welcome, <?= e(current_user_name()) ?></h2>
    <p>Monitor users, catalogue, orders and payments. All figures come from live CampusLink records.</p>
  </div>
</div>

<div class="stats-row">
  <?= ui_stat((string)$user_count, 'Registered users', 'users', 'info') ?>
  <?= ui_stat((string)$buyer_count, 'Customers', 'user') ?>
  <?= ui_stat((string)$seller_count, 'Vendors', 'store') ?>
  <?= ui_stat((string)$product_count, 'Products', 'package') ?>
  <?= ui_stat((string)$order_count, 'Orders', 'orders', 'accent', $pending_pay . ' awaiting payment') ?>
  <?= ui_stat(format_currency($paid_sales, $currency), 'Net paid sales', 'dollar', 'success', $paid_orders . ' paid order' . ($paid_orders === 1 ? '' : 's') . ' · after refunds') ?>
  <?= ui_stat(format_currency($seller_proceeds, $currency), 'Net seller proceeds', 'store', 'info', 'After commissions and seller refund debits') ?>
</div>
<p class="text-muted" style="margin-top:.75rem;">
  Financial figures use <?= e(currency_display_label($currency)) ?> only: <?= format_currency($grossPaidSales, $currency) ?> gross paid,
  <?= format_currency($processedRefunds, $currency) ?> processed buyer refunds, and <?= format_currency($commissions, $currency) ?> commissions.
</p>

<?php
  $alerts = trim(
      ui_alert_chip(APP_BASE_URL . '/admin/orders.php?payment=pending', 'Pending payment orders', $pending_pay, 'warn')
    . ui_alert_chip(APP_BASE_URL . '/admin/payments.php?status=failed', 'Failed payments', $failed_pay, 'danger')
    . ui_alert_chip(APP_BASE_URL . '/admin/orders.php', 'Lines awaiting vendor acceptance', $awaiting_fulfil, 'info')
  );
?>
<?php if ($alerts !== ''): ?>
  <div class="dash-alerts"><?= $alerts ?></div>
<?php endif; ?>

<h3 class="dash-section-title">Management</h3>
<div class="dash-shortcuts">
  <?= ui_shortcut(['href' => APP_BASE_URL . '/admin/users.php', 'icon' => 'users', 'label' => 'Users', 'hint' => 'Customers and accounts']) ?>
  <?= ui_shortcut(['href' => APP_BASE_URL . '/admin/vendors.php', 'icon' => 'store', 'label' => 'Vendor Management', 'hint' => 'Accounts and catalogues']) ?>
  <?= ui_shortcut(['href' => APP_BASE_URL . '/admin/products.php', 'icon' => 'package', 'label' => 'Products', 'hint' => 'Marketplace catalogue']) ?>
  <?= ui_shortcut(['href' => APP_BASE_URL . '/admin/orders.php', 'icon' => 'orders', 'label' => 'Orders', 'hint' => 'Fulfilment oversight']) ?>
  <?= ui_shortcut(['href' => APP_BASE_URL . '/admin/payments.php', 'icon' => 'card', 'label' => 'Payments', 'hint' => 'Paystack and campus']) ?>
  <?= ui_shortcut(['href' => APP_BASE_URL . '/admin/reports.php', 'icon' => 'trending', 'label' => 'Analytics & Reports', 'hint' => 'Live marketplace figures']) ?>
  <?= ui_shortcut(['href' => APP_BASE_URL . '/admin/commission.php', 'icon' => 'sliders', 'label' => 'Commission', 'hint' => number_format(marketplace_commission_bps() / 100, 2) . '% on new orders']) ?>
</div>

<div class="two-col">
  <div class="card">
    <div class="card-title">Latest orders</div>
    <?php if (empty($orders)): ?>
      <?= ui_empty('orders', 'No orders yet', 'Orders placed on CampusLink will appear here.', '', true) ?>
    <?php else: ?>
      <div class="table-wrap">
        <table>
          <thead><tr><th>Order</th><th>Buyer</th><th>Total</th><th>Payment</th></tr></thead>
          <tbody>
            <?php foreach ($orders as $o): ?>
            <tr>
              <td style="font-weight:600;">
                <a href="<?= APP_BASE_URL ?>/admin/order_view.php?id=<?= (int)$o['id'] ?>"><?= e($o['reference_code'] ?: ('#' . $o['id'])) ?></a>
              </td>
              <td><?= e($o['buyer']) ?></td>
              <td class="num" style="font-weight:600;color:var(--forest);"><?= format_currency($o['total'], $o['currency'] ?? null) ?></td>
              <td>
                <?= ui_badge((string)$o['payment_status'], null, false) ?>
                <?php if ((string)($o['refund_status'] ?? 'none') !== 'none'): ?>
                  <div style="margin-top:.25rem;"><?= ui_badge((string)$o['refund_status'], null, false) ?>
                    <span class="text-muted" style="font-size:.78rem;"><?= format_currency(minor_to_money((int)($o['refunded_amount_minor'] ?? 0)), $o['currency'] ?? null) ?> processed</span>
                  </div>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="mt-3">
        <a href="<?= APP_BASE_URL ?>/admin/orders.php" class="btn btn-secondary btn-sm">All orders <?= ui_icon('arrow-right', 'icn-sm') ?></a>
      </div>
    <?php endif; ?>
  </div>

  <div class="card">
    <div class="card-title">Recent payments</div>
    <?php if (empty($payments)): ?>
      <?= ui_empty('card', 'No payments yet', 'Payment rows appear when orders are placed.', '', true) ?>
    <?php else: ?>
      <div class="table-wrap">
        <table>
          <thead><tr><th>Order</th><th>Amount</th><th>Provider</th><th>Status</th></tr></thead>
          <tbody>
            <?php foreach ($payments as $p): ?>
            <tr>
              <td style="font-weight:600;">
                <a href="<?= APP_BASE_URL ?>/admin/order_view.php?id=<?= (int)$p['order_id'] ?>"><?= e($p['reference_code'] ?: ('#' . $p['order_id'])) ?></a>
              </td>
              <td class="num"><?= format_currency($p['amount'], $p['currency'] ?? null) ?></td>
              <td class="text-muted"><?= e($p['provider']) ?></td>
              <td>
                <?= ui_badge((string)$p['status'], null, false) ?>
                <?php if ((string)($p['refund_status'] ?? 'none') !== 'none'): ?>
                  <div style="margin-top:.25rem;"><?= ui_badge((string)$p['refund_status'], null, false) ?>
                    <span class="text-muted" style="font-size:.78rem;"><?= format_currency(minor_to_money((int)($p['refunded_amount_minor'] ?? 0)), $p['currency'] ?? null) ?> processed</span>
                  </div>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="mt-3">
        <a href="<?= APP_BASE_URL ?>/admin/payments.php" class="btn btn-secondary btn-sm">Payment monitor <?= ui_icon('arrow-right', 'icn-sm') ?></a>
      </div>
    <?php endif; ?>
  </div>
</div>

<div class="two-col" style="margin-top:var(--s-5);">
  <div class="card">
    <div class="card-title">Recent registrations</div>
    <?php if (empty($users)): ?>
      <?= ui_empty('users', 'No users yet', '', '', true) ?>
    <?php else: ?>
      <div class="table-wrap">
        <table>
          <thead><tr><th>Name</th><th>Role</th><th>Joined</th></tr></thead>
          <tbody>
            <?php foreach ($users as $u): ?>
            <tr>
              <td style="font-weight:600;">
                <a href="<?= APP_BASE_URL ?>/admin/user_view.php?id=<?= (int)$u['id'] ?>"><?= e($u['name']) ?></a>
                <div class="text-muted" style="font-size:.78rem;"><?= e($u['email']) ?></div>
              </td>
              <td><?= ui_badge((string)$u['role']) ?></td>
              <td class="text-muted"><?= e(date('M j', strtotime($u['created_at']))) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="mt-3">
        <a href="<?= APP_BASE_URL ?>/admin/users.php" class="btn btn-secondary btn-sm">Manage users <?= ui_icon('arrow-right', 'icn-sm') ?></a>
      </div>
    <?php endif; ?>
  </div>

  <div class="card">
    <div class="card-title">Vendor activity</div>
    <?php if (empty($vendorActivity)): ?>
      <?= ui_empty('store', 'No vendors yet', '', '', true) ?>
    <?php else: ?>
      <div class="table-wrap">
        <table>
          <thead><tr><th>Vendor</th><th>Products</th><th>Last listed</th></tr></thead>
          <tbody>
            <?php foreach ($vendorActivity as $v): ?>
            <tr>
              <td style="font-weight:600;">
                <a href="<?= APP_BASE_URL ?>/admin/user_view.php?id=<?= (int)$v['id'] ?>"><?= e($v['name']) ?></a>
              </td>
              <td class="num"><?= (int)$v['listings'] ?></td>
              <td class="text-muted"><?= $v['last_listed'] ? e(date('M j', strtotime($v['last_listed']))) : '—' ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="mt-3">
        <a href="<?= APP_BASE_URL ?>/admin/sellers.php" class="btn btn-secondary btn-sm">Manage vendors <?= ui_icon('arrow-right', 'icn-sm') ?></a>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
