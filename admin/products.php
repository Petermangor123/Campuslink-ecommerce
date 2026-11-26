<?php
// CampusLink – Admin: manage all products
require_once __DIR__ . '/../includes/auth_guard.php';
require_once __DIR__ . '/../includes/ui.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/admin_vendor.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST') { verify_csrf(); }
require_role('admin');

// Soft deactivate / activate / hard delete (only when unused)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['product_id'], $_POST['listing_action'])) {
    $pid = (int)$_POST['product_id'];
    $action = (string)$_POST['listing_action'];
    $s = $conn->prepare('SELECT * FROM products WHERE id=? LIMIT 1');
    $s->bind_param('i', $pid);
    $s->execute();
    $row = $s->get_result()->fetch_assoc();
    $s->close();
    if ($row) {
        $before = admin_product_snapshot($row);
        $vendorId = (int)$row['seller_id'];
        if ($action === 'deactivate' || $action === 'activate') {
            $to = $action === 'activate' ? 1 : 0;
            $u = $conn->prepare('UPDATE products SET is_active=? WHERE id=?');
            $u->bind_param('ii', $to, $pid);
            $u->execute();
            $u->close();
            $row['is_active'] = $to;
            admin_audit_log($conn, current_user_id(), $vendorId, $pid, $to ? 'product_activate' : 'product_deactivate', $before, admin_product_snapshot($row));
            set_flash('success', $to ? 'Product activated.' : 'Product deactivated.');
        } elseif ($action === 'delete') {
            $result = remove_vendor_product($conn, $pid, $vendorId);
            if ($result['action'] === 'deleted') {
                admin_audit_log($conn, current_user_id(), $vendorId, $pid, 'product_delete', $before, null);
            } elseif ($result['action'] === 'deactivated') {
                $row['is_active'] = 0;
                admin_audit_log($conn, current_user_id(), $vendorId, $pid, 'product_deactivate', $before, admin_product_snapshot($row));
            }
            set_flash($result['ok'] ? ($result['action'] === 'deleted' ? 'success' : 'info') : 'error', $result['message']);
        }
    }
    redirect(APP_BASE_URL . '/admin/products.php');
}

// Filters
$search   = trim($_GET['q']        ?? '');
$category = trim($_GET['category'] ?? '');
$sort     = $_GET['sort'] ?? 'newest';

$order = match($sort) {
    'price_asc'  => 'p.price ASC',
    'price_desc' => 'p.price DESC',
    'title_asc'  => 'p.title ASC',
    default       => 'p.created_at DESC',
};

$where = []; $types = ''; $params = [];
if ($search !== '') {
    $where[] = '(p.title LIKE ? OR p.description LIKE ?)';
    $types  .= 'ss'; $like = "%$search%";
    $params[] = &$like; $params[] = &$like;
}
if ($category !== '') {
    $where[] = 'c.name = ?'; $types .= 's'; $params[] = &$category;
}

$sql = "SELECT p.id, p.title, p.price, p.stock, p.is_active, p.image, p.created_at, p.seller_id,
               c.name AS category, u.name AS seller_name, u.role AS seller_role
        FROM products p
        LEFT JOIN categories c ON c.id = p.category_id
        LEFT JOIN users u ON u.id = p.seller_id"
      . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
      . " ORDER BY $order";

$stmt = $conn->prepare($sql);
if ($types) $stmt->bind_param($types, ...$params);
$stmt->execute();
$products = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$cats = $conn->query('SELECT id, name FROM categories ORDER BY name')->fetch_all(MYSQLI_ASSOC);

$dashTitle = 'Products';
$dashSub   = count($products) . ' product' . (count($products) === 1 ? '' : 's');
$dashActions = '<a href="' . APP_BASE_URL . '/admin/vendors.php" class="btn btn-secondary btn-sm">'
             . ui_icon('store', 'icn-sm') . ' Vendor Management</a>'
             . '<a href="' . APP_BASE_URL . '/admin/add_product.php" class="btn btn-primary btn-sm">'
             . ui_icon('plus', 'icn-sm') . ' Add Product</a>';
?>
<?php require_once __DIR__ . '/../includes/header.php'; ?>

