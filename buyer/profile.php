<?php
// CampusLink – Buyer Profile
require_once __DIR__ . '/../includes/auth_guard.php';
require_once __DIR__ . '/../config/db.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST') { verify_csrf(); }
require_role('buyer');

$uid = current_user_id();
$stmt = $conn->prepare('SELECT id,name,email,phone,password,created_at FROM users WHERE id=?');
$stmt->bind_param('i',$uid); $stmt->execute();
$user = $stmt->get_result()->fetch_assoc(); $stmt->close();

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name    = trim($_POST['name']  ?? '');
    $phone   = trim($_POST['phone'] ?? '');
    $pw      = $_POST['new_password']     ?? '';
    $pw2     = $_POST['confirm_password'] ?? '';
    $current = $_POST['current_password'] ?? '';

    if ($name === '')  $errors[] = 'Name cannot be empty.';
    if ($pw !== '') {
        // Changing a password requires re-authentication with the current one.
        if ($current === '' || !password_verify($current, (string)$user['password'])) {
            sec_log('reauth_failed', ['action' => 'password_change']);
            $errors[] = 'Enter your current password to set a new one.';
        } elseif ($pw !== $pw2) {
            $errors[] = 'Passwords do not match.';
        } else {
            $policy = sec_password_error($pw, (string)$user['email'], $name);
            if ($policy !== null) $errors[] = $policy;
        }
    }
    if (empty($errors)) {
        $pv = $phone !== '' ? $phone : null;
        if ($pw !== '') {
            $h = sec_hash_password($pw);
            $s = $conn->prepare('UPDATE users SET name=?,phone=?,password=? WHERE id=?');
            $s->bind_param('sssi',$name,$pv,$h,$uid);
        } else {
            $s = $conn->prepare('UPDATE users SET name=?,phone=? WHERE id=?');
            $s->bind_param('ssi',$name,$pv,$uid);
        }
        $s->execute(); $s->close();
        $_SESSION['user_name'] = $name;
        if ($pw !== '') {
            // Force other sessions of this account to re-authenticate.
            session_regenerate_id(true);
            unset($_SESSION['csrf_token']);
            sec_log('password_changed', ['user_id' => $uid]);
            set_flash('success','Profile updated and password changed.');
        } else {
            set_flash('success','Profile updated.');
        }
        redirect(APP_BASE_URL . '/buyer/profile.php');
    }
}

$stmt = $conn->prepare('SELECT COUNT(*) AS c FROM orders WHERE buyer_id=?');
$stmt->bind_param('i',$uid); $stmt->execute();
$order_count = $stmt->get_result()->fetch_assoc()['c']; $stmt->close();
?>
<?php require_once __DIR__ . '/../includes/header.php'; ?>

<div class="page-bar">
    <div>
        <h1 class="page-title">My profile</h1>
        <p class="text-muted">Manage your details and account security.</p>
    </div>
    <a class="btn btn-secondary btn-sm" href="<?= APP_BASE_URL ?>/buyer/dashboard.php"><?= ui_icon('dashboard', 'icn-sm') ?> My account</a>
</div>

