<?php
// CampusLink – Admin Vendor Management (detail + listings)
require_once __DIR__ . '/../includes/auth_guard.php';
require_once __DIR__ . '/../includes/ui.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/admin_vendor.php';
require_once __DIR__ . '/../includes/order_service.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST') { verify_csrf(); }
require_role('admin');

$vendorId = (int)($_GET['id'] ?? $_POST['vendor_id'] ?? 0);
$vendor = admin_require_vendor($conn, $vendorId);
$adminId = current_user_id();

// Soft activate / deactivate / safe delete
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $productId = (int)($_POST['product_id'] ?? 0);
    $action = (string)($_POST['listing_action'] ?? '');
    $loaded = admin_load_vendor_product($conn, $productId, $vendorId);
    if (!$loaded['ok']) {
        set_flash('error', $loaded['message'] ?? 'Product not found for this vendor.');
        redirect(APP_BASE_URL . '/admin/vendor_view.php?id=' . $vendorId);
    }
    $product = $loaded['product'];
    $before = admin_product_snapshot($product);

    if ($action === 'deactivate' || $action === 'activate') {
        $to = $action === 'activate' ? 1 : 0;
        $stmt = $conn->prepare('UPDATE products SET is_active=? WHERE id=? AND seller_id=?');
        $stmt->bind_param('iii', $to, $productId, $vendorId);
        $stmt->execute();
        $stmt->close();
        $product['is_active'] = $to;
        admin_audit_log(
            $conn,
            $adminId,
            $vendorId,
            $productId,
            $to ? 'product_activate' : 'product_deactivate',
            $before,
            admin_product_snapshot($product)
        );
        set_flash('success', $to ? 'Product activated.' : 'Product deactivated. It is hidden from the marketplace.');
    } elseif ($action === 'delete') {
        if (admin_product_has_order_lines($conn, $productId)) {
            set_flash('error', 'This product appears on orders. Deactivate it instead of deleting.');
        } else {
            $img = (string)($product['image'] ?? '');
            $d = $conn->prepare('DELETE FROM products WHERE id=? AND seller_id=?');
            $d->bind_param('ii', $productId, $vendorId);
            $d->execute();
            $ok = $d->affected_rows > 0;
            $d->close();
            if ($ok) {
                if ($img !== '') {
                    $path = __DIR__ . '/../' . $img;
                    if (is_file($path)) {
                        @unlink($path);
                    }
                }
                admin_audit_log($conn, $adminId, $vendorId, $productId, 'product_delete', $before, null);
                set_flash('success', 'Product deleted.');
            } else {
                set_flash('error', 'Could not delete that product.');
            }
        }
    } else {
        set_flash('error', 'Unknown product action.');
    }
    redirect(APP_BASE_URL . '/admin/vendor_view.php?id=' . $vendorId);
}

$currency = app_payment_currency();
$finance = seller_financial_summary($conn, $vendorId, $currency);

$stmt = $conn->prepare(
    'SELECT p.id, p.title, p.price, p.stock, p.is_active, p.image, p.created_at, c.name AS category
     FROM products p
     LEFT JOIN categories c ON c.id = p.category_id
     WHERE p.seller_id=?
     ORDER BY p.created_at DESC'
);
$stmt->bind_param('i', $vendorId);
$stmt->execute();
$listings = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$payoutStmt = $conn->prepare(
    'SELECT id, settlement_reference, amount_minor, currency, status, method, note,
            external_reference, created_at, completed_at
     FROM payouts WHERE seller_id=? ORDER BY id DESC LIMIT 100'
);
$payoutStmt->bind_param('i', $vendorId);
$payoutStmt->execute();
$payouts = $payoutStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$payoutStmt->close();

