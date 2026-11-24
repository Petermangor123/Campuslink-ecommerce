<?php
// CampusLink – Admin financial ledger export
require_once __DIR__ . '/../includes/auth_guard.php';
require_once __DIR__ . '/../includes/ui.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/order_service.php';
require_role('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
}
$export = $_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['export'] ?? '') === 'csv';

$rows = $conn->query(
    "SELECT b.id, b.source_type, b.source_id, b.order_id, o.reference_code,
            b.currency, b.description, b.created_at,
            (SELECT COUNT(*) FROM financial_entries fe WHERE fe.batch_id=b.id) AS entry_count,
            COALESCE((SELECT SUM(fe.debit_minor) FROM financial_entries fe WHERE fe.batch_id=b.id),0) AS debit_total_minor,
            COALESCE((SELECT SUM(fe.credit_minor) FROM financial_entries fe WHERE fe.batch_id=b.id),0) AS credit_total_minor
     FROM financial_batches b
     LEFT JOIN orders o ON o.id=b.order_id
     ORDER BY b.id DESC"
)->fetch_all(MYSQLI_ASSOC);

if ($export) {
    $csvRows = array_map(static function (array $row): array {
        $debit = (int)$row['debit_total_minor'];
        $credit = (int)$row['credit_total_minor'];
        return [
            $row['id'],
            $row['created_at'],
            $row['source_type'],
            $row['source_id'],
            $row['order_id'] ?? '',
            $row['reference_code'] ?? '',
            $row['currency'],
            $row['description'],
            (int)$row['entry_count'],
            $debit,
            $credit,
            ((int)$row['entry_count'] > 0 && $debit === $credit) ? 'yes' : 'no',
        ];
    }, $rows);
    stream_financial_csv(
        'campuslink-ledger.csv',
        ['Batch ID', 'Created at', 'Source type', 'Source ID', 'Order ID', 'Order reference', 'Currency', 'Description', 'Entry count', 'Debit total minor', 'Credit total minor', 'Balanced'],
        $csvRows
    );
}

$dashTitle = 'Financial Ledger';
$dashSub   = 'Balanced financial batches · export uses CSRF-protected POST';
?>
<?php require_once __DIR__ . '/../includes/header.php'; ?>

<div class="page-bar">
  <div>
    <h1 class="page-title">Financial ledger</h1>
    <p class="text-muted">One row per financial batch. Amounts are integer minor units and are never totalled across currencies.</p>
  </div>
  <form method="POST">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="export" value="csv">
    <button class="btn btn-primary btn-sm" type="submit">Export ledger CSV</button>
  </form>
</div>

<div class="card" style="padding:0;">
  <div class="table-wrap">
    <table>
      <thead><tr><th>Batch</th><th>Created</th><th>Source</th><th>Order</th><th>Currency</th><th>Description</th><th>Entries</th><th>Debit minor</th><th>Credit minor</th><th>Balanced</th></tr></thead>
      <tbody>
        <?php foreach (array_slice($rows, 0, 200) as $row): ?>
          <?php $debit = (int)$row['debit_total_minor']; $credit = (int)$row['credit_total_minor']; ?>
          <tr>
            <td class="num">#<?= (int)$row['id'] ?></td>
            <td class="text-muted"><?= e(date('M j, Y g:i A', strtotime($row['created_at']))) ?></td>
            <td><?= e($row['source_type']) ?><br><span class="text-muted"><?= e($row['source_id']) ?></span></td>
            <td>
              <?php if (!empty($row['order_id'])): ?>
                <a href="<?= APP_BASE_URL ?>/admin/order_view.php?id=<?= (int)$row['order_id'] ?>"><?= e($row['reference_code'] ?: ('#' . (int)$row['order_id'])) ?></a>
              <?php else: ?>—<?php endif; ?>
            </td>
            <td><?= e($row['currency']) ?></td>
            <td><?= e($row['description']) ?></td>
            <td class="num"><?= (int)$row['entry_count'] ?></td>
            <td class="num"><?= number_format($debit) ?></td>
            <td class="num"><?= number_format($credit) ?></td>
            <td><?= ((int)$row['entry_count'] > 0 && $debit === $credit) ? ui_badge('paid', 'Yes', false) : ui_badge('failed', 'No', false) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?>
          <tr><td colspan="10" class="text-muted">No financial batches recorded.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
