<?php
// CampusLink – Admin reports (aggregates from live MySQL only)
require_once __DIR__ . '/../includes/auth_guard.php';
require_once __DIR__ . '/../includes/ui.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/order_service.php';
require_role('admin');

$currency = app_payment_currency();

$byRole = $conn->query(
    "SELECT role, COUNT(*) AS c, SUM(is_active=1) AS active_c FROM users GROUP BY role ORDER BY role"
)->fetch_all(MYSQLI_ASSOC);

$byCategory = $conn->query(
    "SELECT COALESCE(c.name, 'Uncategorised') AS category,
            COUNT(p.id) AS listings,
            SUM(p.stock > 0) AS in_stock,
            COALESCE(SUM(p.stock),0) AS units
     FROM products p
     LEFT JOIN categories c ON c.id = p.category_id
     GROUP BY c.id, c.name
     ORDER BY c.name"
)->fetch_all(MYSQLI_ASSOC);

$byPayment = $conn->query(
    "SELECT o.payment_status, p.currency, COUNT(*) AS c, COALESCE(SUM(o.total),0) AS amount
     FROM orders o
     INNER JOIN payments p ON p.order_id=o.id
     GROUP BY o.payment_status, p.currency
     ORDER BY o.payment_status, p.currency"
)->fetch_all(MYSQLI_ASSOC);

$byFulfilment = $conn->query(
    "SELECT o.status, p.currency, COUNT(*) AS c, COALESCE(SUM(o.total),0) AS amount
     FROM orders o
     INNER JOIN payments p ON p.order_id=o.id
     GROUP BY o.status, p.currency
     ORDER BY o.status, p.currency"
)->fetch_all(MYSQLI_ASSOC);

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
$paidRevenue = round($grossPaidSales - $processedRefunds, 2);
$sellerProceeds = round($grossPaidSales - $commissions - $sellerRefundDebits, 2);

$sellerRows = $conn->query(
    "SELECT u.id, u.name, u.email,
            (SELECT COUNT(*) FROM products p WHERE p.seller_id=u.id) AS listings
     FROM users u
     WHERE u.role='seller'"
)->fetch_all(MYSQLI_ASSOC);
$topSellers = [];
foreach ($sellerRows as $seller) {
    $summary = seller_financial_summary($conn, (int)$seller['id'], $currency);
    $topSellers[] = [
        'id' => (int)$seller['id'],
        'name' => $seller['name'],
        'email' => $seller['email'],
        'listings' => (int)$seller['listings'],
        'gross_paid_sales' => $summary['gross_paid_sales'],
        'commissions' => $summary['commissions'],
        'seller_refund_debits' => $summary['seller_refund_debits'],
        'net_sales' => $summary['net_sales'],
    ];
}
usort($topSellers, static fn($a, $b) => ($b['gross_paid_sales'] <=> $a['gross_paid_sales']) ?: ($b['listings'] <=> $a['listings']));
$topSellers = array_slice($topSellers, 0, 10);

$dashTitle = 'Analytics & Reports';
$dashSub   = 'Live marketplace figures · financial totals in ' . currency_display_label($currency);
?>
<?php require_once __DIR__ . '/../includes/header.php'; ?>

<div class="stats-row">
  <?= ui_stat(format_currency($paidRevenue, $currency), 'Net paid sales', 'dollar', 'success', 'Gross paid less processed buyer refunds') ?>
  <?= ui_stat(format_currency($commissions, $currency), 'Commissions', 'trending', 'info', 'Marketplace commission on paid lines') ?>
  <?= ui_stat(format_currency($sellerProceeds, $currency), 'Net seller proceeds', 'store', 'accent', 'After commissions and seller refund debits') ?>
  <?= ui_stat((string)array_sum(array_column($byRole, 'c')), 'Accounts', 'users') ?>
  <?= ui_stat((string)array_sum(array_map(static fn($r) => (int)$r['listings'], $byCategory)), 'Products', 'package') ?>
  <?= ui_stat((string)array_sum(array_column($byPayment, 'c')), 'Orders', 'orders') ?>
