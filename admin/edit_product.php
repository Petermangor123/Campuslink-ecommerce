<?php
// CampusLink – Admin: edit any product (ownership preserved)
require_once __DIR__ . '/../includes/auth_guard.php';
require_once __DIR__ . '/../includes/ui.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/admin_vendor.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST') { verify_csrf(); }
require_role('admin');

$pid = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$returnVendorId = (int)($_GET['vendor_id'] ?? $_POST['return_vendor_id'] ?? 0);

$stmt = $conn->prepare(
    'SELECT p.*, u.name AS seller_name, u.role AS seller_role
     FROM products p LEFT JOIN users u ON u.id = p.seller_id
     WHERE p.id = ?'
);
$stmt->bind_param('i', $pid);
$stmt->execute();
$product = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$product) {
    set_flash('error', 'Product not found.');
    redirect(APP_BASE_URL . '/admin/products.php');
}

$ownerId = (int)$product['seller_id'];
if ($returnVendorId < 1 && ($product['seller_role'] ?? '') === 'seller') {
    $returnVendorId = $ownerId;
}

$cats   = $conn->query('SELECT id, name FROM categories ORDER BY name')->fetch_all(MYSQLI_ASSOC);
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title       = trim($_POST['title']       ?? '');
    $description = trim($_POST['description'] ?? '');
    $price       = trim($_POST['price']       ?? '');
    $stock       = trim($_POST['stock']       ?? '');
    $category_id = trim($_POST['category_id'] ?? '');
    $isActive    = isset($_POST['is_active']) && (string)$_POST['is_active'] === '1' ? 1 : 0;

    if ($title === '') {
        $errors[] = 'Product name is required.';
    }
    if (!is_numeric($price) || (float)$price <= 0) {
        $errors[] = 'Enter a valid price greater than 0.';
    }
    if (!ctype_digit($stock) || (int)$stock < 0) {
        $errors[] = 'Stock must be a whole number (0 or more).';
    }

    $image_path = $product['image'];
    if (!empty($_FILES['image']['name'])) {
        $uploadError = null;
        $storedName  = sec_store_image($_FILES['image'], __DIR__ . '/../assets/uploads/', $uploadError);
        if ($storedName === null) {
            $errors[] = $uploadError ?: 'Image could not be uploaded.';
        } else {
            if ($product['image']) {
                $old = __DIR__ . '/../' . $product['image'];
                if (is_file($old)) {
                    @unlink($old);
                }
            }
            $image_path = 'assets/uploads/' . $storedName;
        }
    }

    if (empty($errors)) {
        $before = admin_product_snapshot($product);
        $cat  = $category_id !== '' ? (int)$category_id : null;
        $desc = $description !== '' ? $description : null;
        $pv   = (float)$price;
        $sv   = (int)$stock;

        // Never change seller_id
        $stmt = $conn->prepare(
            'UPDATE products
             SET category_id=?, title=?, description=?, price=?, stock=?, is_active=?, image=?
             WHERE id=? AND seller_id=?'
        );
        $stmt->bind_param('issdiisii', $cat, $title, $desc, $pv, $sv, $isActive, $image_path, $pid, $ownerId);
        $stmt->execute();
        $stmt->close();

        $after = [
            'id' => $pid,
            'seller_id' => $ownerId,
            'category_id' => $cat,
            'title' => $title,
            'description' => (string)$desc,
            'price' => $pv,
            'stock' => $sv,
            'is_active' => $isActive,
            'image' => (string)$image_path,
        ];
        $auditAction = 'product_update';
        if ((int)$before['is_active'] !== $isActive) {
            $auditAction = $isActive ? 'product_activate' : 'product_deactivate';
            // Still log full update details in after/before; if only status changed among other edits, product_update is fine — prefer product_update when other fields change
            if ($before['title'] !== $title || (float)$before['price'] !== $pv || (int)$before['stock'] !== $sv
                || (string)$before['description'] !== (string)$desc || (string)$before['image'] !== (string)$image_path
                || (int)($before['category_id'] ?? 0) !== (int)($cat ?? 0)) {
                $auditAction = 'product_update';
            }
        }
        admin_audit_log(
            $conn,
            current_user_id(),
            ($product['seller_role'] ?? '') === 'seller' ? $ownerId : null,
            $pid,
            $auditAction,
            $before,
            $after
        );

        set_flash('success', 'Product updated. Ownership unchanged.');
        if ($returnVendorId > 0) {
            redirect(APP_BASE_URL . '/admin/vendor_view.php?id=' . $returnVendorId);
        }
        redirect(APP_BASE_URL . '/admin/products.php');
    }
} else {
    $title       = $product['title'];
    $description = $product['description'] ?? '';
    $price       = $product['price'];
    $stock       = $product['stock'];
    $category_id = $product['category_id'] ?? '';
    $isActive    = (int)($product['is_active'] ?? 1);
}
?>
<?php
$dashTitle   = 'Edit product';
$dashSub     = (string)$product['title'];
$dashActions = '<a href="' . APP_BASE_URL . '/public/product.php?id=' . (int)$pid . '" class="btn btn-secondary btn-sm">'
             . ui_icon('external', 'icn-sm') . ' View in store</a>';
