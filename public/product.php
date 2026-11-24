<?php
// CampusLink – Product detail page
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/cart.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST') { verify_csrf(); }

$id = (int)($_GET['id'] ?? 0);

$stmt = $conn->prepare(
    'SELECT p.*, c.name AS category, u.name AS seller_name
     FROM products p
     LEFT JOIN categories c ON c.id = p.category_id
     LEFT JOIN users u ON u.id = p.seller_id
     WHERE p.id = ?'
);
$stmt->bind_param('i', $id);
$stmt->execute();
$product = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$product) {
    set_flash('error', 'Product not found.');
    redirect(APP_BASE_URL . '/public/browse.php');
}

$isOwnerOrAdmin = is_logged_in() && (
    current_user_role() === 'admin'
    || ((int)$product['seller_id'] === current_user_id() && current_user_role() === 'seller')
);
if ((int)($product['is_active'] ?? 1) !== 1 && !$isOwnerOrAdmin) {
    set_flash('error', 'This product is no longer available.');
    redirect(APP_BASE_URL . '/public/browse.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $qty = max(1, (int)($_POST['qty'] ?? 1));
    $pid = (int)$product['id'];

    if (!is_logged_in()) {
        $result = add_guest_item($conn, $pid, $qty);
        set_flash($result['ok'] ? 'success' : 'error', $result['message']);
        redirect(APP_BASE_URL . '/public/product.php?id=' . $pid);
    }

    if (current_user_role() !== 'buyer') {
        set_flash('error', 'Only customer accounts can add items to a cart.');
        redirect(APP_BASE_URL . '/public/product.php?id=' . $pid);
    }

    $result = add_to_cart($conn, current_user_id(), $pid, $qty);
    set_flash($result['ok'] ? 'success' : 'error', $result['message']);
    redirect(APP_BASE_URL . '/public/product.php?id=' . $pid);
}

// Related listings: same category, still available, excluding this item.
$related = [];
if (!empty($product['category_id'])) {
    $rs = $conn->prepare(
        'SELECT p.id, p.seller_id, p.title, p.price, p.image, p.stock, c.name AS category, u.name AS seller
         FROM products p
         LEFT JOIN categories c ON c.id = p.category_id
         LEFT JOIN users u ON u.id = p.seller_id
         WHERE p.category_id = ? AND p.id <> ? AND p.stock > 0 AND p.is_active = 1
         ORDER BY p.created_at DESC LIMIT 4'
    );
    $rs->bind_param('ii', $product['category_id'], $id);
    $rs->execute();
    $related = $rs->get_result()->fetch_all(MYSQLI_ASSOC);
    $rs->close();
}

$stock        = (int)$product['stock'];
$isOwnListing = is_logged_in() && (int)$product['seller_id'] === current_user_id();
$canBuy       = $stock > 0 && (!is_logged_in() || current_user_role() === 'buyer') && !$isOwnListing;
$sellerName   = (string)($product['seller_name'] ?? 'CampusLink seller');
$initials     = strtoupper(mb_substr(trim($sellerName) ?: 'S', 0, 1));
$listedOn     = date('M j, Y', strtotime((string)$product['created_at']));
?>
<?php require_once __DIR__ . '/../includes/header.php'; ?>

<?= ui_breadcrumbs([
    ['Home', APP_BASE_URL . '/'],
    ['Browse', APP_BASE_URL . '/public/browse.php'],
    [($product['category'] ?? 'Other'), APP_BASE_URL . '/public/browse.php?category=' . urlencode((string)($product['category'] ?? 'Other'))],
    [(string)$product['title'], null],
]) ?>

<div class="pdp">
    <!-- Gallery -->
    <div class="pdp-media">
        <?php
          $pdpImage = function_exists('ui_product_image_path')
              ? ui_product_image_path($product['image'] ?? null)
              : null;
        ?>
        <?php if ($pdpImage !== null): ?>
            <img src="<?= APP_BASE_URL ?>/<?= e($pdpImage) ?>"
                 alt="<?= e($product['title']) ?>" width="900" height="900" decoding="async">
        <?php else: ?>
            <?php
              $fallbackCat = (string)($product['category'] ?? 'Other');
              $fallbackPhoto = ui_category_photo($fallbackCat);
            ?>
            <div class="pdp-ph pdp-ph-photo">
              <img src="<?= APP_BASE_URL ?>/<?= e($fallbackPhoto) ?>" alt="" width="900" height="900" loading="lazy" decoding="async">
              <span class="pdp-ph-label">No seller photo yet · <?= e($fallbackCat) ?> placeholder</span>
            </div>
        <?php endif; ?>
    </div>

    <!-- Details -->
    <div class="pdp-info">
        <div class="pdp-cat"><?= ui_icon(ui_category_icon((string)($product['category'] ?? '')), 'icn-xs') ?>
            <?= e($product['category'] ?? 'Other') ?></div>
        <h1 class="pdp-title"><?= e($product['title']) ?></h1>
        <div class="pdp-price"><?= format_currency($product['price']) ?></div>

        <?php if ($stock > 0): ?>
            <span class="badge badge-delivered">In stock</span>
        <?php else: ?>
            <span class="badge badge-cancelled">Out of stock</span>
        <?php endif; ?>

        <div class="pdp-facts">
            <div class="fact">
                <div class="k">Availability</div>
                <div class="v">
                    <?php if ($stock > 0): ?>
                        <span class="ok"><?= ui_icon('check', 'icn-xs') ?> <?= (int)$stock ?> available</span>
                    <?php else: ?>
                        <span class="no"><?= ui_icon('x', 'icn-xs') ?> Unavailable</span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="fact">
                <div class="k">Category</div>
                <div class="v"><?= e($product['category'] ?? 'Other') ?></div>
            </div>
            <div class="fact">
                <div class="k">Listed</div>
                <div class="v"><?= e($listedOn) ?></div>
            </div>
        </div>

        <?php if (!empty($product['description'])): ?>
            <h2 class="pdp-subhead">Description</h2>
            <p class="pdp-desc"><?= nl2br(e($product['description'])) ?></p>
        <?php endif; ?>

        <!-- Seller -->
        <div class="seller-card">
            <span class="avatar avatar-lg" aria-hidden="true"><?= e($initials) ?></span>
            <div class="info">
                <div class="lbl">Sold by</div>
                <div class="nm"><?= e($sellerName) ?></div>
                <div class="sub"><a href="<?= APP_BASE_URL ?>/public/browse.php?seller=<?= rawurlencode($sellerName) ?>">View this vendor’s products</a></div>
            </div>
        </div>

        <!-- Purchase -->
        <div class="pdp-buy">
            <?php if ($isOwnListing): ?>
                <p class="text-muted" style="margin:0;">
                    <?= ui_icon('info', 'icn-sm') ?> This is your own product, so it can’t be added to your cart.
                </p>
            <?php elseif ($stock < 1): ?>
                <p class="text-muted" style="margin:0;">
                    <?= ui_icon('info', 'icn-sm') ?> This item is currently unavailable.
                </p>
            <?php elseif ($canBuy): ?>
                <form method="POST" class="pdp-buy-row">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <div class="qty-control" data-qty>
                        <button type="button" data-qty-step="-1" aria-label="Decrease quantity"><?= ui_icon('minus', 'icn-xs') ?></button>
                        <label class="sr-only" for="qty">Quantity</label>
                        <input type="number" id="qty" name="qty" value="1" min="1" max="<?= $stock ?>">
                        <button type="button" data-qty-step="1" aria-label="Increase quantity"><?= ui_icon('plus', 'icn-xs') ?></button>
                    </div>
                    <button class="btn btn-primary btn-lg btn-cart">
                        <?= ui_icon('cart', 'icn-sm') ?> Add to cart
                    </button>
                </form>
            <?php else: ?>
                <p class="text-muted" style="margin:0;">
                    <?= ui_icon('info', 'icn-sm') ?> Sign in with a customer account to buy this item.
                    <a href="<?= APP_BASE_URL ?>/auth/login.php">Sign in</a>
                </p>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($related): ?>
    <div class="sec-hdr" style="margin-top:var(--s-7);">
        <div>
            <h2>More in <?= e($product['category'] ?? 'this category') ?></h2>
            <p>Other products from the same category.</p>
        </div>
        <a href="<?= APP_BASE_URL ?>/public/browse.php?category=<?= urlencode((string)($product['category'] ?? '')) ?>">
            See all <?= ui_icon('arrow-right', 'icn-xs') ?>
        </a>
    </div>
    <div class="product-grid">
        <?php foreach ($related as $p) echo ui_product_card($p); ?>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
