-- Idempotent Paystack webhook / payment event log.
-- Reverse: DROP TABLE payment_events;

CREATE TABLE IF NOT EXISTS payment_events (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_id     VARCHAR(120) NOT NULL,
    event_type   VARCHAR(80)  NOT NULL,
    order_id     INT UNSIGNED NULL,
    payload_hash CHAR(64)     NOT NULL,
    created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_payment_events_event (event_id),
    KEY idx_payment_events_order (order_id)
) ENGINE=InnoDB;
