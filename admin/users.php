<?php
// CampusLink – Admin: manage users
require_once __DIR__ . '/../includes/auth_guard.php';
require_once __DIR__ . '/../includes/ui.php';
require_once __DIR__ . '/../config/db.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST') { verify_csrf(); }

require_role('admin');

// Activate / deactivate (never deletes accounts; never targets self)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_active'], $_POST['user_id'])) {
    $uid = (int)$_POST['user_id'];
    $to  = (int)$_POST['toggle_active'] === 1 ? 1 : 0;
    if ($uid === current_user_id()) {
        set_flash('error', 'You cannot deactivate your own administrator account.');
    } else {
        $stmt = $conn->prepare('UPDATE users SET is_active = ? WHERE id = ?');
        $stmt->bind_param('ii', $to, $uid);
        $stmt->execute();
        $stmt->close();
        set_flash('success', $to === 1 ? 'Account activated.' : 'Account deactivated.');
    }
    redirect(APP_BASE_URL . '/admin/users.php?' . http_build_query(array_filter([
        'q' => trim((string)($_GET['q'] ?? '')),
        'role' => trim((string)($_GET['role'] ?? '')),
        'status' => trim((string)($_GET['status'] ?? '')),
    ], static fn($v) => $v !== '')));
}

// Change role (protect self)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['user_id'], $_POST['role']) && !isset($_POST['toggle_active'])) {
    $uid  = (int)$_POST['user_id'];
    $role = (string)$_POST['role'];
    if (in_array($role, ['buyer', 'seller', 'admin'], true) && $uid !== current_user_id()) {
        $stmt = $conn->prepare('UPDATE users SET role = ? WHERE id = ?');
        $stmt->bind_param('si', $role, $uid);
        $stmt->execute();
        $stmt->close();
        set_flash('success', 'Role updated.');
    } elseif ($uid === current_user_id()) {
        set_flash('error', 'You cannot change your own role here.');
    }
    redirect(APP_BASE_URL . '/admin/users.php');
}

$search = trim((string)($_GET['q'] ?? ''));
$roleF  = trim((string)($_GET['role'] ?? ''));
$status = trim((string)($_GET['status'] ?? ''));

$where = [];
$types = '';
$params = [];
if ($search !== '') {
    $where[] = '(name LIKE ? OR email LIKE ?)';
    $types  .= 'ss';
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
}
if (in_array($roleF, ['buyer', 'seller', 'admin'], true)) {
    $where[] = 'role = ?';
    $types  .= 's';
    $params[] = $roleF;
}
if ($status === 'active') {
    $where[] = 'is_active = 1';
} elseif ($status === 'inactive') {
    $where[] = 'is_active = 0';
}

$sql = 'SELECT id, name, email, role, is_active, phone, created_at FROM users'
     . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
     . ' ORDER BY created_at DESC';
$stmt = $conn->prepare($sql);
if ($types !== '') {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$dashTitle = 'Users';
$dashSub   = count($users) . ' account' . (count($users) === 1 ? '' : 's');
?>
<?php require_once __DIR__ . '/../includes/header.php'; ?>

<form method="GET" class="search-panel">
  <div class="search-field" style="flex:2;">
    <label for="q">Search</label>
    <input type="text" id="q" name="q" placeholder="Name or email…" value="<?= htmlspecialchars($search) ?>">
  </div>
  <div class="search-field">
    <label for="role">Role</label>
    <select id="role" name="role">
      <option value="">All roles</option>
      <?php foreach (['buyer', 'seller', 'admin'] as $r): ?>
        <option value="<?= $r ?>" <?= $roleF === $r ? 'selected' : '' ?>><?= ucfirst($r) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="search-field">
    <label for="status">Status</label>
    <select id="status" name="status">
      <option value="">All</option>
      <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Active</option>
      <option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>Inactive</option>
    </select>
  </div>
  <div class="search-actions">
    <button type="submit" class="btn btn-primary"><?= ui_icon('search', 'icn-sm') ?> Search</button>
    <?php if ($search !== '' || $roleF !== '' || $status !== ''): ?>
      <a href="<?= APP_BASE_URL ?>/admin/users.php" class="btn btn-secondary">Clear</a>
    <?php endif; ?>
  </div>
</form>

<?php if (empty($users)): ?>
  <div class="card">
    <?= ui_empty('users', 'No matching users', 'Try a different search or clear filters.', '') ?>
  </div>
<?php else: ?>
  <div class="card" style="padding:0;">
    <div class="table-wrap">
      <table>
        <thead>
          <tr><th>Account</th><th>Role</th><th>Status</th><th>Joined</th><th></th></tr>
        </thead>
        <tbody>
          <?php foreach ($users as $u): ?>
          <?php
            $parts    = explode(' ', trim((string)$u['name']));
            $initials = strtoupper(substr($parts[0], 0, 1) . (isset($parts[1]) ? substr($parts[1], 0, 1) : ''));
            $isSelf   = ((int)$u['id'] === current_user_id());
            $active   = (int)$u['is_active'] === 1;
          ?>
          <tr>
            <td>
              <div class="cell-media">
                <span class="avatar avatar-soft" aria-hidden="true"><?= htmlspecialchars($initials) ?></span>
                <span>
                  <span class="t">
                    <a href="<?= APP_BASE_URL ?>/admin/user_view.php?id=<?= (int)$u['id'] ?>"><?= htmlspecialchars($u['name']) ?></a>
                    <?= $isSelf ? ' <span class="text-muted">(you)</span>' : '' ?>
                  </span>
                  <span class="s"><?= htmlspecialchars($u['email']) ?></span>
                </span>
              </div>
            </td>
            <td>
              <?php if ($isSelf): ?>
                <?= ui_badge((string)$u['role']) ?>
              <?php else: ?>
                <form method="POST" class="inline-form">
                  <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                  <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                  <label class="sr-only" for="role-<?= (int)$u['id'] ?>">Role for <?= htmlspecialchars($u['name']) ?></label>
                  <select id="role-<?= (int)$u['id'] ?>" name="role" class="select-inline">
                    <?php foreach (['buyer', 'seller', 'admin'] as $r): ?>
                      <option value="<?= $r ?>" <?= $u['role'] === $r ? 'selected' : '' ?>><?= ucfirst($r) ?></option>
                    <?php endforeach; ?>
                  </select>
                  <button class="btn btn-secondary btn-sm"
                          data-confirm="Change this user's role? This immediately changes their access.">Update</button>
                </form>
              <?php endif; ?>
            </td>
            <td><?= $active ? ui_badge('paid', 'Active', false) : ui_badge('cancelled', 'Inactive', false) ?></td>
            <td class="text-muted"><?= date('M j, Y', strtotime($u['created_at'])) ?></td>
            <td class="table-actions">
              <a class="btn btn-secondary btn-sm" href="<?= APP_BASE_URL ?>/admin/user_view.php?id=<?= (int)$u['id'] ?>">Details</a>
              <?php if (!$isSelf): ?>
                <form method="POST">
                  <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                  <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                  <button type="submit" name="toggle_active" value="<?= $active ? 0 : 1 ?>"
                          class="btn <?= $active ? 'btn-danger' : 'btn-primary' ?> btn-sm"
                          data-confirm="<?= $active ? 'Deactivate this account? They will not be able to sign in.' : 'Activate this account?' ?>">
                    <?= $active ? 'Deactivate' : 'Activate' ?>
                  </button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
