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
        if ($action === 'create') {
            create_vendor_payout($conn, (int)($_POST['seller_id'] ?? 0), money_to_minor($_POST['amount'] ?? 0), trim((string)($_POST['method'] ?? '')), trim((string)($_POST['note'] ?? '')), strtoupper(trim((string)($_POST['settlement_reference'] ?? ''))), current_user_id());
        } elseif ($action === 'complete') {
            apply_payout_action($conn, (int)($_POST['payout_id'] ?? 0), 'complete', trim((string)($_POST['external_reference'] ?? '')), current_user_id());
        } elseif ($action === 'void') {
            apply_payout_action($conn, (int)($_POST['payout_id'] ?? 0), 'void', trim((string)($_POST['void_reason'] ?? '')), current_user_id());
        }
        set_flash('success', 'Settlement updated.');
    } catch (Throwable $e) {
        set_flash('error', $e->getMessage());
    }
    redirect(APP_BASE_URL . '/admin/settlements.php');
}

$sellers = $conn->query("SELECT id,name,email FROM users WHERE role='seller' ORDER BY name")->fetch_all(MYSQLI_ASSOC);
$sellerId = (int)($_GET['seller_id'] ?? 0);
$selected = null;
foreach ($sellers as $seller) { if ((int)$seller['id'] === $sellerId) { $selected = $seller; break; } }
$summary = $sellerId > 0 ? seller_financial_summary($conn, $sellerId) : null;
$lines = $sellerId > 0 ? payout_available_lines($conn, $sellerId) : [];
$rows = $conn->query(
    "SELECT p.*,u.name AS seller_name FROM payouts p INNER JOIN users u ON u.id=p.seller_id ORDER BY p.id DESC LIMIT 500"
)->fetch_all(MYSQLI_ASSOC);

if (($_GET['export'] ?? '') === 'csv') {
    $export = array_map(static fn($row) => ['payout', $row['seller_name'], $row['id'], $row['amount_minor'], $row['currency'], $row['status'], $row['method'], $row['external_reference'], $row['settlement_reference'], $row['created_at']], $rows);
    if ($sellerId > 0) {
        $export = array_map(static fn($line) => ['available_line', $line['reference_code'], $line['id'], $line['available_minor'], app_currency_iso(), 'available', '', '', '', $line['created_at'] ?? ''], $lines);
    }
    stream_financial_csv('campuslink-settlements.csv', ['Type','Seller / order','Payout / line ID','Amount minor','Currency','Status','Method','External reference','Settlement reference','Created at'], $export);
}