$aud = $conn->prepare(
    'SELECT a.id, a.action, a.product_id, a.created_at, u.name AS admin_name
     FROM admin_audit_log a
     INNER JOIN users u ON u.id = a.admin_id
     WHERE a.vendor_id=?
     ORDER BY a.created_at DESC
     LIMIT 20'
);
$aud->bind_param('i', $vendorId);
$aud->execute();
$auditRows = $aud->get_result()->fetch_all(MYSQLI_ASSOC);
$aud->close();

$dashTitle = $vendor['name'];
$dashSub   = 'Vendor Management · ' . ucfirst((string)($vendor['vendor_status'] ?? 'pending'));
$dashActions = '<a class="btn btn-secondary btn-sm" href="' . APP_BASE_URL . '/admin/vendors.php">'
             . ui_icon('arrow-left', 'icn-sm') . ' All vendors</a>'
             . '<a class="btn btn-primary btn-sm" href="' . APP_BASE_URL . '/admin/add_product.php?vendor_id=' . (int)$vendorId . '">'
             . ui_icon('plus', 'icn-sm') . ' Add Product</a>';
?>
<?php require_once __DIR__ . '/../includes/header.php'; ?>

<?= ui_breadcrumbs([
      ['Vendor Management', APP_BASE_URL . '/admin/vendors.php'],
      [$vendor['name'], null],
    ]) ?>

<div class="stats-row">
  <?= ui_stat((string)count($listings), 'Products', 'package') ?>
  <?= ui_stat((string)array_sum(array_map(static fn($p) => (int)$p['is_active'], $listings)), 'Active', 'check-circle', 'success') ?>
  <?= ui_stat(format_currency($finance['gross_paid_sales'], $currency), 'Gross paid sales', 'dollar', 'success', 'Verified payments only') ?>
  <?= ui_stat(format_currency($finance['outstanding_settlement'], $currency), 'Available settlement', 'card', 'info', 'Delivered, undisputed balance') ?>
</div>

<div class="two-col">
  <div class="card">
    <div class="card-title">Vendor details</div>
    <div class="table-wrap">
      <table>
        <tbody>
          <tr><th>Name</th><td><?= htmlspecialchars($vendor['name']) ?></td></tr>
          <tr><th>Email</th><td><?= htmlspecialchars($vendor['email']) ?></td></tr>
          <tr><th>Phone</th><td><?= htmlspecialchars($vendor['phone'] ?: '—') ?></td></tr>
          <tr><th>Account</th><td><?= (int)$vendor['is_active'] === 1 ? ui_badge('paid', 'Active', false) : ui_badge('cancelled', 'Inactive', false) ?></td></tr>
          <tr><th>Approval</th><td><?= ui_badge((string)($vendor['vendor_status'] ?? 'pending'), ucfirst((string)($vendor['vendor_status'] ?? 'pending')), false) ?></td></tr>
          <tr><th>Joined</th><td><?= date('M j, Y', strtotime($vendor['created_at'])) ?></td></tr>
        </tbody>
      </table>
    </div>
    <p class="text-muted" style="font-size:.82rem;margin-top:.75rem;">
      Approve, reject, suspend, activate, or deactivate this account from <a href="<?= APP_BASE_URL ?>/admin/vendors.php">Vendor Management</a>.
      Product ownership always stays with this vendor.
    </p>
  </div>

  <div class="card">
    <div class="card-title">Financial summary (<?= e(currency_display_label($currency)) ?>)</div>
    <div class="settle-grid">
      <div class="settle-item"><div class="k">Gross paid sales</div><div class="v"><?= format_currency($finance['gross_paid_sales'], $currency) ?></div></div>
      <div class="settle-item"><div class="k">Commissions</div><div class="v"><?= format_currency($finance['commissions'], $currency) ?></div></div>
      <div class="settle-item"><div class="k">Buyer refunds (processed)</div><div class="v"><?= format_currency($finance['refunds_credits'], $currency) ?></div></div>
      <div class="settle-item"><div class="k">Seller refund debits</div><div class="v"><?= format_currency($finance['seller_refund_debits'], $currency) ?></div></div>
      <div class="settle-item"><div class="k">Net seller proceeds</div><div class="v"><?= format_currency($finance['net_sales'], $currency) ?></div></div>
      <div class="settle-item"><div class="k">Completed payouts</div><div class="v"><?= format_currency($finance['vendor_payouts'], $currency) ?></div></div>
      <div class="settle-item"><div class="k">Pending payouts</div><div class="v"><?= format_currency($finance['pending_payouts'], $currency) ?></div></div>
      <div class="settle-item"><div class="k">Available delivered settlement</div><div class="v"><?= format_currency($finance['outstanding_settlement'], $currency) ?></div></div>
      <div class="settle-item"><div class="k">Recovery debt</div><div class="v"><?= format_currency($finance['recovery_debt'], $currency) ?></div></div>
    </div>
    <?php if ($finance['unpaid_line_total'] > 0): ?>
      <p class="settle-note">Unpaid / failed lines excluded from paid sales: <?= format_currency($finance['unpaid_line_total'], $currency) ?></p>
    <?php endif; ?>
    <p style="margin-top:.75rem;">
      <a class="btn btn-primary btn-sm" href="<?= APP_BASE_URL ?>/admin/settlements.php?seller_id=<?= (int)$vendorId ?>">Create payout</a>
      <a class="btn btn-secondary btn-sm" href="<?= APP_BASE_URL ?>/admin/settlements.php?seller_id=<?= (int)$vendorId ?>">Manage in settlements</a>
    </p>
  </div>