<!-- Compact identity summary -->
<div class="card">
    <div class="cell-media" style="gap:1rem;flex-wrap:wrap;">
        <span class="avatar avatar-lg avatar-soft" aria-hidden="true"><?= e(strtoupper(substr($user['name'], 0, 1))) ?></span>
        <div style="min-width:0;">
            <div style="font-family:var(--font-display);font-weight:600;font-size:1.2rem;color:var(--ink);"><?= e($user['name']) ?></div>
            <div class="text-muted" style="font-size:.85rem;"><?= e($user['email']) ?></div>
            <div style="margin-top:.5rem;display:flex;gap:.4rem;flex-wrap:wrap;">
                <?= ui_badge('buyer', 'Buyer') ?>
                <span class="badge badge-neutral badge-plain">Member since <?= e(date('M Y', strtotime($user['created_at']))) ?></span>
            </div>
        </div>
        <div style="margin-left:auto;text-align:right;">
            <div class="meta-label">Orders placed</div>
            <div style="font-family:var(--font-display);font-size:1.7rem;font-weight:600;color:var(--forest);font-variant-numeric:tabular-nums;"><?= (int)$order_count ?></div>
        </div>
    </div>
    <div class="divider"></div>
    <div style="display:flex;gap:.6rem;flex-wrap:wrap;">
        <a href="<?= APP_BASE_URL ?>/buyer/orders.php" class="btn btn-secondary btn-sm"><?= ui_icon('orders', 'icn icn-sm') ?>My Orders</a>
        <a href="<?= APP_BASE_URL ?>/buyer/cart.php" class="btn btn-secondary btn-sm"><?= ui_icon('cart', 'icn icn-sm') ?>My cart</a>
        <a href="<?= APP_BASE_URL ?>/public/browse.php" class="btn btn-primary btn-sm"><?= ui_icon('compass', 'icn icn-sm') ?>Browse Products</a>
    </div>
</div>

<?php if (!empty($errors)): ?>
    <div class="form-errors"><ul>
        <?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
    </ul></div>
<?php endif; ?>

<form method="POST" data-password-policy="profile">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
    <div class="two-col">
        <div class="card">
            <div class="card-title">Personal details</div>
            <div class="form-group">
                <label for="name">Full name</label>
                <input type="text" id="name" name="name" value="<?= e($user['name']) ?>" required autocomplete="name">
            </div>
            <div class="form-group">
                <label for="phone">Phone</label>
                <input type="text" id="phone" name="phone" value="<?= e($user['phone'] ?? '') ?>"
                       autocomplete="tel" placeholder="(555) 010-2030">
                <p class="form-hint">Used only to coordinate delivery of your orders.</p>
            </div>
        </div>

        <div class="card">
            <div class="card-title">Password &amp; security</div>
            <p class="text-muted" style="font-size:.85rem;margin-bottom:1rem;">Leave the password fields blank to keep your current password.</p>
            <div class="form-group">
                <label for="current_password">Current password</label>
                <div class="pw-field">
                    <input type="password" id="current_password" name="current_password"
                           placeholder="Required only to change your password" autocomplete="current-password">
                    <button type="button" class="pw-toggle" data-pw-toggle aria-label="Show current password" aria-pressed="false"><?= ui_icon('eye', 'icn icn-sm') ?></button>
                </div>
            </div>
            <div class="form-group">
                <label for="new_password">New password</label>
                <div class="pw-field">
                    <input type="password" id="new_password" name="new_password" minlength="12" maxlength="200"
                           placeholder="At least 12 characters" autocomplete="new-password"
                           aria-describedby="new-password-hint">
                    <button type="button" class="pw-toggle" data-pw-toggle aria-label="Show new password" aria-pressed="false"><?= ui_icon('eye', 'icn icn-sm') ?></button>
                </div>
                <p class="form-hint" id="new-password-hint">Use at least 12 characters with uppercase and lowercase letters, a number, and a symbol. Avoid names, repeated patterns, and sequences such as 1234.</p>
            </div>
            <div class="form-group">
                <label for="confirm_password">Confirm new password</label>
                <div class="pw-field">
                    <input type="password" id="confirm_password" name="confirm_password" minlength="12"
                           placeholder="Repeat new password" autocomplete="new-password">
                    <button type="button" class="pw-toggle" data-pw-toggle aria-label="Show confirm password" aria-pressed="false"><?= ui_icon('eye', 'icn icn-sm') ?></button>
                </div>
            </div>
            <p class="form-hint" id="password-client-error" role="alert" hidden style="color:var(--clay,#B4532A);margin-top:.35rem;"></p>
        </div>
    </div>

    <div style="display:flex;gap:.6rem;flex-wrap:wrap;margin-top:1rem;">
        <button type="submit" class="btn btn-primary"><?= ui_icon('save', 'icn icn-sm') ?>Save changes</button>
    </div>
</form>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