</div>
<p class="text-muted" style="margin-top:.75rem;">
  <?= e(currency_display_label($currency)) ?> financial scope: <?= format_currency($grossPaidSales, $currency) ?> gross paid,
  <?= format_currency($processedRefunds, $currency) ?> processed buyer refunds, and <?= format_currency($sellerRefundDebits, $currency) ?> seller refund debits.
</p>

<div class="two-col">
  <div class="card">
    <div class="card-title">Users by role</div>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Role</th><th>Total</th><th>Active</th></tr></thead>
        <tbody>
          <?php foreach ($byRole as $r): ?>
          <tr>
            <td><?= ui_badge((string)$r['role']) ?></td>
            <td class="num"><?= (int)$r['c'] ?></td>
            <td class="num"><?= (int)$r['active_c'] ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card">
    <div class="card-title">Products by category</div>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Category</th><th>Products</th><th>In stock</th><th>Units</th></tr></thead>
        <tbody>
          <?php foreach ($byCategory as $c): ?>
          <tr>
            <td><?= htmlspecialchars($c['category']) ?></td>
            <td class="num"><?= (int)$c['listings'] ?></td>
            <td class="num"><?= (int)$c['in_stock'] ?></td>
            <td class="num"><?= (int)$c['units'] ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<div class="two-col">
  <div class="card">
    <div class="card-title">Orders by payment (gross order value)</div>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Payment</th><th>Currency</th><th>Count</th><th>Gross order value</th></tr></thead>
        <tbody>
          <?php foreach ($byPayment as $r): ?>
          <tr>
            <td><?= ui_badge((string)$r['payment_status'], null, false) ?></td>
            <td><?= e($r['currency'] ?: '—') ?></td>
            <td class="num"><?= (int)$r['c'] ?></td>
            <td class="num"><?= format_currency($r['amount'], $r['currency'] ?: null) ?></td>
          </tr>
          <?php endforeach; ?>
          <?php if (empty($byPayment)): ?>
          <tr><td colspan="4" class="text-muted">No orders yet.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card">
    <div class="card-title">Orders by fulfilment (gross order value)</div>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Status</th><th>Currency</th><th>Count</th><th>Gross order value</th></tr></thead>
        <tbody>
          <?php foreach ($byFulfilment as $r): ?>
          <tr>
            <td><?= ui_badge((string)$r['status']) ?></td>
            <td><?= e($r['currency'] ?: '—') ?></td>
            <td class="num"><?= (int)$r['c'] ?></td>
            <td class="num"><?= format_currency($r['amount'], $r['currency'] ?: null) ?></td>
          </tr>
          <?php endforeach; ?>
          <?php if (empty($byFulfilment)): ?>
          <tr><td colspan="4" class="text-muted">No orders yet.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-title">Vendor performance (<?= e(currency_display_label($currency)) ?> paid line items)</div>
  <?php if (empty($topSellers)): ?>
    <p class="text-muted">No vendors yet.</p>
  <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Vendor</th><th>Products</th><th>Gross paid</th><th>Commissions</th><th>Seller refund debits</th><th>Net seller proceeds</th></tr></thead>
        <tbody>
          <?php foreach ($topSellers as $s): ?>
          <tr>
            <td>
              <a href="<?= APP_BASE_URL ?>/admin/vendor_view.php?id=<?= (int)$s['id'] ?>"><?= htmlspecialchars($s['name']) ?></a>
              <div class="text-muted" style="font-size:.82rem;"><?= htmlspecialchars($s['email']) ?></div>
            </td>
            <td class="num"><?= (int)$s['listings'] ?></td>
            <td class="num"><?= format_currency($s['gross_paid_sales'], $currency) ?></td>
            <td class="num"><?= format_currency($s['commissions'], $currency) ?></td>
            <td class="num"><?= format_currency($s['seller_refund_debits'], $currency) ?></td>
            <td class="num" style="font-weight:600;color:var(--forest);"><?= format_currency($s['net_sales'], $currency) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
