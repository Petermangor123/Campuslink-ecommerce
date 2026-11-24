<?php
// CampusLink – Edit product
require_once __DIR__ . '/../includes/auth_guard.php';
require_once __DIR__ . '/../includes/ui.php';
require_once __DIR__ . '/../config/db.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST') { verify_csrf(); }
require_role('seller');

$uid = current_user_id();
$pid = (int)($_GET['id'] ?? 0);

$stmt = $conn->prepare('SELECT * FROM products WHERE id=? AND seller_id=?');
$stmt->bind_param('ii',$pid,$uid); $stmt->execute();
$product = $stmt->get_result()->fetch_assoc(); $stmt->close();

if (!$product) {
    set_flash('error','Product not found.');
    redirect(APP_BASE_URL . '/seller/my_products.php');
}

$cats   = $conn->query('SELECT id,name FROM categories ORDER BY name')->fetch_all(MYSQLI_ASSOC);
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title       = trim($_POST['title']       ?? '');
    $description = trim($_POST['description'] ?? '');
    $price       = trim($_POST['price']       ?? '');
    $stock       = trim($_POST['stock']       ?? '');
    $category_id = trim($_POST['category_id'] ?? '');

    if ($title === '')                               $errors[] = 'Title is required.';
    if (!is_numeric($price)||(float)$price<=0)      $errors[] = 'Enter a valid price.';
    if (!ctype_digit($stock)||(int)$stock<0)        $errors[] = 'Stock must be a whole number ≥ 0.';

    // Only replace image after a successful validated upload. Otherwise leave DB image untouched.
    $replaceImage = false;
    $image_path   = null;
    if (!empty($_FILES['image']['name'])) {
        $uploadError = null;
        $storedName  = sec_store_image($_FILES['image'], __DIR__ . '/../assets/uploads/', $uploadError);
        if ($storedName === null) {
            $errors[] = $uploadError ?: 'Image could not be uploaded.';
        } else {
            $image_path = 'assets/uploads/' . $storedName;
            $replaceImage = true;
        }
    }

    if (empty($errors)) {
        $cat  = $category_id !== '' ? (int)$category_id : null;
        $desc = $description !== '' ? $description : null;
        $pv   = (float)$price;
        $sv   = (int)$stock;
        // Placeholders: category_id(i), title(s), description(s), price(d), stock(i),
        // optional image(s), then id(i), seller_id(i).
        if ($replaceImage) {
            $stmt = $conn->prepare(
                'UPDATE products SET category_id=?, title=?, description=?, price=?, stock=?, image=?
                 WHERE id=? AND seller_id=?'
            );
            $stmt->bind_param('issdisii', $cat, $title, $desc, $pv, $sv, $image_path, $pid, $uid);
        } else {
            $stmt = $conn->prepare(
                'UPDATE products SET category_id=?, title=?, description=?, price=?, stock=?
                 WHERE id=? AND seller_id=?'
            );
            $stmt->bind_param('issdiii', $cat, $title, $desc, $pv, $sv, $pid, $uid);
        }
        $stmt->execute();
        $stmt->close();
        set_flash('success', 'Product updated.');
        redirect(APP_BASE_URL . '/seller/my_products.php');
    }
} else {
    $title       = $product['title'];
    $description = $product['description'] ?? '';
    $price       = $product['price'];
    $stock       = $product['stock'];
    $category_id = $product['category_id'] ?? '';
}
?>
<?php
$dashTitle   = 'Edit Product';
$dashSub     = (string)$product['title'];
$dashActions = '<a href="' . APP_BASE_URL . '/public/product.php?id=' . (int)$pid . '" class="btn btn-secondary btn-sm">'
             . ui_icon('external', 'icn-sm') . ' View in Marketplace</a>';
require_once __DIR__ . '/../includes/header.php';
?>

<?= ui_breadcrumbs([
      ['My Products',  APP_BASE_URL . '/seller/my_products.php'],
      ['Edit Product', null],
    ]) ?>

<div class="card" style="max-width:720px;">
    <?php if (!empty($errors)): ?>
        <div class="form-errors">
            <strong>Please fix the following:</strong>
            <ul>
                <?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

        <div class="form-group">
            <label for="ep-title">Product <span aria-hidden="true">*</span></label>
            <input type="text" id="ep-title" name="title" value="<?= htmlspecialchars($title) ?>" required>
        </div>

        <div class="form-group">
            <label for="ep-category">Category</label>
            <select id="ep-category" name="category_id">
                <option value="">— Select category —</option>
                <?php foreach ($cats as $c): ?>
                    <option value="<?= (int)$c['id'] ?>" <?= $category_id == $c['id'] ? 'selected' : '' ?>>
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
                       value="<?= htmlspecialchars($price) ?>" required>
            </div>
            <div class="form-group">
                <label for="ep-stock">Stock <span aria-hidden="true">*</span></label>
                <input type="number" id="ep-stock" name="stock" min="0"
                       value="<?= htmlspecialchars($stock) ?>" required>
            </div>
        </div>

        <div class="form-group">
            <?php $currentImage = ui_product_image_path($product['image'] ?? null); ?>
            <label for="ep-image">Product image <span class="text-muted">(leave blank to keep current)</span></label>
            <div class="upload-field">
                <div class="upload-preview<?= $currentImage ? ' has-image' : '' ?>" data-img-preview-target>
                    <img alt="Selected image preview"
                         <?php if ($currentImage): ?>src="<?= APP_BASE_URL ?>/<?= htmlspecialchars($currentImage) ?>"<?php endif; ?>>
                    <span class="upload-empty"><?= ui_icon('image', 'icn-lg', 'width="26" height="26"') ?> No image selected</span>
                </div>
                <input type="file" id="ep-image" name="image" accept="image/jpeg,image/png,image/webp,image/gif" data-img-preview="[data-img-preview-target]">
            </div>
            <p class="form-hint">JPEG, PNG, WebP or GIF · Max 5 MB. A clear, well-lit photo sells faster.</p>
        </div>

        <div style="display:flex;gap:.75rem;flex-wrap:wrap;">
            <button class="btn btn-primary"><?= ui_icon('save', 'icn-sm') ?> Save changes</button>
            <a href="<?= APP_BASE_URL ?>/seller/my_products.php" class="btn btn-secondary">Cancel</a>
        </div>
    </form>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