<form method="GET" class="search-panel">
    <div class="search-field" style="flex:2;">
        <label for="q">Search</label>
        <input type="text" id="q" name="q" placeholder="Product name or description…"
               value="<?= htmlspecialchars($search) ?>">
    </div>
    <div class="search-field">
        <label for="cat">Category</label>
        <select id="cat" name="category">
            <option value="">All categories</option>
            <?php foreach ($cats as $c): ?>
                <option value="<?= htmlspecialchars($c['name']) ?>"
                    <?= $category === $c['name'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($c['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="search-field">
        <label for="sort">Sort</label>
        <select id="sort" name="sort">
            <option value="newest"     <?= $sort === 'newest'     ? 'selected' : '' ?>>Newest first</option>
            <option value="title_asc"  <?= $sort === 'title_asc'  ? 'selected' : '' ?>>Name A–Z</option>
            <option value="price_asc"  <?= $sort === 'price_asc'  ? 'selected' : '' ?>>Price: Low → High</option>
            <option value="price_desc" <?= $sort === 'price_desc' ? 'selected' : '' ?>>Price: High → Low</option>
        </select>
    </div>
    <div class="search-actions">
        <button type="submit" class="btn btn-primary"><?= ui_icon('search', 'icn-sm') ?> Search</button>
        <?php if ($search !== '' || $category !== '' || $sort !== 'newest'): ?>
            <a href="<?= APP_BASE_URL ?>/admin/products.php" class="btn btn-secondary">Clear</a>
        <?php endif; ?>
    </div>
</form>

<?php if ($search !== '' || $category !== ''): ?>
    <div class="filter-summary">
        <span>Filtered by</span>
        <?php if ($search !== ''): ?>
            <?= ui_badge('neutral', '“' . $search . '”', false) ?>
        <?php endif; ?>
        <?php if ($category !== ''): ?>
            <?= ui_badge('neutral', $category, false) ?>
        <?php endif; ?>
        <a class="chip" href="<?= APP_BASE_URL ?>/admin/products.php"><?= ui_icon('x', 'icn-xs') ?> Clear filters</a>
    </div>
<?php endif; ?>

<?php if (empty($products)): ?>
    <div class="card">
        <?php if ($search !== '' || $category !== ''): ?>
            <?= ui_empty(
                  'search',
                  'No matches for these filters',
                  'Try a different search term or category.',
                  '<a href="' . APP_BASE_URL . '/admin/products.php" class="btn btn-secondary btn-sm">Clear filters</a>'
                ) ?>
        <?php else: ?>
            <?= ui_empty(
                  'package',
                  'No products yet',
                  'Products created by vendors will appear here for moderation.',
                  '<a href="' . APP_BASE_URL . '/admin/add_product.php" class="btn btn-primary btn-sm">' . ui_icon('plus', 'icn-sm') . ' Add Product</a>'
                ) ?>
        <?php endif; ?>
    </div>
<?php else: ?>
    <div class="card" style="padding:0;">
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>Category</th>
                        <th>Price</th>
                        <th>Stock</th>
                        <th>Status</th>
                        <th>Vendor</th>
                        <th>Added</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($products as $p): ?>
                    <tr>
                        <td>
                            <div class="cell-media">
                                <?= ui_thumb($p['image'] ?? null, (string)$p['title']) ?>
                                <span class="t"><?= htmlspecialchars($p['title']) ?></span>
                            </div>
                        </td>
                        <td>
                            <?php if ($p['category']): ?>
                                <?= ui_badge('neutral', $p['category'], false) ?>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
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
                        <td>
                          <?php $on = (int)($p['is_active'] ?? 1) === 1; ?>
                          <?= $on ? ui_badge('paid', 'Active', false) : ui_badge('cancelled', 'Inactive', false) ?>
                        </td>
                        <td>
                          <?php if (!empty($p['seller_id'])): ?>
                            <?php if (($p['seller_role'] ?? '') === 'seller'): ?>
                              <a href="<?= APP_BASE_URL ?>/admin/vendor_view.php?id=<?= (int)$p['seller_id'] ?>"><?= htmlspecialchars($p['seller_name'] ?? 'Vendor') ?></a>
                            <?php else: ?>
                              <a href="<?= APP_BASE_URL ?>/admin/user_view.php?id=<?= (int)$p['seller_id'] ?>"><?= htmlspecialchars($p['seller_name'] ?? 'Owner') ?></a>
                            <?php endif; ?>
                          <?php else: ?>
                            <span class="text-muted">—</span>
                          <?php endif; ?>
                        </td>
                        <td class="text-muted"><?= date('M j, Y', strtotime($p['created_at'])) ?></td>
                        <td class="table-actions">
                            <a href="<?= APP_BASE_URL ?>/admin/edit_product.php?id=<?= (int)$p['id'] ?>"
                               class="btn btn-secondary btn-sm"><?= ui_icon('pencil', 'icn-sm') ?> Edit</a>
                            <form method="POST">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="product_id" value="<?= (int)$p['id'] ?>">
                                <button type="submit" name="listing_action" value="<?= $on ? 'deactivate' : 'activate' ?>"
                                        class="btn btn-secondary btn-sm"
                                        data-confirm="<?= $on ? 'Deactivate this product?' : 'Activate this product?' ?>">
                                  <?= $on ? 'Deactivate' : 'Activate' ?>
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
