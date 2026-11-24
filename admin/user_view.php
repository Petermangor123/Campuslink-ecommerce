<?php
// CampusLink – Admin: user detail
require_once __DIR__ . '/../includes/auth_guard.php';
require_once __DIR__ . '/../includes/ui.php';
require_once __DIR__ . '/../config/db.php';
require_role('admin');

$id = (int)($_GET['id'] ?? 0);
$stmt = $conn->prepare('SELECT id, name, email, role, is_active, phone, created_at FROM users WHERE id = ? LIMIT 1');
$stmt->bind_param('i', $id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    set_flash('error', 'User not found.');
    redirect(APP_BASE_URL . '/admin/users.php');
}

$orderCount = 0;
$productCount = 0;
$paidAsBuyer = 0.0;

if ($user['role'] === 'buyer' || true) {
    $s = $conn->prepare('SELECT COUNT(*) AS c, COALESCE(SUM(CASE WHEN payment_status=\'paid\' THEN total ELSE 0 END),0) AS paid FROM orders WHERE buyer_id = ?');
    $s->bind_param('i', $id);
    $s->execute();
    $row = $s->get_result()->fetch_assoc();
    $s->close();
    $orderCount = (int)($row['c'] ?? 0);
    $paidAsBuyer = (float)($row['paid'] ?? 0);
}

$s = $conn->prepare('SELECT COUNT(*) AS c FROM products WHERE seller_id = ?');
$s->bind_param('i', $id);
$s->execute();
$productCount = (int)$s->get_result()->fetch_assoc()['c'];
$s->close();

$recentOrders = [];
$s = $conn->prepare(
    'SELECT id, reference_code, total, status, payment_status, created_at
     FROM orders WHERE buyer_id = ? ORDER BY created_at DESC LIMIT 10'
);
$s->bind_param('i', $id);
$s->execute();
$recentOrders = $s->get_result()->fetch_all(MYSQLI_ASSOC);
$s->close();

$listings = [];
$s = $conn->prepare(
    'SELECT p.id, p.title, p.price, p.stock, c.name AS category
     FROM products p
     LEFT JOIN categories c ON c.id = p.category_id
     WHERE p.seller_id = ?
     ORDER BY p.created_at DESC LIMIT 10'
);
$s->bind_param('i', $id);
$s->execute();
$listings = $s->get_result()->fetch_all(MYSQLI_ASSOC);
$s->close();

$dashTitle = $user['name'];
$dashSub   = ucfirst((string)$user['role']) . ' · ' . ((int)$user['is_active'] === 1 ? 'Active' : 'Inactive');
$dashActions = '<a class="btn btn-secondary btn-sm" href="' . APP_BASE_URL . '/admin/users.php">'
             . ui_icon('arrow-left', 'icn-sm') . ' Back to users</a>';
?>
<?php require_once __DIR__ . '/../includes/header.php'; ?>

<div class="stats-row">
  <?= ui_stat((string)$orderCount, 'Orders as buyer', 'orders') ?>
  <?= ui_stat(format_currency($paidAsBuyer), 'Paid as buyer', 'dollar', 'success') ?>
  <?= ui_stat((string)$productCount, 'Products as vendor', 'package') ?>
</div>

<div class="card">
  <div class="card-title">Account</div>
  <div class="table-wrap">
    <table>
      <tbody>
        <tr><th>Name</th><td><?= htmlspecialchars($user['name']) ?></td></tr>
        <tr><th>Email</th><td><?= htmlspecialchars($user['email']) ?></td></tr>
        <tr><th>Role</th><td><?= ui_badge((string)$user['role']) ?></td></tr>
        <tr><th>Status</th><td><?= (int)$user['is_active'] === 1 ? ui_badge('paid', 'Active', false) : ui_badge('cancelled', 'Inactive', false) ?></td></tr>
        <tr><th>Phone</th><td><?= htmlspecialchars($user['phone'] ?: '—') ?></td></tr>
        <tr><th>Joined</th><td><?= date('M j, Y g:i A', strtotime($user['created_at'])) ?></td></tr>
      </tbody>
    </table>
  </div>
</div>

<div class="two-col">
  <div class="card">
    <div class="card-title">Recent buyer orders</div>
    <?php if (empty($recentOrders)): ?>
      <p class="text-muted">No orders for this account.</p>
    <?php else: ?>
      <div class="table-wrap">
        <table>
          <thead><tr><th>Order</th><th>Total</th><th>Payment</th><th>Status</th></tr></thead>
          <tbody>
            <?php foreach ($recentOrders as $o): ?>
            <tr>
              <td><a href="<?= APP_BASE_URL ?>/admin/order_view.php?id=<?= (int)$o['id'] ?>"><?= htmlspecialchars($o['reference_code'] ?: ('#' . $o['id'])) ?></a></td>
              <td class="num"><?= format_currency($o['total']) ?></td>
              <td><?= ui_badge((string)$o['payment_status'], null, false) ?></td>
              <td><?= ui_badge((string)$o['status']) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <div class="card">
    <div class="card-title">Vendor products
      <?php if ($user['role'] === 'seller'): ?>
        · <a href="<?= APP_BASE_URL ?>/admin/vendor_view.php?id=<?= (int)$user['id'] ?>">Open Vendor Management</a>
      <?php endif; ?>
    </div>
    <?php if (empty($listings)): ?>
      <p class="text-muted">No products listed by this account.</p>
    <?php else: ?>
      <div class="table-wrap">
        <table>
          <thead><tr><th>Title</th><th>Category</th><th>Price</th><th>Stock</th></tr></thead>
          <tbody>
            <?php foreach ($listings as $p): ?>
            <tr>
              <td><a href="<?= APP_BASE_URL ?>/admin/edit_product.php?id=<?= (int)$p['id'] ?>"><?= htmlspecialchars($p['title']) ?></a></td>
              <td><?= htmlspecialchars($p['category'] ?: '—') ?></td>
              <td class="num"><?= format_currency($p['price']) ?></td>
              <td><?= (int)$p['stock'] ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
