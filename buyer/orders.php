<?php
// CampusLink – Buyer order history
require_once __DIR__ . '/../includes/auth_guard.php';
require_once __DIR__ . '/../config/db.php';
require_role('buyer');

$uid = current_user_id();
$stmt = $conn->prepare(
    'SELECT o.id, o.reference_code, o.total, o.status, o.payment_status, o.created_at,
            p.currency AS payment_currency
     FROM orders o
     LEFT JOIN payments p ON p.order_id = o.id
     WHERE o.buyer_id=?
     ORDER BY o.created_at DESC'
);
$stmt->bind_param('i', $uid); $stmt->execute();
$orders = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
?>
<?php require_once __DIR__ . '/../includes/header.php'; ?>

<div class="page-bar">
    <div>
        <h1 class="page-title">My Orders</h1>
        <p class="text-muted">Payment and fulfilment status for your purchases.</p>
    </div>
</div>

<?php if (empty($orders)): ?>
    <div class="card">
        <?= ui_empty('orders', 'No orders yet', 'Your purchases will appear here once you check out.',
            '<a href="' . APP_BASE_URL . '/public/browse.php" class="btn btn-primary">' . ui_icon('compass', 'icn icn-sm') . 'Browse Products</a>') ?>
    </div>
<?php else: ?>
    <div class="card">
        <div class="table-wrap">
            <table>
                <thead>
                    <tr><th>Order</th><th>Date</th><th>Total</th><th>Payment</th><th>Fulfilment</th><th></th></tr>
                </thead>
                <tbody>
                    <?php foreach ($orders as $o): ?>
                    <tr>
                        <td style="font-weight:600;"><?= e($o['reference_code'] ?: ('#' . $o['id'])) ?></td>
                        <td class="text-muted"><?= e(date('M j, Y', strtotime($o['created_at']))) ?></td>
                        <td style="font-weight:600;color:var(--forest);font-variant-numeric:tabular-nums;"><?= format_currency($o['total'], $o['payment_currency'] ?? null) ?></td>
                        <td><?= ui_badge((string)$o['payment_status'], null, false) ?></td>
                        <td><?= ui_badge((string)$o['status']) ?></td>
                        <td>
                            <a href="<?= APP_BASE_URL ?>/buyer/order_detail.php?id=<?= (int)$o['id'] ?>"
                               class="btn btn-secondary btn-sm">View</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
