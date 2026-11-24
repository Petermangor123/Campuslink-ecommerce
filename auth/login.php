<?php
// CampusLink – Login
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/cart.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST') { verify_csrf(); }

if (is_logged_in()) redirect(APP_BASE_URL . '/public/index.php');

$errors = []; $email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email']    ?? '');
    $password = $_POST['password'] ?? '';

    if ($email === '' || $password === '') {
        $errors[] = 'Email and password are required.';
    } else {
        // Throttle login by IP (all attempts) and by account (failed attempts).
        $ipBucket   = 'login-ip:' . sec_client_ip();
        $acctBucket = 'login-acct:' . hash('sha256', strtolower($email));
        $ipRate     = sec_rate($ipBucket, 20, 900, true);
        $acctRate   = sec_rate($acctBucket, 5, 900, false);

        if ($ipRate['limited'] || $acctRate['limited']) {
            sec_log('login_rate_limited', [
                'email_hash'   => hash('sha256', strtolower($email)),
                'ip_limited'   => $ipRate['limited'],
                'acct_limited' => $acctRate['limited'],
            ]);
            http_response_code(429);
            header('Retry-After: ' . max(60, max($ipRate['retry'], $acctRate['retry'])));
            $errors[] = 'Too many sign-in attempts. Please wait a few minutes and try again.';
        } else {
            $stmt = $conn->prepare('SELECT id, name, email, password, role, is_active, vendor_status FROM users WHERE email = ? LIMIT 1');
            $stmt->bind_param('s', $email);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            // Always run one hash comparison so response timing does not reveal
            // whether the email address exists (anti-enumeration).
            $hash  = $user['password'] ?? sec_dummy_hash();
            $valid = $user !== null && password_verify($password, $hash);

            if (!$valid) {
                sec_rate($acctBucket, 5, 900, true);
                sec_log('login_failed', ['email_hash' => hash('sha256', strtolower($email))]);
                $errors[] = 'Incorrect email or password.';
            } elseif ((int)($user['is_active'] ?? 1) !== 1) {
                sec_log('login_inactive', ['user_id' => (int)$user['id'], 'role' => $user['role']]);
                $errors[] = 'This account has been deactivated. Contact an administrator if you need access.';
            } else {
            sec_rate_clear($acctBucket);

            // Transparently upgrade legacy/weaker hashes to the current algorithm.
            if (sec_password_needs_rehash($user['password'])) {
                $newHash = sec_hash_password($password);
                $up = $conn->prepare('UPDATE users SET password = ? WHERE id = ?');
                $up->bind_param('si', $newHash, $user['id']);
                $up->execute();
                $up->close();
            }

            session_regenerate_id(true);
            $_SESSION['user_id']    = $user['id'];
            $_SESSION['user_name']  = $user['name'];
            $_SESSION['user_role']  = $user['role'];
            $_SESSION['user_email'] = $user['email'];
            $_SESSION['created_at']    = time();
            $_SESSION['last_activity'] = time();
            sec_log('login_success', ['user_id' => (int)$user['id'], 'role' => $user['role']]);
            $pending = take_pending_cart();
            $next = safe_local_path((string)($_POST['next'] ?? ''));

            if ($user['role'] === 'buyer') {
                merge_guest_cart($conn, (int)$user['id']);
            }

            if ($user['role'] === 'buyer' && $pending) {
                $result = add_to_cart(
                    $conn,
                    (int)$user['id'],
                    (int)($pending['product_id'] ?? 0),
                    (int)($pending['qty'] ?? 1)
                );
                if ($result['ok']) {
                    set_flash('success', 'Welcome back, ' . $user['name'] . '! Added to cart.');
                    redirect(APP_BASE_URL . '/buyer/cart.php');
                }
                set_flash('error', $result['message']);
                $returnId = (int)($pending['product_id'] ?? 0);
                redirect($returnId > 0
                    ? APP_BASE_URL . '/public/product.php?id=' . $returnId
                    : APP_BASE_URL . '/buyer/dashboard.php');
            }

            set_flash('success', 'Welcome back, ' . $user['name'] . '!');
            if ($user['role'] === 'buyer' && $next !== '') {
                redirect($next);
            }
            switch ($user['role']) {
                case 'admin':  redirect(APP_BASE_URL . '/admin/dashboard.php');  break;
                case 'seller':
                    redirect(APP_BASE_URL . (($user['vendor_status'] ?? 'pending') === 'approved'
                        ? '/seller/dashboard.php'
                        : '/seller/pending.php'));
                    break;
                default:       redirect(APP_BASE_URL . '/buyer/dashboard.php');
            }
            }
        }
    }
}
?>
<?php require_once __DIR__ . '/../includes/header.php'; ?>

<div class="auth-wrap">
  <div class="auth-head">
    <h2>Welcome back</h2>
    <p>Sign in to your CampusLink account</p>
  </div>

  <div class="card" style="padding:2rem;">
    <?php if (!empty($errors)): ?>
      <div class="form-errors">
        <ul><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul>
      </div>
    <?php endif; ?>

    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="next" value="<?= e(safe_local_path((string)($_GET['next'] ?? $_POST['next'] ?? ''))) ?>">
      <div class="form-group">
        <label for="email">Email address</label>
        <input type="email" id="email" name="email"
               value="<?= htmlspecialchars($email) ?>"
               placeholder="you@university.edu" required autofocus>
      </div>
      <div class="form-group">
        <label for="password">Password</label>
        <input type="password" id="password" name="password" placeholder="••••••••" required>
      </div>
      <button type="submit" class="btn btn-primary btn-full mt-1">Sign In</button>
    </form>

    <div class="or-line">or</div>
    <a href="<?= APP_BASE_URL ?>/auth/register.php" class="btn btn-secondary btn-full">
      Create a new account
    </a>
  </div>

  <p class="text-center text-muted mt-2" style="max-width:26rem;margin-left:auto;margin-right:auto;font-size:.85rem;line-height:1.45;">
    Already registered? Use the password from your first successful sign-up.
    Submitting the register form again with the same email does not change your password.
  </p>
  <p class="text-center text-muted mt-2">
    By signing in you agree to CampusLink's terms of service.
  </p>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
