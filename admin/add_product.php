<?php
// CampusLink – Admin: add product on behalf of a selected vendor
require_once __DIR__ . '/../includes/auth_guard.php';
require_once __DIR__ . '/../includes/ui.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/admin_vendor.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST') { verify_csrf(); }
require_role('admin');

$vendorId = (int)($_GET['vendor_id'] ?? $_POST['vendor_id'] ?? 0);

// Vendor picker when none selected
if ($vendorId < 1 && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $vendors = $conn->query(
        "SELECT id, name, email, is_active, vendor_status FROM users WHERE role='seller' ORDER BY name ASC"
    )->fetch_all(MYSQLI_ASSOC);
    $dashTitle = 'Add Product';
    $dashSub = 'Select a vendor first — ownership will belong to that vendor';
    $dashActions = '<a href="' . APP_BASE_URL . '/admin/vendors.php" class="btn btn-secondary btn-sm">'
                 . ui_icon('arrow-left', 'icn-sm') . ' Vendor Management</a>';
    require_once __DIR__ . '/../includes/header.php';
    ?>
    <?= ui_breadcrumbs([
          ['Vendor Management', APP_BASE_URL . '/admin/vendors.php'],
          ['Add Product', null],
        ]) ?>
    <div class="card" style="max-width:640px;">
      <div class="card-title">Choose vendor</div>
      <p class="text-muted">Products created here are never owned by the administrator account.</p>
      <?php if (empty($vendors)): ?>
        <?= ui_empty('store', 'No vendors registered', 'A vendor must register before you can add products for them.', '', true) ?>
      <?php else: ?>
        <form method="GET">
          <div class="form-group">
            <label for="vendor_id">Vendor <span aria-hidden="true">*</span></label>
            <select id="vendor_id" name="vendor_id" required>
              <option value="">— Select vendor —</option>
              <?php foreach ($vendors as $v): ?>
                <option value="<?= (int)$v['id'] ?>">
                  <?= htmlspecialchars($v['name'] . ' · ' . $v['email']) ?>
                  (<?= htmlspecialchars((string)($v['vendor_status'] ?? 'pending')) ?><?= (int)$v['is_active'] === 1 ? '' : ', inactive account' ?>)
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <button type="submit" class="btn btn-primary">Continue</button>
        </form>
      <?php endif; ?>
    </div>
    <?php
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

$vendor = admin_require_vendor($conn, $vendorId);

$cats   = $conn->query('SELECT id, name FROM categories ORDER BY name')->fetch_all(MYSQLI_ASSOC);
$errors = [];
$input  = ['title' => '', 'description' => '', 'price' => '', 'stock' => '1', 'category_id' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Re-validate posted vendor (never trust client alone)
    $vendorId = (int)($_POST['vendor_id'] ?? 0);
    $vendor = admin_require_vendor($conn, $vendorId);

    $input['title']       = trim($_POST['title']       ?? '');
    $input['description'] = trim($_POST['description'] ?? '');
    $input['price']       = trim($_POST['price']       ?? '');
    $input['stock']       = trim($_POST['stock']       ?? '1');
    $input['category_id'] = trim($_POST['category_id'] ?? '');

    if ($input['title'] === '') {
        $errors[] = 'Product name is required.';
    }
    if (!is_numeric($input['price']) || (float)$input['price'] <= 0) {
        $errors[] = 'Enter a valid price greater than 0.';
    }
    if (!ctype_digit($input['stock']) || (int)$input['stock'] < 0) {
        $errors[] = 'Stock must be a whole number (0 or more).';
    }

    $image_path = null;
    if (!empty($_FILES['image']['name'])) {
        $uploadError = null;
        $storedName  = sec_store_image($_FILES['image'], __DIR__ . '/../assets/uploads/', $uploadError);
        if ($storedName === null) {
            $errors[] = $uploadError ?: 'Image could not be uploaded.';
        } else {
            $image_path = 'assets/uploads/' . $storedName;
        }
    }

    if (empty($errors)) {
        $cat   = $input['category_id'] !== '' ? (int)$input['category_id'] : null;
        $sid   = (int)$vendor['id']; // ownership = selected vendor, never admin
        $title = $input['title'];
        $desc  = $input['description'] !== '' ? $input['description'] : null;
        $price = (float)$input['price'];
        $stock = (int)$input['stock'];
        $active = 1;

        $stmt = $conn->prepare(
            'INSERT INTO products (seller_id, category_id, title, description, price, stock, is_active, image)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->bind_param('iissdiis', $sid, $cat, $title, $desc, $price, $stock, $active, $image_path);
        $stmt->execute();
        $newId = (int)$conn->insert_id;
        $stmt->close();

        $after = [
            'id' => $newId,
            'seller_id' => $sid,
            'category_id' => $cat,
            'title' => $title,
            'description' => (string)$desc,
            'price' => $price,
            'stock' => $stock,
            'is_active' => 1,
            'image' => (string)$image_path,
        ];
        admin_audit_log($conn, current_user_id(), $sid, $newId, 'product_create', null, $after);

        set_flash('success', 'Product "' . $title . '" added for ' . $vendor['name'] . '.');
        redirect(APP_BASE_URL . '/admin/vendor_view.php?id=' . $sid);
    }
}
?>
<?php
$dashTitle   = 'Add Product';
$dashSub     = 'On behalf of ' . $vendor['name'];
$dashActions = '<a href="' . APP_BASE_URL . '/admin/vendor_view.php?id=' . (int)$vendorId . '" class="btn btn-secondary btn-sm">'
             . ui_icon('arrow-left', 'icn-sm') . ' Back to vendor</a>';
require_once __DIR__ . '/../includes/header.php';
?>

<?= ui_breadcrumbs([
      ['Vendor Management', APP_BASE_URL . '/admin/vendors.php'],
      [$vendor['name'], APP_BASE_URL . '/admin/vendor_view.php?id=' . (int)$vendorId],
      ['Add Product', null],
    ]) ?>

<div class="card" style="max-width:720px;">
    <p class="form-hint" style="margin-top:0;">
      <?= ui_icon('store', 'icn-xs') ?>
      Product owner: <strong><?= htmlspecialchars($vendor['name']) ?></strong>
      <span class="text-muted">(<?= htmlspecialchars($vendor['email']) ?>)</span>
      — ownership stays with this vendor, not the administrator.
    </p>

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

    <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="vendor_id" value="<?= (int)$vendorId ?>">

        <div class="form-group">
            <label for="ap-title">Product name <span aria-hidden="true">*</span></label>
            <input type="text" id="ap-title" name="title"
                   value="<?= htmlspecialchars($input['title']) ?>"
                   placeholder="e.g. Introduction to Algorithms – 3rd Ed."
                   required autofocus>
        </div>

        <div class="form-group">
            <label for="ap-category">Category</label>
            <select id="ap-category" name="category_id">
                <option value="">— Select a category —</option>
                <?php foreach ($cats as $c): ?>
                    <option value="<?= (int)$c['id'] ?>"
                        <?= $input['category_id'] == $c['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($c['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <label for="ap-desc">Description</label>
            <textarea id="ap-desc" name="description"
                      placeholder="Condition, edition, any relevant notes…"><?= htmlspecialchars($input['description']) ?></textarea>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label for="ap-price">Price (GHC) <span aria-hidden="true">*</span></label>
                <input type="number" id="ap-price" name="price" step="0.01" min="0.01"
                       value="<?= htmlspecialchars($input['price']) ?>"
                       placeholder="0.00" required>
            </div>
            <div class="form-group">
                <label for="ap-stock">Stock quantity <span aria-hidden="true">*</span></label>
                <input type="number" id="ap-stock" name="stock" min="0"
                       value="<?= htmlspecialchars($input['stock']) ?>"
                       required>
            </div>
        </div>

        <div class="form-group">
            <label for="ap-image">Product image <span class="text-muted">(optional)</span></label>
            <div class="upload-field">
                <div class="upload-preview" data-img-preview-target>
                    <img alt="Selected image preview">
                    <span class="upload-empty"><?= ui_icon('image', 'icn-lg', 'width="26" height="26"') ?> No image selected</span>
                </div>
                <input type="file" id="ap-image" name="image" accept="image/jpeg,image/png,image/webp,image/gif" data-img-preview="[data-img-preview-target]">
            </div>
            <p class="form-hint">JPEG, PNG, WebP or GIF · Max 5 MB.</p>
        </div>

        <div style="display:flex;gap:.75rem;flex-wrap:wrap;margin-top:1.5rem;">
            <button type="submit" class="btn btn-primary"><?= ui_icon('check', 'icn-sm') ?> Add for vendor</button>
            <a href="<?= APP_BASE_URL ?>/admin/vendor_view.php?id=<?= (int)$vendorId ?>" class="btn btn-secondary">Cancel</a>
        </div>
    </form>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
