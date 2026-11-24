<?php
// Checkout for registered buyers and guests. Totals are calculated on the server.
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/order_service.php';
require_once __DIR__ . '/../includes/payment_gateway.php';
$payMode = payment_gateway_mode();

$isBuyer = is_logged_in() && current_user_role() === 'buyer';

if (is_logged_in() && !$isBuyer) {
    require_once __DIR__ . '/../includes/header.php';
    echo '<h1 class="page-title">Checkout</h1><div class="card"><p>Vendor and admin accounts cannot place orders. Sign out to continue as a guest, or sign in with a customer account.</p></div>';
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

$items = cart_items($conn);
$postedKey = (string)($_POST['checkout_key'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    // Repeat submit for an already-placed checkout: never create another order.
    // If Paystack is active and the order is still unpaid, resume hosted checkout
    // instead of only showing "Order received" (that path caused the two-click UX).
    if ($postedKey !== '' && ($receipt = receipt_for_checkout_key($postedKey))) {
        if ($payMode === 'paystack') {
            $prior = order_by_reference($conn, (string)$receipt['reference']);
            if ($prior && ($prior['payment_status'] ?? '') !== 'paid') {
                redirect(
                    APP_BASE_URL . '/public/pay.php?ref=' . rawurlencode($receipt['reference'])
                    . '&t=' . rawurlencode($receipt['token'])
                );
            }
        }
        redirect(APP_BASE_URL . '/public/track.php?ref=' . rawurlencode($receipt['reference']) . '&t=' . rawurlencode($receipt['token']) . '&placed=1');
    }
}

if (!$items) {
    set_flash('error', 'Your cart is empty.');
    redirect(APP_BASE_URL . '/buyer/cart.php');
}

$total = array_reduce($items, fn($sum, $item) => $sum + ((float)$item['price'] * (int)$item['quantity']), 0.0);
$asGuest = !$isBuyer && (($_GET['as'] ?? '') === 'guest' || $_SERVER['REQUEST_METHOD'] === 'POST');
$errors = [];
$customerInput = ['name' => '', 'email' => '', 'phone' => '', 'address' => '', 'notes' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $intent = $_SESSION['checkout_intent'] ?? null;
    if (!is_array($intent) || empty($intent['key']) || !hash_equals((string)$intent['key'], $postedKey)) {
        $errors[] = 'Your checkout session expired. Review your details and submit the order again.';
    } else {
        try {
            if ($isBuyer) {
                $customer = buyer_customer($conn, current_user_id(), (string)($_POST['address'] ?? ''), (string)($_POST['notes'] ?? ''));
                $buyerId = current_user_id();
            } else {
                $validated = validate_customer($_POST, true);
                $customerInput = $validated['customer'];
                $errors = $validated['errors'];
                $customer = $validated['customer'];
                $buyerId = null;
            }

            if (!$errors) {
                $result = place_order($conn, $buyerId, $customer, $intent['key'], $intent['token']);
                $order = $result['order'];
                $emailSent = false;
                if (!$result['duplicate']) {
                    if ($buyerId === null) {
                        $_SESSION['guest_cart'] = [];
                    }
                    $emailSent = send_order_email($order, order_line_items($conn, (int)$order['id']), tracking_url($order['reference_code'], $intent['token']));
                    if ($emailSent) {
                        mark_confirmation_sent($conn, (int)$order['id']);
                    }
                }
                remember_checkout_receipt($intent['key'], $order['reference_code'], $intent['token'], $emailSent);
                remember_tracked_order($order['reference_code']);

                // Pay for new and duplicate (retry) unpaid orders — one checkout_key ⇒ one order.
                if ($payMode === 'paystack' && ($order['payment_status'] ?? '') !== 'paid') {
                    // Release the session lock before the slow Paystack HTTP call so a
                    // concurrent retry can see the receipt and avoid stacking on the lock.
                    if (session_status() === PHP_SESSION_ACTIVE) {
                        session_write_close();
                    }
                    $init = paystack_initialize_payment($conn, $order, (string)$intent['token']);
                    if ($init['ok'] && !empty($init['authorization_url'])) {
                        redirect($init['authorization_url']);
                    }
                    if (session_status() !== PHP_SESSION_ACTIVE) {
                        session_start();
                    }
                    set_flash('error', $init['error'] ?? 'Order saved, but Paystack checkout could not start. Use Pay Now on the tracking page.');
                    redirect(APP_BASE_URL . '/public/track.php?ref=' . rawurlencode($order['reference_code']) . '&t=' . rawurlencode($intent['token']) . '&placed=1&pay_failed=1');
                }

                redirect(APP_BASE_URL . '/public/track.php?ref=' . rawurlencode($order['reference_code']) . '&t=' . rawurlencode($intent['token']) . '&placed=1');
            }
        } catch (Throwable $e) {
            error_log('CampusLink checkout failed: ' . $e->getMessage());
            $errors[] = $e instanceof RuntimeException ? $e->getMessage() : 'Could not place your order. Please try again.';
        }
    }
}

$intent = checkout_intent();
if ($isBuyer) {
    $profile = buyer_customer($conn, current_user_id(), '', '');
    $customerInput['name'] = $profile['name'];
    $customerInput['email'] = $profile['email'];
    $customerInput['phone'] = $profile['phone'];
}
?>
<?php require_once __DIR__ . '/../includes/header.php'; ?>

<div class="mb-2">
    <?= ui_breadcrumbs([
        ['Cart', APP_BASE_URL . '/buyer/cart.php'],
        ['Checkout', null],
    ]) ?>
</div>
<div class="page-bar">
    <div>
        <h1 class="page-title">Checkout</h1>
        <p class="text-muted">Confirm your details and place the order.</p>
    </div>
</div>

<?php if (!$isBuyer && !$asGuest): ?>
    <p class="text-muted mb-2">Registration is optional. You can place this order as a guest or sign in to an existing buyer account.</p>
    <div class="checkout-options">
        <a class="card checkout-choice" href="<?= APP_BASE_URL ?>/buyer/checkout.php?as=guest">
            <h2>Continue as Guest</h2>
            <p>Enter your contact and delivery details. No registration is required.</p>
            <span class="btn btn-primary">Continue</span>
        </a>
        <a class="card checkout-choice" href="<?= APP_BASE_URL ?>/auth/login.php?next=<?= rawurlencode(APP_BASE_URL . '/buyer/checkout.php') ?>">
            <h2>Sign In to an Existing Account</h2>
            <p>Use your buyer account. Your cart is kept and checkout continues after sign-in.</p>
            <span class="btn btn-secondary">Sign in</span>
        </a>
    </div>
<?php else: ?>
    <?php if ($errors): ?>
        <div class="form-errors"><ul><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>

    <div class="checkout-layout">
        <div class="card">
            <div class="card-title">Order Summary</div>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Product</th><th>Qty</th><th>Price</th><th>Subtotal</th></tr></thead>
                    <tbody>
                        <?php foreach ($items as $item): ?>
                        <tr>
                            <td>
                                <div class="checkout-line">
                                    <?= ui_thumb($item['image'] ?? null, $item['title']) ?>
                                    <div>
                                        <div style="font-weight:600;"><?= e($item['title']) ?></div>
                                        <?php if (!empty($item['seller'])): ?><div class="text-muted">Sold by <?= e($item['seller']) ?></div><?php endif; ?>
                                    </div>
                                </div>
                            </td>
                            <td><?= (int)$item['quantity'] ?></td>
                            <td><?= format_currency($item['price']) ?></td>
                            <td style="font-weight:600;color:var(--forest);font-variant-numeric:tabular-nums;"><?= format_currency((float)$item['price'] * (int)$item['quantity']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="text-muted mt-2">The total is calculated on the server from current product prices.</p>
        </div>

        <div class="card">
            <div class="card-title"><?= $isBuyer ? 'Delivery and payment' : 'Guest details' ?></div>
            <?php if (!$isBuyer): ?>
                <p class="text-muted mb-2">This does not create a CampusLink account.</p>
            <?php else: ?>
                <p class="text-muted mb-2">Ordering as <strong><?= e($customerInput['name']) ?></strong> (<?= e($customerInput['email']) ?>).</p>
            <?php endif; ?>
            <form method="POST" id="checkout-form" data-checkout-form
                  data-processing-label="<?= e($payMode === 'paystack' ? 'Processing order…' : 'Placing order…') ?>">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="checkout_key" value="<?= e($intent['key']) ?>">
                <?php if (!$isBuyer): ?>
                    <div class="form-group">
                        <label for="name">Full name</label>
                        <input type="text" id="name" name="name" required minlength="2" maxlength="100" value="<?= e($customerInput['name']) ?>" autocomplete="name">
                    </div>
                    <div class="form-group">
                        <label for="email">Email address</label>
                        <input type="email" id="email" name="email" required maxlength="150" value="<?= e($customerInput['email']) ?>" autocomplete="email">
                    </div>
                    <div class="form-group">
                        <label for="phone">Phone number</label>
                        <input type="tel" id="phone" name="phone" required minlength="7" maxlength="20" value="<?= e($customerInput['phone']) ?>" autocomplete="tel" placeholder="(555) 010-2030">
                    </div>
                <?php endif; ?>
                <div class="form-group">
                    <label for="address">Delivery address<?= $isBuyer ? ' (optional)' : '' ?></label>
                    <textarea id="address" name="address" <?= $isBuyer ? '' : 'required minlength="8"' ?> maxlength="500"><?= e($customerInput['address']) ?></textarea>
                </div>
                <div class="form-group">
                    <label for="notes">Order notes (optional)</label>
                    <textarea id="notes" name="notes" maxlength="500"><?= e($customerInput['notes']) ?></textarea>
                </div>
                <div class="checkout-total">Total: <span><?= format_currency($total) ?></span></div>
                <button class="btn btn-primary btn-full btn-lg" type="submit" id="checkoutSubmit">
                  <span class="btn-label"><?= $payMode === 'paystack' ? 'Place order &amp; pay' : 'Place order' ?></span>
                </button>
                <p class="checkout-pending-hint text-muted" hidden>Please wait — do not refresh. You will be redirected to secure payment.</p>
            </form>
        </div>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

