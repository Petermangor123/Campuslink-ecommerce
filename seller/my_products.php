<?php
// CampusLink – Seller product list
require_once __DIR__ . '/../includes/auth_guard.php';
require_once __DIR__ . '/../includes/ui.php';
require_once __DIR__ . '/../config/db.php';
require_role('seller');

$uid = current_user_id();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_product'])) {
    verify_csrf();
    $pid  = (int)$_POST['delete_product'];
    $stmt = $conn->prepare('SELECT id FROM products WHERE id=? AND seller_id=?');
    $stmt->bind_param('ii',$pid,$uid); $stmt->execute(); $stmt->store_result();
    if ($stmt->num_rows > 0) {
        $stmt->close();
        $d = $conn->prepare('DELETE FROM products WHERE id=?');
        $d->bind_param('i',$pid); $d->execute(); $d->close();
        set_flash('success','Product deleted.');
    } else { $stmt->close(); }
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
                            <a href="<?= APP_BASE_URL ?>/seller/edit_product.php?id=<?= (int)$p['id'] ?>"
                               class="btn btn-secondary btn-sm"><?= ui_icon('pencil', 'icn-sm') ?> Edit</a>
                            <form method="POST">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <button type="submit" name="delete_product" value="<?= (int)$p['id'] ?>"
                                        class="btn btn-danger btn-sm"
                                        data-confirm="Delete this product? This cannot be undone."><?= ui_icon('trash', 'icn-sm') ?> Delete</button>
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
