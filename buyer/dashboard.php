<?php
// CampusLink – Customer account dashboard
require_once __DIR__ . '/../includes/auth_guard.php';
require_once __DIR__ . '/../includes/ui.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/cart.php';
require_once __DIR__ . '/../includes/order_service.php';
require_role('buyer');

$uid = current_user_id();
$name = current_user_name();

$stmt = $conn->prepare('SELECT COUNT(*) AS c FROM orders WHERE buyer_id=?');
$stmt->bind_param('i', $uid); $stmt->execute();
$totalOrders = (int)$stmt->get_result()->fetch_assoc()['c']; $stmt->close();

$stmt = $conn->prepare(
    "SELECT COUNT(*) AS c FROM orders WHERE buyer_id=? AND payment_status='pending'"
);
$stmt->bind_param('i', $uid); $stmt->execute();
$pendingOrders = (int)$stmt->get_result()->fetch_assoc()['c']; $stmt->close();

$stmt = $conn->prepare(
    "SELECT COUNT(*) AS c FROM orders WHERE buyer_id=? AND status='delivered'"
);
$stmt->bind_param('i', $uid); $stmt->execute();
$completedOrders = (int)$stmt->get_result()->fetch_assoc()['c']; $stmt->close();

$cartQty = cart_quantity_total($conn, $uid);

$stmt = $conn->prepare(
    'SELECT o.id, o.reference_code, o.total, o.status, o.payment_status, o.created_at,
            (SELECT oi.product_id FROM order_items oi WHERE oi.order_id=o.id ORDER BY oi.id ASC LIMIT 1) AS first_product_id
     FROM orders o
     WHERE o.buyer_id=?
     ORDER BY o.created_at DESC
     LIMIT 5'
);
$stmt->bind_param('i', $uid); $stmt->execute();
$recent = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();

// Attach first line image/title for thumbnails
foreach ($recent as &$o) {
    $o['thumb_image'] = null;
    $o['thumb_title'] = '';
    $pid = (int)($o['first_product_id'] ?? 0);
    if ($pid > 0) {
        $ps = $conn->prepare('SELECT title, image FROM products WHERE id=? LIMIT 1');
        $ps->bind_param('i', $pid);
        $ps->execute();
        $prow = $ps->get_result()->fetch_assoc();
        $ps->close();
        if ($prow) {
            $o['thumb_image'] = $prow['image'] ?? null;
            $o['thumb_title'] = (string)($prow['title'] ?? '');
        }
    }
}
unset($o);

$pageTitle = 'Home';
?>
<?php require_once __DIR__ . '/../includes/header.php'; ?>

<div class="dash-welcome">
  <div>
    <h2>Welcome, <?= e($name) ?></h2>
    <p>Browse products, track your orders, and manage your account from here.</p>
  </div>
  <a class="btn btn-primary btn-sm" href="<?= APP_BASE_URL ?>/public/browse.php">
    <?= ui_icon('compass', 'icn-sm') ?> Browse Products
  </a>
</div>

<div class="stats-row">
  <?= ui_stat((string)$totalOrders, 'Total orders', 'orders') ?>
  <?= ui_stat((string)$pendingOrders, 'Pending payment', 'card', 'accent') ?>
  <?= ui_stat((string)$completedOrders, 'Delivered', 'check-circle', 'success') ?>
  <?= ui_stat((string)$cartQty, 'Cart items', 'cart', 'info') ?>
</div>

<h3 class="dash-section-title">Quick actions</h3>
<div class="dash-shortcuts">
  <?= ui_shortcut(['href' => APP_BASE_URL . '/public/browse.php', 'icon' => 'compass', 'label' => 'Browse Products', 'hint' => 'Find campus products']) ?>
  <?= ui_shortcut(['href' => APP_BASE_URL . '/buyer/orders.php', 'icon' => 'orders', 'label' => 'My Orders', 'hint' => 'History and status']) ?>
  <?= ui_shortcut(['href' => APP_BASE_URL . '/public/track.php', 'icon' => 'truck', 'label' => 'Track Order', 'hint' => 'Secure tracking link']) ?>
  <?= ui_shortcut(['href' => APP_BASE_URL . '/buyer/cart.php', 'icon' => 'cart', 'label' => 'My Cart', 'hint' => $cartQty . ' item' . ($cartQty === 1 ? '' : 's')]) ?>
</div>

<div class="two-col">
  <div class="card">
    <div class="card-title">Recent orders</div>
    <?php if (empty($recent)): ?>
      <?= ui_empty(
            'orders',
            'No orders yet',
            'When you check out, your purchases will show up here.',
            '<a href="' . APP_BASE_URL . '/public/browse.php" class="btn btn-primary btn-sm">' . ui_icon('compass', 'icn-sm') . ' Start shopping</a>',
            true
          ) ?>
    <?php else: ?>
      <?php foreach ($recent as $o): ?>
        <div class="order-mini">
          <?= ui_thumb($o['thumb_image'] ?? null, (string)($o['thumb_title'] ?: 'Order')) ?>
          <div class="om-body">
            <a class="om-ref" href="<?= APP_BASE_URL ?>/buyer/order_detail.php?id=<?= (int)$o['id'] ?>">
              <?= e($o['reference_code'] ?: ('#' . $o['id'])) ?>
            </a>
            <div class="om-meta">
              <?= e(date('M j, Y', strtotime($o['created_at']))) ?>
              · <?= e(ui_status_label((string)$o['payment_status'])) ?> payment
              · <?= e(ui_status_label((string)$o['status'])) ?>
            </div>
          </div>
          <div class="om-amt"><?= format_currency($o['total']) ?></div>
        </div>
      <?php endforeach; ?>
      <div class="mt-3">
        <a href="<?= APP_BASE_URL ?>/buyer/orders.php" class="btn btn-secondary btn-sm">My Orders <?= ui_icon('arrow-right', 'icn-sm') ?></a>
      </div>
    <?php endif; ?>
  </div>

  <div class="card">
    <div class="card-title">Continue shopping</div>
    <p class="text-muted card-lead">Keep your details up to date for smoother checkout and delivery.</p>
    <div class="dash-shortcuts" style="margin-bottom:0;">
      <?= ui_shortcut(['href' => APP_BASE_URL . '/public/browse.php', 'icon' => 'compass', 'label' => 'Browse Products', 'hint' => 'Continue shopping']) ?>
      <?= ui_shortcut(['href' => APP_BASE_URL . '/buyer/profile.php', 'icon' => 'user', 'label' => 'My Profile', 'hint' => 'Name, phone, password']) ?>
      <?= ui_shortcut(['href' => APP_BASE_URL . '/buyer/cart.php', 'icon' => 'cart', 'label' => 'My Cart', 'hint' => 'Continue checkout']) ?>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
