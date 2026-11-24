<?php
require_once __DIR__ . '/../includes/auth_guard.php';
require_once __DIR__ . '/../includes/ui.php';
require_login();

if (current_user_role() !== 'seller') {
    redirect(APP_BASE_URL . (current_user_role() === 'admin' ? '/admin/dashboard.php' : '/buyer/dashboard.php'));
}

$stmt = $conn->prepare("SELECT is_active, vendor_status FROM users WHERE id=? AND role='seller' LIMIT 1");
$stmt->bind_param('i', current_user_id());
$stmt->execute();
$account = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$account || (int)$account['is_active'] !== 1) {
    $_SESSION = [];
    session_destroy();
    session_start();
    set_flash('error', 'Your account is no longer active. Please log in again.');
    redirect(APP_BASE_URL . '/auth/login.php');
}

$vendorStatus = (string)($account['vendor_status'] ?? 'pending');
if ($vendorStatus === 'approved') {
    redirect(APP_BASE_URL . '/seller/dashboard.php');
}

$statusCopy = [
    'pending' => [
        'title' => 'Vendor approval pending',
        'message' => 'An administrator must approve your vendor account before you can add or edit products, manage orders, or view sales and earnings.',
    ],
    'rejected' => [
        'title' => 'Vendor application not approved',
        'message' => 'Your vendor account is not approved for selling. Contact an administrator if you need help with your application.',
    ],
    'suspended' => [
        'title' => 'Vendor account suspended',
        'message' => 'Administrator approval has been suspended. Vendor operations are unavailable until an administrator restores access.',
    ],
];
$copy = $statusCopy[$vendorStatus] ?? $statusCopy['pending'];

$dashTitle = 'Vendor status';
$dashSub   = $copy['title'];
?>
<?php require_once __DIR__ . '/../includes/header.php'; ?>

<div class="card" style="max-width:760px;margin:2rem auto;">
    <div style="display:flex;align-items:center;gap:.75rem;flex-wrap:wrap;">
        <h1 style="margin:0;"><?= htmlspecialchars($copy['title']) ?></h1>
        <?= ui_badge($vendorStatus, ucfirst($vendorStatus), false) ?>
    </div>
    <p style="margin-top:1rem;line-height:1.65;"><?= htmlspecialchars($copy['message']) ?></p>
    <div style="display:flex;gap:.75rem;flex-wrap:wrap;margin-top:1.25rem;">
        <a class="btn btn-primary" href="<?= APP_BASE_URL ?>/public/browse.php">Browse marketplace</a>
        <a class="btn btn-secondary" href="<?= APP_BASE_URL ?>/seller/pending.php">Refresh status</a>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
