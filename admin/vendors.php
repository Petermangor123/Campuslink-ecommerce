<?php
// CampusLink – Admin Vendor Management (list)
require_once __DIR__ . '/../includes/auth_guard.php';
require_once __DIR__ . '/../includes/ui.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/admin_vendor.php';
require_once __DIR__ . '/../includes/order_service.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST') { verify_csrf(); }
require_role('admin');

// Activate / deactivate vendor accounts (merged from sellers.php)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_active'], $_POST['user_id'])) {
    $uid = (int)$_POST['user_id'];
    $to  = (int)$_POST['toggle_active'] === 1 ? 1 : 0;
    if ($uid === current_user_id()) {
        set_flash('error', 'You cannot deactivate your own account.');
    } else {
        $stmt = $conn->prepare("UPDATE users SET is_active = ? WHERE id = ? AND role = 'seller'");
        $stmt->bind_param('ii', $to, $uid);
        $stmt->execute();
        $ok = $stmt->affected_rows > 0;
        $stmt->close();
        set_flash($ok ? 'success' : 'error', $ok ? ($to ? 'Vendor account activated.' : 'Vendor account deactivated.') : 'Vendor not found.');
    }
    redirect(APP_BASE_URL . '/admin/vendors.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['vendor_status'], $_POST['user_id'])) {
    $uid = (int)$_POST['user_id'];
    $status = (string)$_POST['vendor_status'];
    if (!in_array($status, ['pending', 'approved', 'rejected', 'suspended'], true)) {
        set_flash('error', 'Choose a valid vendor approval status.');
        redirect(APP_BASE_URL . '/admin/vendors.php');
    }

    $stmt = $conn->prepare("SELECT vendor_status FROM users WHERE id=? AND role='seller' LIMIT 1");
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $vendor = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$vendor) {
        set_flash('error', 'Vendor not found.');
        redirect(APP_BASE_URL . '/admin/vendors.php');
    }

    $beforeStatus = (string)($vendor['vendor_status'] ?? 'pending');
    if ($beforeStatus === $status) {
        set_flash('success', 'Vendor approval status is already ' . $status . '.');
        redirect(APP_BASE_URL . '/admin/vendors.php');
    }

    $stmt = $conn->prepare("UPDATE users SET vendor_status=? WHERE id=? AND role='seller'");
    $stmt->bind_param('si', $status, $uid);
    $stmt->execute();
    $ok = $stmt->affected_rows > 0;
    $stmt->close();
    if ($ok) {
        admin_audit_log(
            $conn,
            current_user_id(),
            $uid,
            null,
            'vendor_' . $status,
            ['vendor_status' => $beforeStatus],
            ['vendor_status' => $status]
        );
        set_flash('success', 'Vendor ' . ($status === 'approved' ? 'approved.' : 'status changed to ' . $status . '.'));
    } else {
        set_flash('error', 'Could not update vendor approval status.');
    }
    redirect(APP_BASE_URL . '/admin/vendors.php');
}

$search = trim((string)($_GET['q'] ?? ''));
$status = trim((string)($_GET['status'] ?? ''));
$approval = trim((string)($_GET['approval'] ?? ''));

$where = ["u.role = 'seller'"];
$types = '';
$params = [];
if ($search !== '') {
    $where[] = '(u.name LIKE ? OR u.email LIKE ?)';
    $types .= 'ss';
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
}
if ($status === 'active') {
    $where[] = 'u.is_active = 1';
} elseif ($status === 'inactive') {
    $where[] = 'u.is_active = 0';
}
if (in_array($approval, ['pending', 'approved', 'rejected', 'suspended'], true)) {
    $where[] = 'u.vendor_status = ?';
    $types .= 's';
    $params[] = $approval;
}

$sql = "SELECT u.id, u.name, u.email, u.is_active, u.vendor_status, u.phone, u.created_at,
               (SELECT COUNT(*) FROM products p WHERE p.seller_id=u.id) AS listing_count,
               (SELECT COUNT(*) FROM products p WHERE p.seller_id=u.id AND p.is_active=1) AS active_listings
        FROM users u
        WHERE " . implode(' AND ', $where) . '
        ORDER BY u.name ASC';