$dashTitle = 'Vendor Settlements';
$dashSub = 'Offline payout records; no automatic gateway transfers';
?>
<?php require_once __DIR__ . '/../includes/header.php'; ?>
<div class="page-bar"><div><h1 class="page-title">Vendor settlements</h1><p class="text-muted">Only delivered, paid, undisputed lines are eligible. Pending payouts reserve funds.</p></div><a class="btn btn-secondary btn-sm" href="?export=csv<?= $sellerId ? '&seller_id=' . $sellerId : '' ?>">Export CSV</a></div>
<div class="two-col">
  <div class="card">
    <div class="card-title">Create settlement</div>
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="create">
      <div class="form-group"><label for="seller_id">Vendor</label><select id="seller_id" name="seller_id" required><option value="">Choose vendor</option><?php foreach ($sellers as $seller): ?><option value="<?= (int)$seller['id'] ?>" <?= $sellerId === (int)$seller['id'] ? 'selected' : '' ?>><?= e($seller['name']) ?> (<?= e($seller['email']) ?>)</option><?php endforeach; ?></select></div>
      <div class="form-group"><label for="settlement_reference">Unique settlement reference</label><input id="settlement_reference" name="settlement_reference" required minlength="8" maxlength="100" pattern="[A-Za-z0-9_-]+" placeholder="SET-2026-0001"></div>
      <div class="form-group"><label for="amount">Amount (<?= e(currency_display_label(app_currency_iso())) ?>)</label><input id="amount" name="amount" type="number" min="0.01" step="0.01" required></div>
      <div class="form-group"><label for="method">Payment method</label><input id="method" name="method" required maxlength="50" placeholder="Bank transfer / campus cash"></div>
      <div class="form-group"><label for="note">Note</label><textarea id="note" name="note" maxlength="500" rows="2"></textarea></div>
      <button class="btn btn-primary">Create pending payout</button>
    </form>
    <?php if ($selected): ?>
      <p style="margin-top:1rem;"><a href="<?= APP_BASE_URL ?>/admin/vendor_view.php?id=<?= $sellerId ?>">Open <?= e($selected['name']) ?>'s vendor record</a></p>
    <?php endif; ?>
  </div>
  <div class="card">
    <div class="card-title">Vendor balance</div>
    <form method="GET" class="inline-form"><select class="select-inline" name="seller_id" aria-label="Vendor balance"><option value="">Choose vendor</option><?php foreach ($sellers as $seller): ?><option value="<?= (int)$seller['id'] ?>" <?= $sellerId === (int)$seller['id'] ? 'selected' : '' ?>><?= e($seller['name']) ?></option><?php endforeach; ?></select><button class="btn btn-secondary btn-sm">View</button></form>
    <?php if ($summary): ?>
      <div class="settle-grid">
        <div class="settle-item"><div class="k">Gross paid sales</div><div class="v"><?= e(format_currency($summary['gross_paid_sales'])) ?></div></div>
        <div class="settle-item"><div class="k">Commissions</div><div class="v"><?= e(format_currency($summary['commissions'])) ?></div></div>
        <div class="settle-item"><div class="k">Seller refund debits</div><div class="v"><?= e(format_currency($summary['seller_refund_debits'])) ?></div></div>
        <div class="settle-item"><div class="k">Net sales</div><div class="v"><?= e(format_currency($summary['net_sales'])) ?></div></div>
        <div class="settle-item"><div class="k">Completed payouts</div><div class="v"><?= e(format_currency($summary['vendor_payouts'])) ?></div></div>
        <div class="settle-item"><div class="k">Pending payouts</div><div class="v"><?= e(format_currency($summary['pending_payouts'])) ?></div></div>
        <div class="settle-item"><div class="k">Delivered eligible balance</div><div class="v"><?= e(format_currency($summary['outstanding_settlement'])) ?></div></div>
        <div class="settle-item"><div class="k">Recovery debt</div><div class="v"><?= e(format_currency($summary['recovery_debt'])) ?></div></div>
      </div>
      <h3 style="font-size:1rem;margin-top:1rem;">Eligible lines (FIFO)</h3>
      <?php foreach ($lines as $line): if ((int)$line['available_minor'] < 1) { continue; } ?><p><?= e($line['reference_code']) ?> · line #<?= (int)$line['id'] ?> · <strong><?= e(format_currency(minor_to_money((int)$line['available_minor']))) ?></strong><?= !empty($line['disputed']) ? ' · dispute hold' : '' ?></p><?php endforeach; ?>
    <?php else: ?><p class="text-muted">Choose a vendor to calculate the eligible balance.</p><?php endif; ?>
  </div>
</div>
<div class="card" style="margin-top:1rem;">
  <div class="card-title">Payout history</div>
  <div class="table-wrap"><table><thead><tr><th>Reference</th><th>Vendor</th><th>Amount</th><th>Status</th><th>Method / note</th><th>External reference</th><th>Action</th></tr></thead><tbody>
    <?php foreach ($rows as $row): ?><tr><td><?= e($row['settlement_reference']) ?></td><td><?= e($row['seller_name']) ?></td><td><?= e(format_currency(minor_to_money((int)$row['amount_minor']), $row['currency'])) ?></td><td><?= ui_badge((string)$row['status']) ?></td><td><?= e($row['method']) ?><br><span class="text-muted"><?= e($row['note']) ?></span></td><td><?= e($row['external_reference'] ?: '—') ?></td><td>
      <?php if ($row['status'] === 'pending'): ?>
        <form method="POST" class="inline-form"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="complete"><input type="hidden" name="payout_id" value="<?= (int)$row['id'] ?>"><input name="external_reference" required maxlength="100" placeholder="Payment reference" aria-label="External payment reference"><button class="btn btn-secondary btn-sm">Complete</button></form>
        <form method="POST" class="inline-form" style="margin-top:.35rem;"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="void"><input type="hidden" name="payout_id" value="<?= (int)$row['id'] ?>"><input name="void_reason" required maxlength="500" placeholder="Void reason" aria-label="Void reason"><button class="btn btn-danger btn-sm">Void</button></form>
      <?php else: ?>—<?php endif; ?>
    </td></tr><?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="7"><?= ui_empty('dollar','No settlements','Create a pending payout to begin.', '', true) ?></td></tr><?php endif; ?>
  </tbody></table></div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
