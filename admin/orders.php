<?php
// CampusLink – Admin: manage orders
require_once __DIR__ . '/../includes/auth_guard.php';
require_once __DIR__ . '/../includes/ui.php';
require_once __DIR__ . '/../config/db.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST') { verify_csrf(); }

require_role('admin');
require_once __DIR__ . '/../includes/order_service.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['order_id'], $_POST['status'])) {
    $message = apply_admin_order_action($conn, (int)$_POST['order_id'], (string)$_POST['status']);
    set_flash($message === '' ? 'success' : 'error', $message === '' ? 'Order status updated.' : $message);
    redirect(APP_BASE_URL . '/admin/orders.php?' . http_build_query(array_filter([
        'q' => trim((string)($_GET['q'] ?? '')),
        'payment' => trim((string)($_GET['payment'] ?? '')),
        'status' => trim((string)($_GET['status'] ?? '')),
    ], static fn($v) => $v !== '')));
}

$search  = trim((string)($_GET['q'] ?? ''));
$payment = trim((string)($_GET['payment'] ?? ''));
$fulfil  = trim((string)($_GET['status'] ?? ''));

$where = [];
$types = '';
$params = [];
if ($search !== '') {
    $where[] = '(o.reference_code LIKE ? OR o.customer_name LIKE ? OR o.customer_email LIKE ? OR u.name LIKE ? OR u.email LIKE ?)';
    $types .= 'sssss';
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like, $like, $like);
}
if (in_array($payment, ['pending', 'paid', 'failed', 'cancelled'], true)) {
    $where[] = 'o.payment_status = ?';
    $types .= 's';
    $params[] = $payment;
}
if (in_array($fulfil, ['pending', 'paid', 'shipped', 'delivered', 'cancelled'], true)) {
    $where[] = 'o.status = ?';
    $types .= 's';
    $params[] = $fulfil;
}

$sql = 'SELECT o.id, o.reference_code, o.total, o.status, o.payment_status, o.created_at,
               COALESCE(u.name, o.customer_name, \'Guest\') AS buyer,
               p.currency AS payment_currency
        FROM orders o
        LEFT JOIN users u ON u.id = o.buyer_id
        LEFT JOIN payments p ON p.order_id = o.id'
      . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
      . ' ORDER BY o.created_at DESC';

$stmt = $conn->prepare($sql);
if ($types !== '') {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$orders = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$dashTitle = 'Orders';
$dashSub   = count($orders) . ' order' . (count($orders) === 1 ? '' : 's');
?>
<?php require_once __DIR__ . '/../includes/header.php'; ?>

<form method="GET" class="search-panel">
  <div class="search-field" style="flex:2;">
    <label for="q">Search</label>
    <input type="text" id="q" name="q" placeholder="Reference, buyer name or email…" value="<?= htmlspecialchars($search) ?>">
  </div>
  <div class="search-field">
    <label for="payment">Payment</label>
    <select id="payment" name="payment">
      <option value="">All</option>
      <?php foreach (['pending', 'paid', 'failed', 'cancelled'] as $p): ?>
        <option value="<?= $p ?>" <?= $payment === $p ? 'selected' : '' ?>><?= ui_status_label($p) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="search-field">
    <label for="status">Fulfilment</label>
    <select id="status" name="status">
      <option value="">All</option>
      <?php foreach (['pending', 'paid', 'shipped', 'delivered', 'cancelled'] as $s): ?>
        <option value="<?= $s ?>" <?= $fulfil === $s ? 'selected' : '' ?>><?= ui_status_label($s) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="search-actions">
    <button type="submit" class="btn btn-primary"><?= ui_icon('search', 'icn-sm') ?> Search</button>
    <?php if ($search !== '' || $payment !== '' || $fulfil !== ''): ?>
      <a href="<?= APP_BASE_URL ?>/admin/orders.php" class="btn btn-secondary">Clear</a>
    <?php endif; ?>
  </div>
</form>

<?php if (empty($orders)): ?>
  <div class="card">
    <?= ui_empty(
          'orders',
          'No matching orders',
          'Try another filter or clear the search.',
          '<a href="' . APP_BASE_URL . '/public/browse.php" class="btn btn-secondary btn-sm">' . ui_icon('store', 'icn-sm') . ' View storefront</a>'
        ) ?>
  </div>
<?php else: ?>
  <div class="card" style="padding:0;">
    <div class="table-wrap">
      <table>
        <thead>
          <tr><th>Order</th><th>Buyer</th><th>Total</th><th>Payment</th><th>Fulfilment</th><th>Date</th><th></th></tr>
        </thead>
        <tbody>
          <?php foreach ($orders as $o): ?>
          <tr>
            <td style="font-weight:600;color:var(--forest);">
              <a href="<?= APP_BASE_URL ?>/admin/order_view.php?id=<?= (int)$o['id'] ?>"><?= htmlspecialchars($o['reference_code'] ?: ('#' . $o['id'])) ?></a>
            </td>
            <td><?= htmlspecialchars($o['buyer']) ?></td>
            <td class="num" style="font-weight:600;color:var(--forest);"><?= format_currency($o['total'], $o['payment_currency'] ?? null) ?></td>
            <td><?= ui_badge((string)$o['payment_status'], null, false) ?></td>
            <td>
              <form method="POST" class="inline-form">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>">
                <label class="sr-only" for="status-<?= (int)$o['id'] ?>">Fulfilment status</label>
                <select id="status-<?= (int)$o['id'] ?>" name="status" class="select-inline">
                  <?php foreach (['pending', 'paid', 'failed', 'shipped', 'delivered', 'cancelled'] as $s): ?>
                    <option value="<?= $s ?>" <?= $o['status'] === $s ? 'selected' : '' ?>><?= ui_status_label($s) ?></option>
                  <?php endforeach; ?>
                </select>
                <button class="btn btn-secondary btn-sm" data-confirm="Update fulfilment status for this order?">Update</button>
              </form>
            </td>
            <td class="text-muted"><?= date('M j, Y', strtotime($o['created_at'])) ?></td>
            <td class="table-actions">
              <a class="btn btn-secondary btn-sm" href="<?= APP_BASE_URL ?>/admin/order_view.php?id=<?= (int)$o['id'] ?>">Details</a>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
