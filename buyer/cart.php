<?php
// CampusLink – Shopping cart for buyers and guests
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/cart.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST') { verify_csrf(); }

if (is_logged_in() && current_user_role() !== 'buyer') {
    require_once __DIR__ . '/../includes/header.php';
    echo '<h1 class="page-title">My Cart</h1><div class="card"><p>Vendor and admin accounts cannot purchase products, including their own. Sign out to shop as a guest, or use a customer account.</p></div>';
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_product'])) {
    $pid = (int)$_POST['remove_product'];
    if ($pid > 0) {
        cart_remove($conn, $pid);
        set_flash('success', 'Item removed.');
    }
    redirect(APP_BASE_URL . '/buyer/cart.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update'])) {
    cart_update($conn, $_POST['qty'] ?? []);
    set_flash('success', 'Cart updated.');
    redirect(APP_BASE_URL . '/buyer/cart.php');
}

$items = cart_items($conn);
$total = array_reduce($items, fn($s, $i) => $s + $i['price'] * $i['quantity'], 0.0);
?>
<?php require_once __DIR__ . '/../includes/header.php'; ?>

<div class="page-bar">
    <div>
        <h1 class="page-title">Shopping cart</h1>
        <p class="text-muted">Review your items before checkout.</p>
    </div>
</div>

<?php if (empty($items)): ?>
    <div class="card">
        <?= ui_empty('cart', 'Your cart is empty', 'Browse products and add items — no account is required.',
            '<a href="' . APP_BASE_URL . '/public/browse.php" class="btn btn-primary">' . ui_icon('compass', 'icn icn-sm') . 'Continue shopping</a>') ?>
    </div>
<?php else: ?>
    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <div class="cart-layout">
            <div class="card">
                <div class="card-title tight"><?= count($items) ?> item<?= count($items) === 1 ? '' : 's' ?></div>
                <?php foreach ($items as $item): ?>
                    <div class="cart-item">
                        <?= ui_thumb($item['image'] ?? null, $item['title']) ?>
                        <div class="info">
                            <div class="title"><a href="<?= APP_BASE_URL ?>/public/product.php?id=<?= (int)$item['product_id'] ?>"><?= e($item['title']) ?></a></div>
                            <?php if (!empty($item['seller'])): ?>
                                <div class="sub">Sold by <?= e($item['seller']) ?></div>
                            <?php endif; ?>
                            <div class="sub"><?= format_currency($item['price']) ?> each · <?= (int)$item['stock'] ?> in stock</div>
                            <div style="margin-top:.6rem;display:flex;align-items:center;gap:.75rem;flex-wrap:wrap;">
                                <div class="qty-control" data-qty>
                                    <button type="button" data-qty-step="-1" aria-label="Decrease quantity"><?= ui_icon('minus', 'icn icn-sm') ?></button>
                                    <input type="number" name="qty[<?= (int)$item['product_id'] ?>]" value="<?= (int)$item['quantity'] ?>"
                                           min="1" max="<?= (int)$item['stock'] ?>" aria-label="Quantity for <?= e($item['title']) ?>">
                                    <button type="button" data-qty-step="1" aria-label="Increase quantity"><?= ui_icon('plus', 'icn icn-sm') ?></button>
                                </div>
                                <button type="submit" name="remove_product" value="<?= (int)$item['product_id'] ?>"
                                        class="btn btn-ghost btn-sm" data-confirm="Remove this item from your cart?">
                                    <?= ui_icon('trash', 'icn icn-sm') ?>Remove
                                </button>
                            </div>
                        </div>
                        <div style="text-align:right;font-weight:600;color:var(--forest);font-variant-numeric:tabular-nums;white-space:nowrap;">
                            <?= format_currency($item['price'] * $item['quantity']) ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <aside>
                <div class="card summary">
                    <div class="card-title tight">Order summary</div>
                    <div class="summary-row"><span>Items</span><span class="v"><?= array_sum(array_map(fn($i) => (int)$i['quantity'], $items)) ?></span></div>
                    <div class="summary-row"><span>Subtotal</span><span class="v"><?= format_currency($total) ?></span></div>
                    <div class="summary-row muted"><span>Delivery</span><span>Arranged with the seller</span></div>
                    <div class="summary-total"><span class="l">Total</span><span class="v"><?= format_currency($total) ?></span></div>
                    <div style="display:grid;gap:.6rem;margin-top:1rem;">
                        <button type="submit" name="update" class="btn btn-secondary btn-full"><?= ui_icon('refresh', 'icn icn-sm') ?>Update cart</button>
                        <a href="<?= APP_BASE_URL ?>/buyer/checkout.php" class="btn btn-primary btn-full"><?= ui_icon('card', 'icn icn-sm') ?>Proceed to checkout</a>
                        <a href="<?= APP_BASE_URL ?>/public/browse.php" class="btn btn-ghost btn-full"><?= ui_icon('arrow-left', 'icn icn-sm') ?>Continue shopping</a>
                    </div>
                    <p class="form-hint" style="margin-top:.85rem;">The total is calculated on the server from current prices. No card is charged at checkout.</p>
                </div>
            </aside>
        </div>
    </form>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
