<?php
// CampusLink – Admin payment / Paystack monitoring
require_once __DIR__ . '/../includes/auth_guard.php';
require_once __DIR__ . '/../includes/ui.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/payment_gateway.php';
require_once __DIR__ . '/../includes/order_service.php';
require_role('admin');

$status = (string)($_GET['status'] ?? '');
$allowed = ['', 'pending', 'paid', 'failed', 'cancelled'];
if (!in_array($status, $allowed, true)) {
    $status = '';
}

$sql = 'SELECT p.id, p.order_id, p.amount, p.status, p.provider, p.gateway_reference, p.currency,
               p.refund_status, p.refunded_amount_minor, p.verified_at, p.created_at, p.updated_at,
               o.reference_code, o.customer_name, o.customer_email, o.status AS order_status
        FROM payments p
        INNER JOIN orders o ON o.id = p.order_id';
if ($status !== '') {
    $sql .= ' WHERE p.status=?';
}
$sql .= ' ORDER BY p.created_at DESC LIMIT 200';

if ($status !== '') {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('s', $status);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} else {
    $rows = $conn->query($sql)->fetch_all(MYSQLI_ASSOC);
}

$dashTitle = 'Payments';
$dashSub = 'Paystack + campus payment monitoring · mode: ' . payment_gateway_mode();
?>
<?php require_once __DIR__ . '/../includes/header.php'; ?>

<div class="page-bar" style="margin-bottom:1rem;">
  <div style="display:flex;gap:.5rem;flex-wrap:wrap;">
    <?php foreach (['' => 'All', 'pending' => 'Pending', 'paid' => 'Paid', 'failed' => 'Failed', 'cancelled' => 'Cancelled'] as $k => $label): ?>
      <a class="btn btn-sm <?= $status === $k ? 'btn-primary' : 'btn-secondary' ?>" href="?status=<?= e($k) ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
  </div>
</div>

<p class="text-muted">
  Successfully verified Paystack payments do not need manual approval.
  Refunds are tracked separately: a captured order stays paid even when a partial or full refund is processed.
  Use order detail to investigate failures or confirm campus/offline payments when Paystack is not used.
</p>

<div class="card" style="padding:0;">
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Order</th><th>Customer</th><th>Amount</th><th>Currency</th>
          <th>Provider</th><th>Payment status</th><th>Refund</th><th>Gateway ref</th><th>Verified</th><th></th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="10" class="text-muted">No payments found.</td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td style="font-weight:600;"><?= e($r['reference_code'] ?: ('#' . $r['order_id'])) ?></td>
            <td>
              <?= e($r['customer_name'] ?: '—') ?>
              <div class="text-muted" style="font-size:.8rem;"><?= e($r['customer_email'] ?: '') ?></div>
            </td>
            <td class="num"><?= format_currency($r['amount'], $r['currency'] ?? null) ?></td>
            <td><?= e($r['currency'] ?: '—') ?><?php if (strtoupper((string)($r['currency'] ?? '')) === 'GHS'): ?> <span class="text-muted">(<?= e(currency_display_label('GHS')) ?>)</span><?php endif; ?></td>
            <td><?= e($r['provider']) ?></td>
            <td><?= ui_badge((string)$r['status'], null, false) ?></td>
            <td>
              <?php $refundStatus = (string)($r['refund_status'] ?? 'none'); ?>
              <?php $refundedMinor = (int)($r['refunded_amount_minor'] ?? 0); ?>
              <?php if ($refundStatus !== 'none' || $refundedMinor > 0): ?>
                <?= ui_badge($refundStatus, null, false) ?>
                <div class="text-muted" style="font-size:.8rem;"><?= format_currency(minor_to_money($refundedMinor), $r['currency'] ?? null) ?> processed</div>
                <a href="<?= APP_BASE_URL ?>/admin/refunds.php">Refund details</a>
              <?php else: ?>
                <span class="text-muted">—</span>
              <?php endif; ?>
            </td>
            <td><code style="font-size:.78rem;"><?= e($r['gateway_reference'] ?: '—') ?></code></td>
            <td class="text-muted"><?= $r['verified_at'] ? e(date('M j, g:i A', strtotime($r['verified_at']))) : '—' ?></td>
            <td><a class="btn btn-secondary btn-sm" href="<?= APP_BASE_URL ?>/admin/order_view.php?id=<?= (int)$r['order_id'] ?>">Order</a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
