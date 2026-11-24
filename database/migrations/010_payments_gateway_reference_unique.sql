-- Payments: keep updated_at (the admin payments page reads it) and stop
-- the same Paystack reference from being stored on two orders.
-- Does not change amounts or statuses. Multiple NULL references are allowed.
-- Stop and fix data first if this unique key fails because of duplicate references.

ALTER TABLE payments
    ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP AFTER created_at;

ALTER TABLE payments
    ADD UNIQUE KEY IF NOT EXISTS uq_payments_gateway_ref (gateway_reference);
