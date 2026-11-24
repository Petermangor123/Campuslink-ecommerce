<?php
// CampusLink – Admin My Profile
require_once __DIR__ . '/../includes/auth_guard.php';
require_once __DIR__ . '/../includes/ui.php';
require_once __DIR__ . '/../config/db.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST') { verify_csrf(); }
require_role('admin');

$uid = current_user_id();
$errors = [];

$stmt = $conn->prepare('SELECT id, name, email, phone, created_at FROM users WHERE id=? AND role=\'admin\' LIMIT 1');
$stmt->bind_param('i', $uid);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$user) {
    set_flash('error', 'Admin account not found.');
    redirect(APP_BASE_URL . '/admin/dashboard.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name  = trim((string)($_POST['name'] ?? ''));
    $phone = trim((string)($_POST['phone'] ?? ''));
    if (mb_strlen($name) < 2 || mb_strlen($name) > 100) {
        $errors[] = 'Enter a display name between 2 and 100 characters.';
    }
    if ($phone !== '' && (mb_strlen($phone) < 7 || mb_strlen($phone) > 20)) {
        $errors[] = 'Enter a valid phone number, or leave it blank.';
    }
    if (!$errors) {
        $phoneVal = $phone !== '' ? $phone : null;
        $up = $conn->prepare('UPDATE users SET name=?, phone=? WHERE id=? AND role=\'admin\'');
        $up->bind_param('ssi', $name, $phoneVal, $uid);
        $up->execute();
        $up->close();
        $_SESSION['user_name'] = $name;
        set_flash('success', 'Profile updated.');
        redirect(APP_BASE_URL . '/admin/profile.php');
    }
    $user['name'] = $name;
    $user['phone'] = $phone;
}

$dashTitle = 'My Profile';
$dashSub   = 'Administrator account details';
?>
<?php require_once __DIR__ . '/../includes/header.php'; ?>

<div class="card" style="max-width:640px;">
  <div class="card-title">Profile details</div>
  <?php if ($errors): ?>
    <div class="form-errors"><ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
  <?php endif; ?>
  <form method="POST">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
    <div class="form-group">
      <label for="name">Display name <span aria-hidden="true">*</span></label>
      <input type="text" id="name" name="name" required maxlength="100" value="<?= e($user['name']) ?>">
    </div>
    <div class="form-group">
      <label for="email">Email</label>
      <input type="email" id="email" value="<?= e($user['email']) ?>" disabled>
      <p class="form-hint">Sign-in email changes require a database administrator.</p>
    </div>
    <div class="form-group">
      <label for="phone">Phone</label>
      <input type="tel" id="phone" name="phone" maxlength="20" value="<?= e((string)($user['phone'] ?? '')) ?>">
    </div>
    <button type="submit" class="btn btn-primary"><?= ui_icon('save', 'icn-sm') ?> Save profile</button>
  </form>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
