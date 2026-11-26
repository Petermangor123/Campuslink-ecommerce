<?php
require_once __DIR__ . '/../includes/auth_guard.php';
require_once __DIR__ . '/../includes/ui.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/order_service.php';
require_once __DIR__ . '/../includes/commission_settings.php';
require_role('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        $result = save_marketplace_commission_percent(
            $conn,
            (string)($_POST['commission_percent'] ?? ''),
            current_user_id()
        );
        if ($result['saved']) {
            set_flash(
                'success',
                'Commission rate saved at ' . number_format($result['bps'] / 100, 2)
                . '%. It applies to new orders only. Existing orders are unchanged.'
            );
        } else {
            set_flash('info', 'That commission rate is already the saved database rate.');
        }
    } catch (InvalidArgumentException $e) {
        set_flash('error', $e->getMessage());
    } catch (RuntimeException $e) {
        set_flash('error', $e->getMessage());
    }
    redirect(APP_BASE_URL . '/admin/commission.php');
}

$status = marketplace_commission_status($conn);
$history = $status['storage_ready'] ? marketplace_commission_history($conn) : [];
$activePercent = number_format($status['bps'] / 100, 2);
$currency = app_payment_currency();
$exampleCommission = $status['bps'] / 10;
$exampleSeller = 1000 - $exampleCommission;

$dashTitle = 'Commission';
$dashSub = 'Rate applied to new marketplace orders';
?>
<?php require_once __DIR__ . '/../includes/header.php'; ?>

<div class="page-bar">
  <div>
    <h1 class="page-title">Marketplace commission</h1>
    <p class="text-muted">The saved rate is copied onto each new order line. Paid orders, refunds, and settlements keep the rate they were given.</p>
  </div>
</div>

<div class="stats-row">
  <?= ui_stat($activePercent . '%', 'Active commission', 'sliders', 'accent', $status['source'] === 'database' ? 'Saved in the database' : 'Environment fallback') ?>
  <?= ui_stat(format_currency(1000, $currency), 'Example new order', 'dollar', 'info', 'Commission ' . format_currency($exampleCommission, $currency) . ' · seller ' . format_currency($exampleSeller, $currency)) ?>
</div>

<div class="two-col" style="margin-top:1rem;">
  <div class="card">
    <div class="card-title">Commission rate</div>
    <?php if (!$status['storage_ready']): ?>
      <p class="form-errors">Commission storage is not installed. Apply database migration 013 before saving a rate.</p>
    <?php endif; ?>
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
      <div class="form-group">
        <label for="commission_percent">Rate (%)</label>
        <input id="commission_percent" name="commission_percent" type="number" inputmode="decimal"
               min="0" max="100" step="0.01" required
               value="<?= e($activePercent) ?>"
               <?= $status['storage_ready'] ? '' : 'disabled' ?>>
        <p class="form-hint">Enter 0 to 100. Two decimal places are the smallest step (0.01% = 1 basis point). 5 means 5%.</p>
      </div>
      <button class="btn btn-primary" type="submit" <?= $status['storage_ready'] ? '' : 'disabled' ?>><?= ui_icon('save', 'icn-sm') ?> Save rate</button>
    </form>
  </div>
  <div class="card">
    <div class="card-title">Current setting</div>
    <div class="settle-grid">
      <div class="settle-item"><div class="k">Active rate</div><div class="v"><?= e($activePercent) ?>%</div></div>
      <div class="settle-item"><div class="k">Basis points</div><div class="v"><?= (int)$status['bps'] ?></div></div>
      <div class="settle-item"><div class="k">Source</div><div class="v"><?= $status['source'] === 'database' ? 'Database' : 'Environment' ?></div></div>
      <div class="settle-item">
        <div class="k">Last updated</div>
        <div class="v"><?= $status['updated_at'] ? e(date('M j, Y g:i A', strtotime($status['updated_at']))) : 'Not saved yet' ?></div>
      </div>
      <div class="settle-item">
        <div class="k">Updated by</div>
        <div class="v"><?= $status['admin_name'] ? e($status['admin_name']) : 'No administrator yet' ?></div>
      </div>
    </div>
    <p class="settle-note">Until an administrator saves a rate, new orders use <code>CAMPUSLINK_COMMISSION_BPS</code>. A saved database rate replaces that fallback and does not rewrite older lines.</p>
  </div>
</div>

<div class="card" style="margin-top:1rem;">
  <div class="card-title">Change history</div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>When</th><th>Administrator</th><th>From</th><th>To</th></tr></thead>
      <tbody>
        <?php foreach ($history as $row): ?>
          <?php
            $before = json_decode((string)($row['before_json'] ?? ''), true);
            $after = json_decode((string)($row['after_json'] ?? ''), true);
            $fromBps = is_array($before) ? (int)($before['commission_bps'] ?? 0) : 0;
            $toBps = is_array($after) ? (int)($after['commission_bps'] ?? 0) : 0;
            $fromSource = is_array($before) ? (string)($before['source'] ?? '') : '';
          ?>
          <tr>
            <td><?= e(date('M j, Y g:i A', strtotime((string)$row['created_at']))) ?></td>
            <td><?= e((string)$row['admin_name']) ?></td>
            <td class="num"><?= e(number_format($fromBps / 100, 2)) ?>%<?= $fromSource === 'environment' ? ' (environment)' : '' ?></td>
            <td class="num"><?= e(number_format($toBps / 100, 2)) ?>%</td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$history): ?>
          <tr><td colspan="4"><?= ui_empty('sliders', 'No commission changes', 'Saved rate changes will be listed here.', '', true) ?></td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