</div>

<div class="card" style="margin-top:var(--s-5);">
  <div class="card-title" style="display:flex;justify-content:space-between;align-items:center;gap:1rem;flex-wrap:wrap;">
    <span>Payout history</span>
    <a class="btn btn-secondary btn-sm" href="<?= APP_BASE_URL ?>/admin/settlements.php?seller_id=<?= (int)$vendorId ?>">Create / manage payouts</a>
  </div>
  <?php if (empty($payouts)): ?>
    <p class="text-muted">No payouts have been recorded for this vendor.</p>
  <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Reference</th><th>Amount</th><th>Status</th><th>Method / note</th><th>External reference</th><th>Dates</th><th>Action</th></tr></thead>
        <tbody>
          <?php foreach ($payouts as $payout): ?>
          <tr>
            <td><code><?= e($payout['settlement_reference']) ?></code></td>
            <td class="num"><?= format_currency(minor_to_money((int)$payout['amount_minor']), $payout['currency'] ?: $currency) ?></td>
            <td><?= ui_badge((string)$payout['status'], null, false) ?></td>
            <td><?= e($payout['method']) ?><br><span class="text-muted"><?= e($payout['note'] ?: '—') ?></span></td>
            <td><?= e($payout['external_reference'] ?: '—') ?></td>
            <td class="text-muted">
              <?= e(date('M j, Y', strtotime($payout['created_at']))) ?>
              <?php if (!empty($payout['completed_at'])): ?><br>Completed <?= e(date('M j, Y', strtotime($payout['completed_at']))) ?><?php endif; ?>
            </td>
            <td>
              <?php if ($payout['status'] === 'pending'): ?>
                <form method="POST" action="<?= APP_BASE_URL ?>/admin/settlements.php" class="inline-form">
                  <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                  <input type="hidden" name="action" value="complete">
                  <input type="hidden" name="payout_id" value="<?= (int)$payout['id'] ?>">
                  <input name="external_reference" required maxlength="100" placeholder="Payment reference" aria-label="External payment reference">
                  <button class="btn btn-secondary btn-sm">Complete</button>
                </form>
                <form method="POST" action="<?= APP_BASE_URL ?>/admin/settlements.php" class="inline-form" style="margin-top:.35rem;">
                  <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                  <input type="hidden" name="action" value="void">
                  <input type="hidden" name="payout_id" value="<?= (int)$payout['id'] ?>">
                  <input name="void_reason" required maxlength="500" placeholder="Void reason" aria-label="Void reason">
                  <button class="btn btn-danger btn-sm">Void</button>
                </form>
              <?php else: ?>
                <a class="btn btn-secondary btn-sm" href="<?= APP_BASE_URL ?>/admin/settlements.php?seller_id=<?= (int)$vendorId ?>">Settlements</a>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<div class="card" style="margin-top:var(--s-5);">
  <div class="card-title" style="display:flex;justify-content:space-between;align-items:center;gap:1rem;flex-wrap:wrap;">
    <span>Products</span>
    <a class="btn btn-primary btn-sm" href="<?= APP_BASE_URL ?>/admin/add_product.php?vendor_id=<?= (int)$vendorId ?>">
      <?= ui_icon('plus', 'icn-sm') ?> Add Product for this vendor
    </a>
  </div>
  <?php if (empty($listings)): ?>
    <?= ui_empty('package', 'No products yet', 'Add a product on behalf of this vendor.', '', true) ?>
  <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead>
          <tr><th>Product</th><th>Category</th><th>Price</th><th>Stock</th><th>Status</th><th></th></tr>
        </thead>
        <tbody>
          <?php foreach ($listings as $p): ?>
          <?php $on = (int)$p['is_active'] === 1; ?>
          <tr>
            <td>
              <div class="cell-media">
                <?= ui_thumb($p['image'] ?? null, (string)$p['title']) ?>
                <span class="t"><?= htmlspecialchars($p['title']) ?></span>
              </div>
            </td>
            <td><?= htmlspecialchars($p['category'] ?: '—') ?></td>
            <td class="num" style="font-weight:600;color:var(--forest);"><?= format_currency($p['price']) ?></td>
            <td class="num"><?= (int)$p['stock'] ?></td>
            <td><?= $on ? ui_badge('paid', 'Active', false) : ui_badge('cancelled', 'Inactive', false) ?></td>
            <td class="table-actions">
              <a class="btn btn-secondary btn-sm" href="<?= APP_BASE_URL ?>/admin/edit_product.php?id=<?= (int)$p['id'] ?>&vendor_id=<?= (int)$vendorId ?>">Edit</a>
              <form method="POST" style="display:inline;">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="vendor_id" value="<?= (int)$vendorId ?>">
                <input type="hidden" name="product_id" value="<?= (int)$p['id'] ?>">
                <button type="submit" name="listing_action" value="<?= $on ? 'deactivate' : 'activate' ?>"
                        class="btn btn-sm <?= $on ? 'btn-secondary' : 'btn-primary' ?>"
                        data-confirm="<?= $on ? 'Deactivate this product?' : 'Activate this product?' ?>">
                  <?= $on ? 'Deactivate' : 'Activate' ?>
                </button>
              </form>
              <form method="POST" style="display:inline;">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="vendor_id" value="<?= (int)$vendorId ?>">
                <input type="hidden" name="product_id" value="<?= (int)$p['id'] ?>">
                <button type="submit" name="listing_action" value="delete" class="btn btn-danger btn-sm"
                        data-confirm="Permanently delete only if unused in orders. Prefer deactivate. Continue?">
                  Delete
                </button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<div class="card" style="margin-top:var(--s-5);">
  <div class="card-title">Recent admin actions</div>
  <?php if (empty($auditRows)): ?>
    <p class="text-muted">No administrative product actions recorded for this vendor yet.</p>
  <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead><tr><th>When</th><th>Admin</th><th>Action</th><th>Product</th></tr></thead>
        <tbody>
          <?php foreach ($auditRows as $row): ?>
          <tr>
            <td class="text-muted"><?= e(date('M j, g:i A', strtotime($row['created_at']))) ?></td>
            <td><?= htmlspecialchars($row['admin_name']) ?></td>
            <td><code><?= e($row['action']) ?></code></td>
            <td><?= $row['product_id'] ? '#' . (int)$row['product_id'] : '—' ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