require_once __DIR__ . '/../includes/header.php';
?>

<?php
  $crumbs = $returnVendorId > 0
      ? [
          ['Vendors', APP_BASE_URL . '/admin/vendors.php'],
          [(string)$product['seller_name'], APP_BASE_URL . '/admin/vendor_view.php?id=' . $returnVendorId],
          ['Edit product', null],
        ]
      : [
          ['Products', APP_BASE_URL . '/admin/products.php'],
          ['Edit product', null],
        ];
  echo ui_breadcrumbs($crumbs);
?>

<div class="card" style="max-width:720px;">
    <?php if (!empty($errors)): ?>
        <div class="form-errors">
            <strong>Please fix the following:</strong>
            <ul>
                <?php foreach ($errors as $err): ?>
                    <li><?= htmlspecialchars($err) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <p class="form-hint" style="margin-top:0;">
      <?= ui_icon('user', 'icn-xs') ?>
      Listed by <strong><?= htmlspecialchars($product['seller_name'] ?? 'Unknown') ?></strong>
      · seller_id <code><?= (int)$ownerId ?></code> (not changed on save)
    </p>

    <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="id" value="<?= (int)$pid ?>">
        <input type="hidden" name="return_vendor_id" value="<?= (int)$returnVendorId ?>">

        <div class="form-group">
            <label for="ep-title">Product name <span aria-hidden="true">*</span></label>
            <input type="text" id="ep-title" name="title"
                   value="<?= htmlspecialchars($title) ?>" required>
        </div>

        <div class="form-group">
            <label for="ep-category">Category</label>
            <select id="ep-category" name="category_id">
                <option value="">— Select a category —</option>
                <?php foreach ($cats as $c): ?>
                    <option value="<?= (int)$c['id'] ?>"
                        <?= $category_id == $c['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($c['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <label for="ep-desc">Description</label>
            <textarea id="ep-desc" name="description"><?= htmlspecialchars($description) ?></textarea>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label for="ep-price">Price (GHC) <span aria-hidden="true">*</span></label>
                <input type="number" id="ep-price" name="price" step="0.01" min="0.01"
                       value="<?= htmlspecialchars((string)$price) ?>" required>
            </div>
            <div class="form-group">
                <label for="ep-stock">Stock quantity <span aria-hidden="true">*</span></label>
                <input type="number" id="ep-stock" name="stock" min="0"
                       value="<?= htmlspecialchars((string)$stock) ?>" required>
            </div>
        </div>

        <div class="form-group">
            <label for="ep-active">Product status</label>
            <select id="ep-active" name="is_active">
                <option value="1" <?= (int)$isActive === 1 ? 'selected' : '' ?>>Active (visible in marketplace)</option>
                <option value="0" <?= (int)$isActive === 0 ? 'selected' : '' ?>>Inactive (hidden from marketplace)</option>
            </select>
        </div>

        <div class="form-group">
            <label for="ep-image">Product image <span class="text-muted">(leave blank to keep current)</span></label>
            <div class="upload-field">
                <div class="upload-preview<?= $product['image'] ? ' has-image' : '' ?>" data-img-preview-target>
                    <img alt="Selected image preview"
                         <?php if ($product['image']): ?>src="<?= APP_BASE_URL ?>/<?= htmlspecialchars($product['image']) ?>"<?php endif; ?>>
                    <span class="upload-empty"><?= ui_icon('image', 'icn-lg', 'width="26" height="26"') ?> No image selected</span>
                </div>
                <input type="file" id="ep-image" name="image" accept="image/jpeg,image/png,image/webp,image/gif" data-img-preview="[data-img-preview-target]">
            </div>
            <p class="form-hint">JPEG, PNG, WebP or GIF · Max 5 MB.</p>
        </div>

        <div style="display:flex;gap:.75rem;flex-wrap:wrap;margin-top:1.5rem;">
            <button type="submit" class="btn btn-primary"><?= ui_icon('save', 'icn-sm') ?> Save changes</button>
            <?php if ($returnVendorId > 0): ?>
              <a href="<?= APP_BASE_URL ?>/admin/vendor_view.php?id=<?= (int)$returnVendorId ?>" class="btn btn-secondary">Cancel</a>
            <?php else: ?>
              <a href="<?= APP_BASE_URL ?>/admin/products.php" class="btn btn-secondary">Cancel</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
