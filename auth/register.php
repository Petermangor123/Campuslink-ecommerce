<?php
// CampusLink – Register
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../config/db.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST') { verify_csrf(); }

if (is_logged_in()) redirect(APP_BASE_URL . '/public/index.php');

$errors = [];
$input  = ['name'=>'','email'=>'','phone'=>'','role'=> (($_GET['role'] ?? '') === 'seller' ? 'seller' : 'buyer')];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input['name']  = trim($_POST['name']  ?? '');
    $input['email'] = trim($_POST['email'] ?? '');
    $input['phone'] = trim($_POST['phone'] ?? '');
    $input['role']  = in_array($_POST['role'] ?? '', ['buyer','seller']) ? $_POST['role'] : 'buyer';
    $password       = $_POST['password'] ?? '';
    $confirm        = $_POST['confirm']  ?? '';

    // Network-wide registration limit (shared campus NAT / public Wi-Fi).
    // Customer and vendor posts share one IP bucket. Failed field validation
    // does not consume the budget so password/form testing is not punished.
    // Thresholds: REGISTER_IP_MAX completed attempts per REGISTER_IP_WINDOW seconds.
    $regBucket = 'register-ip:' . sec_client_ip();
    $regPeek   = sec_rate($regBucket, REGISTER_IP_MAX, REGISTER_IP_WINDOW, false);
    if ($regPeek['limited']) {
        sec_log('register_rate_limited', [
            'count' => $regPeek['count'],
            'retry' => $regPeek['retry'],
        ]);
        http_response_code(429);
        header('Retry-After: ' . max(60, $regPeek['retry']));
        $mins = max(1, (int)ceil($regPeek['retry'] / 60));
        $errors[] = 'Too many sign-up attempts from this network. Please wait about '
            . $mins . ' minute' . ($mins === 1 ? '' : 's') . ', then try again.';
    }

    if (empty($errors)) {
        if ($input['name']  === '')                              $errors[] = 'Full name is required.';
        if (!filter_var($input['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Enter a valid email address.';
        if ($password !== $confirm) {
            $errors[] = 'Passwords do not match.';
        } else {
            $policy = sec_password_error($password, $input['email'], $input['name']);
            if ($policy !== null) $errors[] = $policy;
        }
    }

    if (empty($errors)) {
        // Count only completed attempts (passed validation, about to create/lookup).
        $regHit = sec_rate($regBucket, REGISTER_IP_MAX, REGISTER_IP_WINDOW, true);
        if ($regHit['limited']) {
            sec_log('register_rate_limited', [
                'count' => $regHit['count'],
                'retry' => $regHit['retry'],
                'at'    => 'post_validation',
            ]);
            http_response_code(429);
            header('Retry-After: ' . max(60, $regHit['retry']));
            $mins = max(1, (int)ceil($regHit['retry'] / 60));
            $errors[] = 'Too many sign-up attempts from this network. Please wait about '
                . $mins . ' minute' . ($mins === 1 ? '' : 's') . ', then try again.';
        }
    }

    if (empty($errors)) {
        $stmt = $conn->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $stmt->bind_param('s', $input['email']); $stmt->execute(); $stmt->store_result();
        $exists = $stmt->num_rows > 0;
        $stmt->close();

        if (!$exists) {
            $hashed = sec_hash_password($password);
            $phone  = $input['phone'] !== '' ? $input['phone'] : null;
            $stmt   = $conn->prepare('INSERT INTO users (name,email,password,role,phone) VALUES (?,?,?,?,?)');
            $stmt->bind_param('sssss', $input['name'], $input['email'], $hashed, $input['role'], $phone);
            $stmt->execute(); $stmt->close();
            sec_log('register_success', [
                'email_hash' => hash('sha256', strtolower($input['email'])),
                'role'       => $input['role'],
            ]);
        } else {
            sec_log('register_duplicate_email', ['email_hash' => hash('sha256', strtolower($input['email']))]);
        }

        // The same response is returned whether or not the email was already
        // registered, so the form cannot be used to enumerate accounts.
        // Same message for new and existing emails (anti-enumeration). Existing
        // accounts keep their original password — registering again does not reset it.
        if ($input['role'] === 'seller') {
            $notice = 'Thanks for joining CampusLink as a vendor! If this email is new, your account has been created and is awaiting administrator approval. Once approved, you can sign in and start selling. If you already registered, use your existing password.';
        } else {
            $notice = 'Thanks for joining CampusLink! If this email is new, your customer account has been created. You can now sign in with the password you chose. If you already registered, use your existing password.';
        }
        set_flash('success', $notice);
        redirect(APP_BASE_URL . '/auth/login.php');
    }
}
?>
<?php require_once __DIR__ . '/../includes/header.php'; ?>

<div class="auth-register">
  <div class="reg-panel">
    <header class="reg-head">
      <p class="reg-kicker">CampusLink</p>
      <h1>Create your account</h1>
      <p>A trusted place to buy and sell with students on your campus.</p>
    </header>

    <?php if (!empty($errors)): ?>
      <div class="form-errors">
        <ul><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul>
      </div>
    <?php endif; ?>

    <form method="POST" data-password-policy="register">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

      <div class="account-choices" role="radiogroup" aria-label="Account type">
        <label class="account-card">
          <input type="radio" name="role" value="buyer" <?= $input['role']==='buyer'?'checked':'' ?>>
          <span class="account-card-body">
            <span class="account-icon" aria-hidden="true">
              <svg xmlns="http://www.w3.org/2000/svg" width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"/><path d="M3 6h18"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>
            </span>
            <span class="account-title">Customer</span>
            <span class="account-desc">Discover and purchase products from your campus community.</span>
          </span>
        </label>
        <label class="account-card">
          <input type="radio" name="role" value="seller" <?= $input['role']==='seller'?'checked':'' ?>>
          <span class="account-card-body">
            <span class="account-icon" aria-hidden="true">
              <svg xmlns="http://www.w3.org/2000/svg" width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="m2 7 4.41-4.41A2 2 0 0 1 7.83 2h8.34a2 2 0 0 1 1.42.59L22 7"/><path d="M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8"/><path d="M15 22v-4a2 2 0 0 0-2-2h-2a2 2 0 0 0-2 2v4"/><path d="M2 7h20"/><path d="M22 7v3a2 2 0 0 1-2 2 2.7 2.7 0 0 1-1.59-.63.7.7 0 0 0-.82 0A2.7 2.7 0 0 1 16 12a2.7 2.7 0 0 1-1.59-.63.7.7 0 0 0-.82 0A2.7 2.7 0 0 1 12 12a2.7 2.7 0 0 1-1.59-.63.7.7 0 0 0-.82 0A2.7 2.7 0 0 1 8 12a2.7 2.7 0 0 1-1.59-.63.7.7 0 0 0-.82 0A2.7 2.7 0 0 1 4 12a2 2 0 0 1-2-2V7"/></svg>
            </span>
            <span class="account-title">Vendor</span>
            <span class="account-desc">Showcase and sell your products after administrator approval.</span>
          </span>
        </label>
      </div>

      <div class="form-group">
        <label for="name">Full name</label>
        <input type="text" id="name" name="name" autocomplete="name"
               value="<?= htmlspecialchars($input['name']) ?>"
               placeholder="Jane Smith" required>
      </div>
      <div class="form-group">
        <label for="email">Email address</label>
        <input type="email" id="email" name="email" autocomplete="email"
               value="<?= htmlspecialchars($input['email']) ?>"
               placeholder="you@university.edu" required>
      </div>
      <div class="form-group">
        <label for="phone">Phone <span class="reg-optional">Optional</span></label>
        <input type="tel" id="phone" name="phone" autocomplete="tel"
               value="<?= htmlspecialchars($input['phone']) ?>"
               placeholder="+1 555 000 0000">
      </div>
      <div class="form-row">
        <div class="form-group">
          <label for="password">Password</label>
          <div class="pw-field">
            <input type="password" id="password" name="password" autocomplete="new-password"
                   placeholder="At least 12 characters" required minlength="12" maxlength="200"
                   aria-describedby="password-hint">
            <button type="button" class="pw-toggle" data-pw-toggle aria-label="Show password" aria-pressed="false"><?= ui_icon('eye', 'icn icn-sm') ?></button>
          </div>
          <p class="form-hint" id="password-hint">Use at least 12 characters with uppercase and lowercase letters, a number, and a symbol. </p>
        </div>
        <div class="form-group">
          <label for="confirm">Confirm password</label>
          <div class="pw-field">
            <input type="password" id="confirm" name="confirm" autocomplete="new-password"
                   placeholder="Repeat password" required minlength="12" maxlength="200">
            <button type="button" class="pw-toggle" data-pw-toggle aria-label="Show confirm password" aria-pressed="false"><?= ui_icon('eye', 'icn icn-sm') ?></button>
          </div>
        </div>
      </div>
      <p class="form-hint" id="password-client-error" role="alert" hidden style="color:var(--clay,#B4532A);margin-top:.35rem;"></p>

      <button type="submit" class="btn btn-primary btn-full reg-submit">Create account</button>
    </form>

    <p class="reg-switch">Already have an account? <a href="<?= APP_BASE_URL ?>/auth/login.php">Sign in</a></p>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
