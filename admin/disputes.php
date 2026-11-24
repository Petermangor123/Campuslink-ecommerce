<?php
require_once __DIR__ . '/../includes/auth_guard.php';
require_once __DIR__ . '/../includes/ui.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/order_service.php';
require_role('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        resolve_order_dispute($conn, (int)($_POST['dispute_id'] ?? 0), (string)($_POST['resolution_action'] ?? ''), trim((string)($_POST['note'] ?? '')), current_user_id());
        set_flash('success', 'Dispute updated.');
    } catch (Throwable $e) {
        set_flash('error', $e->getMessage());
    }
    redirect(APP_BASE_URL . '/admin/disputes.php');
}

$rows = $conn->query(
    "SELECT d.*,o.reference_code,o.customer_name,o.customer_email,r.id AS refund_id,r.status AS refund_status
     FROM disputes d INNER JOIN orders o ON o.id=d.order_id LEFT JOIN refunds r ON r.id=d.refund_id
     ORDER BY d.id DESC LIMIT 500"
)->fetch_all(MYSQLI_ASSOC);
if (($_GET['export'] ?? '') === 'csv') {
    stream_financial_csv('campuslink-disputes.csv', ['Dispute ID','Order','Customer','Email','Amount minor','Currency','Status','Reason','Gateway dispute ID','Resolution','Message','Resolution note','Created at','Resolved at'], array_map(static fn($d) => [$d['id'],$d['reference_code'],$d['customer_name'],$d['customer_email'],$d['disputed_amount_minor'],$d['currency'],$d['status'],$d['reason'],$d['gateway_dispute_id'],$d['resolution'],$d['customer_message'],$d['resolution_note'],$d['created_at'],$d['resolved_at']], $rows));
}
$open = count(array_filter($rows, static fn($d) => in_array($d['status'], ['open','under_review'], true)));
$dashTitle = 'Disputes & Chargebacks';
$dashSub = 'Customer claims and Paystack chargeback intake';
?>
<?php require_once __DIR__ . '/../includes/header.php'; ?>
<div class="page-bar"><div><h1 class="page-title">Disputes &amp; chargebacks</h1><p class="text-muted">Open disputes hold affected vendor lines from settlement.</p></div><a class="btn btn-secondary btn-sm" href="?export=csv">Export CSV</a></div>
<div class="stats-row"><?= ui_stat((string)$open, 'Open disputes', 'shield', 'accent') ?></div>
<div class="card"><div class="table-wrap"><table><thead><tr><th>Order / customer</th><th>Amount</th><th>Status / source</th><th>Claim</th><th>Resolution</th></tr></thead><tbody>
  <?php foreach ($rows as $d): ?>
    <tr>
      <td><a href="<?= APP_BASE_URL ?>/admin/order_view.php?id=<?= (int)$d['order_id'] ?>"><?= e($d['reference_code']) ?></a><br><?= e($d['customer_name']) ?><br><span class="text-muted"><?= e($d['customer_email']) ?></span></td>
      <td><?= e(format_currency(minor_to_money((int)$d['disputed_amount_minor']), $d['currency'])) ?></td>
      <td><?= ui_badge((string)$d['status']) ?><br><span class="text-muted"><?= $d['gateway_dispute_id'] ? 'Paystack #' . e($d['gateway_dispute_id']) : 'Customer submission' ?></span></td>
      <td><strong><?= e($d['reason']) ?></strong><br><?= nl2br(e($d['customer_message'] ?: 'No customer message.')) ?></td>
      <td>
        <?php if (in_array($d['status'], ['open','under_review'], true)): ?>
          <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="dispute_id" value="<?= (int)$d['id'] ?>">
            <select name="resolution_action" required><option value="review">Move to review</option><option value="resolve">Resolve</option><option value="reject">Reject</option></select>
            <textarea name="note" required maxlength="2000" rows="2" placeholder="Internal note / resolution"></textarea>
            <button class="btn btn-secondary btn-sm">Update dispute</button>
          </form>
          <p class="text-muted" style="font-size:.8rem;">If money is owed, use the order page to create and process a refund.</p>
        <?php else: ?>
          <?= e($d['resolution'] ?: $d['status']) ?><br><?= nl2br(e($d['resolution_note'] ?: $d['admin_note'] ?: '—')) ?>
          <?php if ($d['refund_id']): ?><br><a href="<?= APP_BASE_URL ?>/admin/refunds.php">Refund #<?= (int)$d['refund_id'] ?> (<?= e($d['refund_status']) ?>)</a><?php endif; ?>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="5"><?= ui_empty('shield','No disputes','No customer disputes or gateway chargebacks are recorded.', '', true) ?></td></tr><?php endif; ?>
</tbody></table></div></div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
