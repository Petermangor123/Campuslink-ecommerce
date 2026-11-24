-- Multivendor item fulfilment, event timeline, in-app notifications.
-- Additive only — does not rewrite historical reference_code or delete payments.
-- Reverse (destructive): DROP TABLE notifications; DROP TABLE order_events;
--   ALTER TABLE order_items DROP COLUMN seller_id, DROP COLUMN fulfilment_status,
--     DROP COLUMN rejection_reason, DROP COLUMN fulfilled_at;
--   ALTER TABLE payments DROP COLUMN gateway_reference, DROP COLUMN currency, DROP COLUMN verified_at;

ALTER TABLE order_items
    ADD COLUMN seller_id INT UNSIGNED NULL AFTER product_id,
    ADD COLUMN fulfilment_status VARCHAR(32) NOT NULL DEFAULT 'awaiting_payment' AFTER unit_price,
    ADD COLUMN rejection_reason VARCHAR(500) NULL AFTER fulfilment_status,
    ADD COLUMN fulfilled_at TIMESTAMP NULL DEFAULT NULL AFTER rejection_reason,
    ADD KEY idx_order_items_seller (seller_id),
    ADD KEY idx_order_items_fulfilment (fulfilment_status);

UPDATE order_items oi
INNER JOIN products p ON p.id = oi.product_id
SET oi.seller_id = p.seller_id
WHERE oi.seller_id IS NULL;

UPDATE order_items oi
INNER JOIN orders o ON o.id = oi.order_id
SET oi.fulfilment_status = CASE
    WHEN o.status = 'cancelled' OR o.payment_status IN ('failed', 'cancelled') THEN 'cancelled'
    WHEN o.status = 'delivered' THEN 'delivered'
    WHEN o.status = 'shipped' THEN 'shipped'
    WHEN o.payment_status = 'paid' THEN 'awaiting_acceptance'
    ELSE 'awaiting_payment'
END
WHERE oi.fulfilment_status = 'awaiting_payment'
   OR oi.fulfilment_status = 'awaiting_payment';

-- Re-run safe backfill for rows still awaiting_payment that should match paid orders
UPDATE order_items oi
INNER JOIN orders o ON o.id = oi.order_id
SET oi.fulfilment_status = 'awaiting_acceptance'
WHERE o.payment_status = 'paid'
  AND o.status NOT IN ('cancelled', 'delivered', 'shipped')
  AND oi.fulfilment_status = 'awaiting_payment';

UPDATE order_items oi
INNER JOIN orders o ON o.id = oi.order_id
SET oi.fulfilment_status = 'shipped'
WHERE o.status = 'shipped' AND oi.fulfilment_status NOT IN ('cancelled', 'rejected', 'delivered');

UPDATE order_items oi
INNER JOIN orders o ON o.id = oi.order_id
SET oi.fulfilment_status = 'delivered'
WHERE o.status = 'delivered' AND oi.fulfilment_status NOT IN ('cancelled', 'rejected');

CREATE TABLE IF NOT EXISTS order_events (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id      INT UNSIGNED NOT NULL,
    order_item_id INT UNSIGNED NULL,
    actor_user_id INT UNSIGNED NULL,
    actor_role    VARCHAR(20)  NOT NULL DEFAULT 'system',
    event_type    VARCHAR(40)  NOT NULL,
    message       VARCHAR(500) NOT NULL,
    created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_order_events_order (order_id, created_at),
    CONSTRAINT fk_order_events_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS notifications (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NOT NULL,
    order_id   INT UNSIGNED NULL,
    title      VARCHAR(120) NOT NULL,
    body       VARCHAR(500) NOT NULL,
    is_read    TINYINT(1)   NOT NULL DEFAULT 0,
    created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_notifications_user (user_id, is_read, created_at),
    CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

ALTER TABLE payments
    ADD COLUMN gateway_reference VARCHAR(100) NULL AFTER provider,
    ADD COLUMN currency CHAR(3) NOT NULL DEFAULT 'USD' AFTER gateway_reference,
    ADD COLUMN verified_at TIMESTAMP NULL DEFAULT NULL AFTER currency;

-- Seed placed/paid events for existing orders (idempotent-ish: only if none exist)
INSERT INTO order_events (order_id, actor_role, event_type, message, created_at)
SELECT o.id, 'system', 'order_placed', CONCAT('Order ', COALESCE(o.reference_code, CONCAT('#', o.id)), ' placed.'), o.created_at
FROM orders o
WHERE NOT EXISTS (SELECT 1 FROM order_events e WHERE e.order_id = o.id AND e.event_type = 'order_placed');

INSERT INTO order_events (order_id, actor_role, event_type, message, created_at)
SELECT o.id, 'admin', 'payment_confirmed', CONCAT('Payment confirmed for ', COALESCE(o.reference_code, CONCAT('#', o.id)), '.'), COALESCE(p.updated_at, o.created_at)
FROM orders o
INNER JOIN payments p ON p.order_id = o.id
WHERE o.payment_status = 'paid'
  AND NOT EXISTS (SELECT 1 FROM order_events e WHERE e.order_id = o.id AND e.event_type = 'payment_confirmed');
