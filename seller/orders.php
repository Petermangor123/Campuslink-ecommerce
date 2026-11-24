<?php
// CampusLink – Orders received by seller (own products only)
require_once __DIR__ . '/../includes/auth_guard.php';
require_once __DIR__ . '/../includes/ui.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/order_service.php';

require_role('seller');

$uid = current_user_id();
$filter = (string)($_GET['filter'] ?? 'paid');
$allowedFilters = ['paid', 'awaiting', 'active', 'done', 'unpaid', 'all'];
if (!in_array($filter, $allowedFilters, true)) {
    $filter = 'paid';
}

$where = ['EXISTS (SELECT 1 FROM order_items own WHERE own.order_id=o.id AND own.seller_id=?)'];
$types = 'i';
$params = [$uid];
if ($filter === 'unpaid') {
    $where[] = "o.payment_status IN ('pending','failed','cancelled')";
} elseif ($filter !== 'all') {
    $where[] = "o.payment_status='paid'";
}
if ($filter === 'awaiting') {
    $where[] = "EXISTS (SELECT 1 FROM order_items x WHERE x.order_id=o.id AND x.seller_id=? AND x.fulfilment_status='awaiting_acceptance')";
    $types .= 'i';
    $params[] = $uid;
} elseif ($filter === 'active') {
    $where[] = "EXISTS (SELECT 1 FROM order_items x WHERE x.order_id=o.id AND x.seller_id=? AND x.fulfilment_status IN ('accepted','processing','ready_for_pickup','shipped'))";
    $types .= 'i';
    $params[] = $uid;
} elseif ($filter === 'done') {
    $where[] = "EXISTS (SELECT 1 FROM order_items x WHERE x.order_id=o.id AND x.seller_id=? AND x.fulfilment_status IN ('delivered','rejected','cancelled'))";
    $types .= 'i';
    $params[] = $uid;
}
$types .= 'iii';
$params[] = $uid;
$params[] = $uid;
$params[] = $uid;

$sql = 'SELECT o.id, o.buyer_id, o.reference_code, o.created_at, o.status, o.payment_status,
            pm.currency, pm.refund_status,
            COALESCE(u.name, o.customer_name, \'Guest\') AS buyer_name,
            (SELECT COUNT(*) FROM order_items oi2 WHERE oi2.order_id=o.id AND oi2.seller_id=?) AS my_lines,
            (SELECT COUNT(*) FROM order_items oi3 WHERE oi3.order_id=o.id AND oi3.seller_id=? AND oi3.fulfilment_status=\'awaiting_acceptance\') AS needs_accept,
            (SELECT COALESCE(SUM(ra.seller_net_reversal_minor),0)
             FROM refund_allocations ra
             INNER JOIN refunds r ON r.id=ra.refund_id
             INNER JOIN order_items roi ON roi.id=ra.order_item_id
             WHERE roi.order_id=o.id AND roi.seller_id=? AND r.status=\'processed\') AS seller_refund_debits_minor,
            (SELECT COUNT(*) FROM disputes d WHERE d.order_id=o.id AND d.status IN (\'open\',\'under_review\')) AS active_disputes
     FROM orders o
     LEFT JOIN users u ON u.id=o.buyer_id
     LEFT JOIN payments pm ON pm.order_id=o.id
     WHERE ' . implode(' AND ', $where) . '
     ORDER BY o.created_at DESC';

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$orders = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$dashTitle = 'Orders';
$dashSub   = count($orders) . ' order' . (count($orders) === 1 ? '' : 's') . ' with your products';
?>
<?php require_once __DIR__ . '/../includes/header.php'; ?>

<div class="page-bar" style="margin-bottom:1rem;">
  <div class="chip-row" style="display:flex;gap:.5rem;flex-wrap:wrap;">
    <?php foreach (['paid' => 'Paid', 'awaiting' => 'Needs acceptance', 'active' => 'In progress', 'done' => 'Completed / closed', 'unpaid' => 'Unpaid / not captured', 'all' => 'All incl. unpaid'] as $key => $label): ?>
      <a class="btn btn-sm <?= $filter === $key ? 'btn-primary' : 'btn-secondary' ?>"
         href="?filter=<?= e($key) ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
  </div>
</div>
<p class="text-muted" style="margin-top:-.5rem;margin-bottom:1rem;">
  Paid orders are shown by default. Unpaid orders appear only through an explicit filter and are not earned revenue.
</p>

<?php if (empty($orders)): ?>
    <div class="card">
        <?= ui_empty(
              'orders',
              'No orders in this view',
              'When a customer purchases one of your products and payment is confirmed, fulfilment actions appear here.',
              '<a href="' . APP_BASE_URL . '/seller/my_products.php" class="btn btn-secondary btn-sm">' . ui_icon('package', 'icn-sm') . ' My Products</a>'
            ) ?>
    </div>
<?php else: ?>
    <div class="card" style="padding:0;">
        <div class="table-wrap">
            <table>
                <thead>
                    <tr><th>Order</th><th>Buyer</th><th>Date</th><th>Your lines</th><th>Payment</th><th>Order status</th><th></th></tr>
                </thead>
                <tbody>
                    <?php foreach ($orders as $o): ?>
                    <tr>
                        <td style="font-weight:600;color:var(--forest);"><?= htmlspecialchars($o['reference_code'] ?: ('#' . $o['id'])) ?></td>
                        <td>
                            <?= htmlspecialchars($o['buyer_name']) ?>
                            <?php if ($o['buyer_id'] === null): ?> <?= ui_badge('neutral', 'Guest') ?><?php endif; ?>
                        </td>
                        <td class="text-muted"><?= date('M j, Y', strtotime($o['created_at'])) ?></td>
                        <td class="num">
                          <?= (int)$o['my_lines'] ?>
                          <?php if ((int)$o['needs_accept'] > 0): ?>
                            <?= ui_badge('warning', 'Accept', false) ?>
                          <?php endif; ?>
                        </td>
                        <td>
                          <?= ui_badge((string)$o['payment_status'], null, false) ?>
                          <?php if ((string)($o['refund_status'] ?? 'none') !== 'none'): ?>
                            <div style="margin-top:.25rem;">
                              <?= ui_badge((string)$o['refund_status'], null, false) ?>
                              <?php if ((int)($o['seller_refund_debits_minor'] ?? 0) > 0): ?>
                                <span class="text-muted" style="font-size:.76rem;"><?= format_currency(minor_to_money((int)$o['seller_refund_debits_minor']), $o['currency'] ?? null) ?> your refund debit</span>
                              <?php endif; ?>
                            </div>
                          <?php endif; ?>
                          <?php if ((int)($o['active_disputes'] ?? 0) > 0): ?>
                            <div style="margin-top:.25rem;"><?= ui_badge('open', 'Dispute', false) ?></div>
                          <?php endif; ?>
                        </td>
                        <td><?= ui_badge((string)$o['status']) ?></td>
                        <td>
                          <a class="btn btn-secondary btn-sm" href="<?= APP_BASE_URL ?>/seller/order_view.php?id=<?= (int)$o['id'] ?>">Manage</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
