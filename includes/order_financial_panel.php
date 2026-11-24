<?php
/** Buyer-safe refund and dispute panel. Seller settlement data is never included. */
$currency = $payment['currency'] ?? app_currency_iso();
$activeDispute = false;
foreach ($disputes as $dispute) {
    if (in_array($dispute['status'], ['open', 'under_review'], true)) {
        $activeDispute = true;
    }
}
?>
<div class="card">
  <div class="card-title">Refunds &amp; disputes</div>
  <?php if (($payment['refund_status'] ?? 'none') !== 'none'): ?>
    <p>Refund status: <?= ui_badge((string)$payment['refund_status']) ?>
      <span class="text-muted">Processed: <?= e(format_currency(minor_to_money((int)$payment['refunded_amount_minor']), $currency)) ?></span>
    </p>
  <?php endif; ?>
  <?php if ($refunds): ?>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Requested</th><th>Amount</th><th>Status</th><th>Reason</th><th>External reference</th></tr></thead>
        <tbody>
          <?php foreach ($refunds as $refund): ?>
            <tr>
              <td><?= e(date('M j, Y', strtotime($refund['created_at']))) ?></td>
              <td><?= e(format_currency(minor_to_money((int)$refund['amount_minor']), $refund['currency'])) ?></td>
              <td><?= ui_badge((string)$refund['status']) ?></td>
              <td><?= e($refund['reason']) ?></td>
              <td><?= e($refund['external_reference'] ?: '—') ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php else: ?>
    <p class="text-muted">No refunds have been recorded for this order.</p>
  <?php endif; ?>
  <?php if ($disputes): ?>
    <h3 style="font-size:1rem;margin-top:1rem;">Dispute history</h3>
    <?php foreach ($disputes as $dispute): ?>
      <p><?= ui_badge((string)$dispute['status']) ?> <?= e($dispute['reason']) ?>
        <?php if (!empty($dispute['resolution_note'])): ?><br><span class="text-muted"><?= e($dispute['resolution_note']) ?></span><?php endif; ?>
      </p>
    <?php endforeach; ?>
  <?php endif; ?>
  <?php if ($canSubmitDispute && !$activeDispute): ?>
    <form method="POST" style="margin-top:1rem;">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="open_dispute">
      <input type="hidden" name="order_id" value="<?= (int)$order['id'] ?>">
      <?php if (!empty($trackingContext)): ?>
        <input type="hidden" name="reference" value="<?= e($order['reference_code']) ?>">
        <?php if (!empty($trackingToken)): ?><input type="hidden" name="token" value="<?= e($trackingToken) ?>"><?php endif; ?>
      <?php endif; ?>
      <div class="form-group">
        <label for="dispute_reason_<?= (int)$order['id'] ?>">Dispute reason</label>
        <select id="dispute_reason_<?= (int)$order['id'] ?>" name="reason" required>
          <option value="Item not received">Item not received</option>
          <option value="Not as described">Not as described</option>
          <option value="Duplicate or incorrect charge">Duplicate or incorrect charge</option>
          <option value="Refund not received">Refund not received</option>
          <option value="Other">Other</option>
        </select>
      </div>
      <div class="form-group">
        <label for="dispute_message_<?= (int)$order['id'] ?>">What happened?</label>
        <textarea id="dispute_message_<?= (int)$order['id'] ?>" name="message" required minlength="10" maxlength="2000" rows="3"></textarea>
      </div>
      <button class="btn btn-secondary" type="submit">Open a dispute</button>
    </form>
  <?php elseif ($activeDispute): ?>
    <p class="text-muted">Your dispute is open and awaiting CampusLink review.</p>
  <?php endif; ?>
</div>
