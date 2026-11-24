-- CampusLink migration 007
-- Set default payment currency to GHS (ISO) for NEW rows only.
-- Does NOT update historical payment amounts or currencies (e.g. CL-001 USD).
-- Display label remains GHC via includes/currency.php — Paystack API stays GHS.

ALTER TABLE payments
  MODIFY COLUMN currency CHAR(3) NOT NULL DEFAULT 'GHS';
