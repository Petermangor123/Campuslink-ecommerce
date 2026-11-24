<?php
require_once __DIR__ . '/../includes/auth_guard.php';
require_once __DIR__ . '/../includes/ui.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/order_service.php';
require_role('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'reconcile') {
            $id = (int)($_POST['refund_id'] ?? 0);
            $result = refresh_paystack_refund($conn, $id);
            if (empty($result['ok'])) { throw new RuntimeException((string)($result['error'] ?? 'Paystack could not be reached.')); }
        } elseif ($action === 'complete') {
            complete_manual_refund($conn, (int)($_POST['refund_id'] ?? 0), trim((string)($_POST['external_reference'] ?? '')), current_user_id());
        } elseif ($action === 'fail') {
            $id = (int)($_POST['refund_id'] ?? 0);
            $refund = $conn->query('SELECT provider FROM refunds WHERE id=' . $id)->fetch_assoc();
            if (($refund['provider'] ?? '') === 'paystack') { throw new RuntimeException('Paystack refunds can only be failed by a verified provider response.'); }
            apply_refund_status($conn, $id, 'failed', trim((string)($_POST['failure_reason'] ?? 'Refund could not be sent.')));
        }
        set_flash('success', 'Refund updated.');
    } catch (Throwable $e) {
        set_flash('error', $e->getMessage());
    }
    redirect(APP_BASE_URL . '/admin/refunds.php');
}

$status = trim((string)($_GET['status'] ?? ''));
$allowedStatuses = ['pending', 'processing', 'needs_attention', 'processed', 'failed', 'cancelled'];
if (!in_array($status, $allowedStatuses, true)) { $status = ''; }
$rows = $conn->query(
    "SELECT r.*,o.reference_code,o.customer_name,p.gateway_reference AS transaction_reference
     FROM refunds r INNER JOIN orders o ON o.id=r.order_id INNER JOIN payments p ON p.id=r.payment_id"
    . ($status !== '' ? " WHERE r.status='" . $conn->real_escape_string($status) . "'" : '')
    . ' ORDER BY r.id DESC LIMIT 500'
)->fetch_all(MYSQLI_ASSOC);

if (($_GET['export'] ?? '') === 'csv') {
    stream_financial_csv('campuslink-refunds.csv', ['Refund ID','Order','Customer','Amount minor','Currency','Status','Provider','Reason','Gateway refund ID','Gateway reference','External reference','Requested at','Processed at'], array_map(static fn($r) => [$r['id'],$r['reference_code'],$r['customer_name'],$r['amount_minor'],$r['currency'],$r['status'],$r['provider'],$r['reason'],$r['gateway_refund_id'],$r['gateway_reference'],$r['external_reference'],$r['requested_at'],$r['processed_at']], $rows));
}

$dashTitle = 'Refunds';
$dashSub = 'Partial and full refunds with provider reconciliation';
?>
<?php require_once __DIR__ . '/../includes/header.php'; ?>
<div class="page-bar"><div><h1 class="page-title">Refunds</h1><p class="text-muted">Refunds reserve vendor entitlement until processed or released.</p></div><a class="btn btn-secondary btn-sm" href="?export=csv">Export CSV</a></div>
<div class="card">
  <form method="GET" class="inline-form" style="margin-bottom:1rem;">
    <select name="status" class="select-inline" aria-label="Filter refunds"><option value="">All statuses</option><?php foreach ($allowedStatuses as $s): ?><option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= ui_status_label($s) ?></option><?php endforeach; ?></select>
    <button class="btn btn-secondary btn-sm">Filter</button>
  </form>
  <div class="table-wrap"><table>
    <thead><tr><th>Order</th><th>Amount</th><th>Status</th><th>Provider / reason</th><th>Gateway</th><th>Action</th></tr></thead>
    <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><a href="<?= APP_BASE_URL ?>/admin/order_view.php?id=<?= (int)$r['order_id'] ?>"><?= e($r['reference_code']) ?></a><br><span class="text-muted"><?= e($r['customer_name']) ?></span></td>
          <td><?= e(format_currency(minor_to_money((int)$r['amount_minor']), $r['currency'])) ?></td>
          <td><?= ui_badge((string)$r['status']) ?></td>
          <td><?= e($r['provider']) ?><br><span class="text-muted"><?= e($r['reason']) ?></span></td>
          <td><code><?= e($r['gateway_refund_id'] ?: $r['gateway_reference'] ?: '—') ?></code><?php if ($r['failure_reason']): ?><br><span class="text-muted"><?= e($r['failure_reason']) ?></span><?php endif; ?></td>
          <td>
            <?php if ($r['provider'] === 'paystack' && ($r['gateway_refund_id'] || $r['gateway_reference']) && !in_array($r['status'], ['processed','failed','cancelled'], true)): ?>
              <form method="POST"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="reconcile"><input type="hidden" name="refund_id" value="<?= (int)$r['id'] ?>"><button class="btn btn-secondary btn-sm">Refresh status</button></form>
            <?php elseif ($r['provider'] !== 'paystack' && !in_array($r['status'], ['processed','failed','cancelled'], true)): ?>
              <form method="POST" class="inline-form"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="complete"><input type="hidden" name="refund_id" value="<?= (int)$r['id'] ?>"><input name="external_reference" required maxlength="100" placeholder="Reference" aria-label="External refund reference"><button class="btn btn-secondary btn-sm">Mark sent</button></form>
              <form method="POST" style="margin-top:.35rem;"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="fail"><input type="hidden" name="refund_id" value="<?= (int)$r['id'] ?>"><input name="failure_reason" required maxlength="500" placeholder="Failure reason" aria-label="Failure reason"><button class="btn btn-danger btn-sm">Mark failed</button></form>
            <?php else: ?>—<?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="6"><?= ui_empty('refresh','No refunds','No refunds match this filter.', '', true) ?></td></tr><?php endif; ?>
    </tbody>
  </table></div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