$stmt = $conn->prepare($sql);
if ($types !== '') {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$vendors = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$currency = app_payment_currency();
foreach ($vendors as &$vendor) {
    $vendor['finance'] = seller_financial_summary($conn, (int)$vendor['id'], $currency);
}
unset($vendor);

$dashTitle = 'Vendor Management';
$dashSub   = count($vendors) . ' vendor' . (count($vendors) === 1 ? '' : 's');
$dashActions = '<a href="' . APP_BASE_URL . '/admin/add_product.php" class="btn btn-primary btn-sm">'
             . ui_icon('plus', 'icn-sm') . ' Add Product</a>';
?>
<?php require_once __DIR__ . '/../includes/header.php'; ?>

<p class="text-muted" style="margin-top:-.5rem;margin-bottom:1rem;">
  Approve vendors before they can add products or manage orders. Account activation and vendor approval are separate controls.
  Manage catalogues without impersonating vendor logins. New products are always owned by the selected vendor.
  Financial figures use <?= e(currency_display_label($currency)) ?> and subtract processed refunds; unpaid lines are excluded.
</p>

<form method="GET" class="search-panel">
  <div class="search-field" style="flex:2;">
    <label for="q">Search</label>
    <input type="text" id="q" name="q" placeholder="Vendor name or email…" value="<?= htmlspecialchars($search) ?>">
  </div>
  <div class="search-field">
    <label for="status">Account</label>
    <select id="status" name="status">
      <option value="">All</option>
      <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Active</option>
      <option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>Inactive</option>
    </select>
  </div>
  <div class="search-field">
    <label for="approval">Approval</label>
    <select id="approval" name="approval">
      <option value="">All</option>
      <?php foreach (['pending', 'approved', 'rejected', 'suspended'] as $approvalStatus): ?>
        <option value="<?= $approvalStatus ?>" <?= $approval === $approvalStatus ? 'selected' : '' ?>>
          <?= ucfirst($approvalStatus) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="search-actions">
    <button type="submit" class="btn btn-primary"><?= ui_icon('search', 'icn-sm') ?> Search</button>
    <?php if ($search !== '' || $status !== '' || $approval !== ''): ?>
      <a href="<?= APP_BASE_URL ?>/admin/vendors.php" class="btn btn-secondary">Clear</a>
    <?php endif; ?>
  </div>
</form>

<?php if (empty($vendors)): ?>
  <div class="card">
    <?= ui_empty('store', 'No vendors found', 'Vendor accounts appear here after registration.', '') ?>
  </div>
<?php else: ?>
  <div class="card" style="padding:0;">
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Vendor</th>
            <th>Products</th>
            <th>Gross paid</th>
            <th>Commissions</th>
            <th>Seller refund debits</th>
            <th>Net proceeds</th>
            <th>Payouts (completed / pending)</th>
            <th>Available delivered settlement</th>
            <th>Recovery debt</th>
            <th>Status</th>
            <th>Approval</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($vendors as $v): ?>
          <?php
            $active = (int)$v['is_active'] === 1;
            $vendorStatus = (string)($v['vendor_status'] ?? 'pending');
          ?>
          <tr>
            <td>
              <div class="t"><a href="<?= APP_BASE_URL ?>/admin/vendor_view.php?id=<?= (int)$v['id'] ?>"><?= htmlspecialchars($v['name']) ?></a></div>
              <div class="s text-muted"><?= htmlspecialchars($v['email']) ?></div>
            </td>
            <td class="num">
              <?= (int)$v['listing_count'] ?>
              <div class="text-muted" style="font-size:.78rem;"><?= (int)$v['active_listings'] ?> active</div>
            </td>
            <td class="num"><?= format_currency($v['finance']['gross_paid_sales'], $currency) ?></td>
            <td class="num"><?= format_currency($v['finance']['commissions'], $currency) ?></td>
            <td class="num"><?= format_currency($v['finance']['seller_refund_debits'], $currency) ?></td>
            <td class="num" style="font-weight:600;color:var(--forest);"><?= format_currency($v['finance']['net_sales'], $currency) ?></td>
            <td class="num">
              <?= format_currency($v['finance']['vendor_payouts'], $currency) ?>
              <div class="text-muted" style="font-size:.78rem;"><?= format_currency($v['finance']['pending_payouts'], $currency) ?> pending</div>
            </td>
            <td class="num"><?= format_currency($v['finance']['outstanding_settlement'], $currency) ?></td>
            <td class="num"><?= format_currency($v['finance']['recovery_debt'], $currency) ?></td>
            <td><?= $active ? ui_badge('paid', 'Active', false) : ui_badge('cancelled', 'Inactive', false) ?></td>
            <td><?= ui_badge($vendorStatus, ucfirst($vendorStatus), false) ?></td>
            <td class="table-actions">
              <a class="btn btn-primary btn-sm" href="<?= APP_BASE_URL ?>/admin/vendor_view.php?id=<?= (int)$v['id'] ?>">Manage</a>
              <a class="btn btn-secondary btn-sm" href="<?= APP_BASE_URL ?>/admin/add_product.php?vendor_id=<?= (int)$v['id'] ?>"><?= ui_icon('plus', 'icn-sm') ?> Add Product</a>
              <?php if ($vendorStatus !== 'approved'): ?>
                <form method="POST">
                  <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                  <input type="hidden" name="user_id" value="<?= (int)$v['id'] ?>">
                  <button type="submit" name="vendor_status" value="approved" class="btn btn-primary btn-sm">Approve</button>
                </form>
              <?php endif; ?>
              <?php if ($vendorStatus === 'pending' || $vendorStatus === 'suspended'): ?>
                <form method="POST">
                  <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                  <input type="hidden" name="user_id" value="<?= (int)$v['id'] ?>">
                  <button type="submit" name="vendor_status" value="rejected" class="btn btn-danger btn-sm"
                          data-confirm="Reject this vendor application?">Reject</button>
                </form>
              <?php endif; ?>
              <?php if ($vendorStatus === 'approved'): ?>
                <form method="POST">
                  <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                  <input type="hidden" name="user_id" value="<?= (int)$v['id'] ?>">
                  <button type="submit" name="vendor_status" value="suspended" class="btn btn-danger btn-sm"
                          data-confirm="Suspend vendor operations?">Suspend</button>
                </form>
              <?php endif; ?>
              <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="user_id" value="<?= (int)$v['id'] ?>">
                <button type="submit" name="toggle_active" value="<?= $active ? 0 : 1 ?>"
                        class="btn <?= $active ? 'btn-danger' : 'btn-secondary' ?> btn-sm"
                        data-confirm="<?= $active ? 'Deactivate this vendor account?' : 'Activate this vendor account?' ?>">
                  <?= $active ? 'Deactivate' : 'Activate' ?>
                </button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
