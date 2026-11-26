<?php
// CampusLink – Seller product list
require_once __DIR__ . '/../includes/auth_guard.php';
require_once __DIR__ . '/../includes/ui.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/admin_vendor.php';
require_role('seller');

$uid = current_user_id();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $pid = (int)($_POST['product_id'] ?? $_POST['delete_product'] ?? 0);
    try {
        if (isset($_POST['delete_product']) || (($_POST['listing_action'] ?? '') === 'delete')) {
            $result = remove_vendor_product($conn, $pid, $uid);
            set_flash($result['ok'] ? ($result['action'] === 'deleted' ? 'success' : 'info') : 'error', $result['message']);
        } elseif (in_array((string)($_POST['listing_action'] ?? ''), ['activate', 'deactivate'], true)) {
            $result = set_vendor_product_active($conn, $pid, $uid, $_POST['listing_action'] === 'activate');
            set_flash($result['ok'] ? 'success' : 'error', $result['message']);
        }
    } catch (Throwable $e) {
        error_log('CampusLink product removal failed: ' . $e->getMessage());
        set_flash('error', 'That product could not be changed. Please try again.');
    }
    redirect(APP_BASE_URL . '/seller/my_products.php');
}

$stmt = $conn->prepare(
    'SELECT p.id,p.title,p.price,p.stock,p.is_active,p.image,p.created_at,c.name AS category
     FROM products p LEFT JOIN categories c ON c.id=p.category_id
     WHERE p.seller_id=? ORDER BY p.created_at DESC'
);
$stmt->bind_param('i',$uid); $stmt->execute();
$products = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();

$dashTitle   = 'My Products';
$dashSub     = count($products) . ' product' . (count($products) === 1 ? '' : 's');
$dashActions = '<a href="' . APP_BASE_URL . '/seller/add_product.php" class="btn btn-primary btn-sm">'
             . ui_icon('plus', 'icn-sm') . ' Add Product</a>';
?>
<?php require_once __DIR__ . '/../includes/header.php'; ?>

<?php if (empty($products)): ?>
    <div class="card">
        <?= ui_empty(
              'package',
              'No products yet',
              'Add your first product and start selling to students on campus.',
              '<a href="' . APP_BASE_URL . '/seller/add_product.php" class="btn btn-primary btn-sm">' . ui_icon('plus', 'icn-sm') . ' Add Product</a>'
            ) ?>
    </div>
<?php else: ?>
    <div class="card" style="padding:0;">
        <div class="table-wrap">
            <table>
                <thead>
                    <tr><th>Product</th><th>Category</th><th>Price</th><th>Stock</th><th>Status</th><th>Added</th><th></th></tr>
                </thead>
                <tbody>
                    <?php foreach ($products as $p): ?>
                    <tr>
                        <td>
                            <div class="cell-media">
                                <?= ui_thumb($p['image'] ?? null, (string)$p['title']) ?>
                                <span>
                                    <a class="t" href="<?= APP_BASE_URL ?>/public/product.php?id=<?= (int)$p['id'] ?>"><?= htmlspecialchars($p['title']) ?></a>
                                </span>
                            </div>
                        </td>
                        <td class="text-muted"><?= htmlspecialchars($p['category'] ?? '—') ?></td>
                        <td class="num" style="font-weight:600;color:var(--forest);"><?= format_currency($p['price']) ?></td>
                        <td>
                            <?php if ($p['stock'] == 0): ?>
                                <?= ui_badge('cancelled', 'Out of stock') ?>
                            <?php elseif ($p['stock'] <= 3): ?>
                                <?= ui_badge('warning', $p['stock'] . ' left') ?>
                            <?php else: ?>
                                <span style="font-weight:600;"><?= (int)$p['stock'] ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?= (int)($p['is_active'] ?? 1) === 1 ? ui_badge('paid', 'Active', false) : ui_badge('cancelled', 'Inactive', false) ?></td>
                        <td class="text-muted"><?= date('M j, Y', strtotime($p['created_at'])) ?></td>
                        <td class="table-actions">
                            <?php $on = (int)($p['is_active'] ?? 1) === 1; ?>
                            <a href="<?= APP_BASE_URL ?>/seller/edit_product.php?id=<?= (int)$p['id'] ?>"
                               class="btn btn-secondary btn-sm"><?= ui_icon('pencil', 'icn-sm') ?> Edit</a>
                            <form method="POST">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="product_id" value="<?= (int)$p['id'] ?>">
                                <button type="submit" name="listing_action" value="<?= $on ? 'deactivate' : 'activate' ?>"
                                        class="btn btn-secondary btn-sm"
                                        data-confirm="<?= $on ? 'Deactivate this product? It will be hidden from the marketplace. Orders that already include it stay unchanged.' : 'Activate this product and show it on the marketplace again?' ?>">
                                    <?= $on ? 'Deactivate' : 'Activate' ?>
                                </button>
                            </form>
                            <form method="POST">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <button type="submit" name="delete_product" value="<?= (int)$p['id'] ?>"
                                        class="btn btn-danger btn-sm"
                                        data-confirm="Permanently delete <?= htmlspecialchars((string)$p['title'], ENT_QUOTES) ?>? This is only possible when the product has never been ordered. If it appears on an order, it will be deactivated instead."><?= ui_icon('trash', 'icn-sm') ?> Delete</button>
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
