<?php
// CampusLink – Add product
require_once __DIR__ . '/../includes/auth_guard.php';
require_once __DIR__ . '/../includes/ui.php';
require_once __DIR__ . '/../config/db.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST') { verify_csrf(); }
require_role('seller');

$cats   = $conn->query('SELECT id,name FROM categories ORDER BY name')->fetch_all(MYSQLI_ASSOC);
$errors = [];
$input  = ['title'=>'','description'=>'','price'=>'','stock'=>'1','category_id'=>''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input['title']       = trim($_POST['title']       ?? '');
    $input['description'] = trim($_POST['description'] ?? '');
    $input['price']       = trim($_POST['price']       ?? '');
    $input['stock']       = trim($_POST['stock']       ?? '1');
    $input['category_id'] = trim($_POST['category_id'] ?? '');

    if ($input['title'] === '')
        $errors[] = 'Title is required.';
    if (!is_numeric($input['price']) || (float)$input['price'] <= 0)
        $errors[] = 'Enter a valid price greater than 0.';
    if (!ctype_digit($input['stock']) || (int)$input['stock'] < 0)
        $errors[] = 'Stock must be a whole number.';

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
        $sid   = current_user_id();
        $title = $input['title'];
        $desc  = $input['description'] !== '' ? $input['description'] : null;
        $price = (float)$input['price'];
        $stock = (int)$input['stock'];

        $active = 1;
        $stmt = $conn->prepare(
            'INSERT INTO products (seller_id,category_id,title,description,price,stock,is_active,image)
             VALUES (?,?,?,?,?,?,?,?)'
        );
        $stmt->bind_param('iissdiis', $sid, $cat, $title, $desc, $price, $stock, $active, $image_path);
        $stmt->execute(); $stmt->close();

        set_flash('success','Product listed successfully!');
        redirect(APP_BASE_URL . '/seller/my_products.php');
    }
}
?>
<?php
$dashTitle   = 'Add Product';
$dashSub     = 'Create a new marketplace product';
$dashActions = '<a href="' . APP_BASE_URL . '/seller/my_products.php" class="btn btn-secondary btn-sm">'
             . ui_icon('arrow-left', 'icn-sm') . ' My Products</a>';
require_once __DIR__ . '/../includes/header.php';
?>

<?= ui_breadcrumbs([
      ['Vendor Dashboard', APP_BASE_URL . '/seller/dashboard.php'],
      ['My Products',      APP_BASE_URL . '/seller/my_products.php'],
      ['Add Product',      null],
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
            <label for="ap-title">Product <span aria-hidden="true">*</span></label>
            <input type="text" id="ap-title" name="title" value="<?= htmlspecialchars($input['title']) ?>"
                   placeholder="e.g. Introduction to Algorithms – 3rd Ed." required>
        </div>

        <div class="form-group">
            <label for="ap-category">Category</label>
            <select id="ap-category" name="category_id">
                <option value="">— Select category —</option>
                <?php foreach ($cats as $c): ?>
                    <option value="<?= (int)$c['id'] ?>" <?= $input['category_id'] == $c['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($c['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <label for="ap-desc">Description</label>
            <textarea id="ap-desc" name="description" placeholder="Condition, edition, any notes…"><?= htmlspecialchars($input['description']) ?></textarea>
            <p class="form-hint">Mention condition, edition, included accessories or pickup location.</p>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label for="ap-price">Price (GHC) <span aria-hidden="true">*</span></label>
                <input type="number" id="ap-price" name="price" step="0.01" min="0.01"
                       value="<?= htmlspecialchars($input['price']) ?>" placeholder="0.00" required>
            </div>
            <div class="form-group">
                <label for="ap-stock">Stock <span aria-hidden="true">*</span></label>
                <input type="number" id="ap-stock" name="stock" min="0"
                       value="<?= htmlspecialchars($input['stock']) ?>" required>
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
            <p class="form-hint">JPEG, PNG, WebP or GIF · Max 5 MB. A clear, well-lit photo sells faster.</p>
        </div>

        <div style="display:flex;gap:.75rem;flex-wrap:wrap;">
            <button class="btn btn-primary"><?= ui_icon('check', 'icn-sm') ?> List product</button>
            <a href="<?= APP_BASE_URL ?>/seller/my_products.php" class="btn btn-secondary">Cancel</a>
        </div>
    </form>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
