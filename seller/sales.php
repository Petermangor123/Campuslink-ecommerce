<?php
// CampusLink – Vendor Sales & Earnings (read-only financial summary)
require_once __DIR__ . '/../includes/auth_guard.php';
require_once __DIR__ . '/../includes/ui.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/order_service.php';
require_once __DIR__ . '/../includes/order_service.php';
require_role('seller');

$uid = current_user_id();
$finance = vendor_sales_summary($conn, $uid);
$payouts = $conn->prepare('SELECT * FROM payouts WHERE seller_id=? ORDER BY id DESC');
$payouts->bind_param('i', $uid);
$payouts->execute();
$payoutRows = $payouts->get_result()->fetch_all(MYSQLI_ASSOC);
$payouts->close();
if (($_GET['export'] ?? '') === 'csv') {
    stream_financial_csv('campuslink-seller-statement.csv', ['Metric','Amount minor','Currency'], [
        ['Gross paid sales', money_to_minor($finance['gross_paid_sales']), app_currency_iso()],
        ['Commissions', money_to_minor($finance['commissions']), app_currency_iso()],
        ['Buyer refunds', money_to_minor($finance['refunds_credits']), app_currency_iso()],
        ['Seller refund debits', money_to_minor($finance['seller_refund_debits']), app_currency_iso()],
        ['Net sales', money_to_minor($finance['net_sales']), app_currency_iso()],
        ['Completed payouts', money_to_minor($finance['vendor_payouts']), app_currency_iso()],
        ['Pending payouts', money_to_minor($finance['pending_payouts']), app_currency_iso()],
        ['Delivered eligible balance', money_to_minor($finance['outstanding_settlement']), app_currency_iso()],
        ['Recovery debt', money_to_minor($finance['recovery_debt']), app_currency_iso()],
    ]);
}

$dashTitle = 'Sales & Earnings';
$dashSub   = 'Paid sales and settlement figures for your products only';
$dashActions = '<a href="' . APP_BASE_URL . '/seller/orders.php" class="btn btn-secondary btn-sm">'
             . ui_icon('orders', 'icn-sm') . ' Orders</a> <a href="?export=csv" class="btn btn-secondary btn-sm">Export statement</a>';
?>
<?php require_once __DIR__ . '/../includes/header.php'; ?>

<div class="dash-welcome">
  <div>
    <h2>Sales &amp; Earnings</h2>
    <p>Paid sales are marketplace figures from verified payments. CampusLink does not transfer funds to vendors automatically — outstanding settlement is not a bank payout.</p>
  </div>
</div>

<div class="stats-row">
  <?= ui_stat(format_currency($finance['gross_paid_sales']), 'Paid Sales', 'dollar', 'success', 'Verified payments only') ?>
  <?= ui_stat(format_currency($finance['commissions']), 'Commissions', 'alert', 'accent') ?>
  <?= ui_stat(format_currency($finance['refunds_credits']), 'Buyer Refunds', 'refresh', 'accent') ?>
  <?= ui_stat(format_currency($finance['net_sales']), 'Net Sales', 'trending', 'info') ?>
  <?= ui_stat(format_currency($finance['outstanding_settlement']), 'Outstanding Settlement', 'card', '', 'Not an automatic payout') ?>
</div>

<div class="card">
  <div class="card-title">Settlement detail</div>
  <p class="text-muted card-lead">Settlements are recorded after CampusLink pays you offline. Pending payouts reserve your delivered balance; no automatic transfer is made.</p>
  <div class="settle-grid">
    <div class="settle-item"><div class="k">Gross paid sales</div><div class="v"><?= format_currency($finance['gross_paid_sales']) ?></div></div>
    <div class="settle-item"><div class="k">Commission rate on new orders</div><div class="v"><?= number_format(marketplace_commission_bps() / 100, 2) ?>%</div></div>
    <div class="settle-item"><div class="k">Buyer refunds</div><div class="v"><?= format_currency($finance['refunds_credits']) ?></div></div>
    <div class="settle-item"><div class="k">Seller refund debits</div><div class="v"><?= format_currency($finance['seller_refund_debits']) ?></div></div>
    <div class="settle-item"><div class="k">Net sales</div><div class="v"><?= format_currency($finance['net_sales']) ?></div></div>
    <div class="settle-item"><div class="k">Completed payouts</div><div class="v"><?= format_currency($finance['vendor_payouts']) ?></div></div>
    <div class="settle-item"><div class="k">Pending payouts</div><div class="v"><?= format_currency($finance['pending_payouts']) ?></div></div>
    <div class="settle-item"><div class="k">Recovery debt</div><div class="v"><?= format_currency($finance['recovery_debt']) ?></div></div>
    <div class="settle-item"><div class="k">Outstanding settlement</div><div class="v"><?= format_currency($finance['outstanding_settlement']) ?></div></div>
    <div class="settle-item"><div class="k">Paid orders (lines)</div><div class="v"><?= (int)$finance['paid_order_count'] ?></div></div>
  </div>
  <?php if ($finance['unpaid_line_total'] > 0): ?>
    <p class="settle-note">Unpaid / failed lines (excluded from paid sales): <?= format_currency($finance['unpaid_line_total']) ?></p>
  <?php endif; ?>
</div>

<div class="card" style="margin-top:1rem;">
  <div class="card-title">Payout history</div>
  <div class="table-wrap"><table><thead><tr><th>Reference</th><th>Amount</th><th>Status</th><th>Method</th><th>External reference</th><th>Completed</th></tr></thead><tbody>
    <?php foreach ($payoutRows as $payout): ?><tr><td><?= e($payout['settlement_reference']) ?></td><td><?= format_currency(minor_to_money((int)$payout['amount_minor']), $payout['currency']) ?></td><td><?= ui_badge((string)$payout['status']) ?></td><td><?= e($payout['method']) ?></td><td><?= e($payout['external_reference'] ?: '—') ?></td><td><?= $payout['completed_at'] ? e(date('M j, Y', strtotime($payout['completed_at']))) : '—' ?></td></tr><?php endforeach; ?>
    <?php if (!$payoutRows): ?><tr><td colspan="6">No payout records yet.</td></tr><?php endif; ?>
  </tbody></table></div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
