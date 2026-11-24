# CampusLink database

Import `campuslink.sql` once through phpMyAdmin or the MySQL client.

The script creates the database `ecommerce_2026A_chol_thiong` and these tables:

- `users` — buyer, seller, and admin accounts (`is_active` for account activation, `vendor_status` for seller approval)
- `categories` — Textbooks, Electronics, Clothing, Furniture, Stationery, Other
- `products`
- `cart`
- `orders` and `order_items` — public `reference_code` values are sequential (`CL-001`…); items carry per-vendor `fulfilment_status`
- `order_ref_seq` — allocates the next public order reference under row lock
- `order_events` — audit / tracking timeline
- `notifications` — in-app alerts for buyers and sellers
- `payments` — live `manual_review`; optional Paystack columns when configured
- `payment_events` — Paystack webhook idempotency / claim–apply–fail (`process_status`)

`CREATE DATABASE` and `CREATE TABLE IF NOT EXISTS` are used, so importing again does not drop existing rows. It also does not repair a database that was created from an older schema. Do not edit table or column names to match a folder rename.

### Fresh production import

1. `mysql … < database/campuslink.sql` — current dump includes `payment_events` with `process_status`.
2. Optionally re-run `migrations/006_payment_events.sql` and `migrations/008_payment_events_process_status.sql` (both are idempotent) as a safety check.
3. Do **not** run destructive reverse migrations on production data.

## Migrations (existing databases)

Apply in order when upgrading an older local DB:

1. `migrations/001_add_users_totp_columns.sql` — only if an intermediate build expected TOTP columns and they are missing.
2. `migrations/002_drop_users_totp_columns.sql` — removes TOTP columns after 2FA was retired (reverse by re-applying 001).
3. `migrations/003_add_users_is_active.sql` — adds `users.is_active` for admin activate/deactivate.
4. `migrations/004_order_ref_seq.sql` — sequential public order references (`CL-001`…); does not rewrite historical codes.
5. `migrations/005_order_fulfilment_multivendor.sql` — per-vendor fulfilment columns, `order_events`, `notifications`, payment verify metadata.
6. `migrations/006_payment_events.sql` — idempotent Paystack webhook event log.
7. `migrations/007_payments_currency_default_ghs.sql` — payments currency default GHS (if present).
8. `migrations/008_payment_events_process_status.sql` — webhook claim/process columns (if present).
9. `migrations/009_admin_vendor_management.sql` — `products.is_active` + `admin_audit_log` for Vendor Management.
10. `migrations/010_payments_gateway_reference_unique.sql` — `payments.updated_at` if missing, and a unique key on `gateway_reference`. Does not change amounts or statuses.
11. `migrations/011_payment_operations.sql` — refunds, payouts, disputes, and balanced financial records.
12. `migrations/012_vendor_approval.sql` — `users.vendor_status`; existing sellers become pending and require administrator approval.

Fresh installs from the current `campuslink.sql` already include user `is_active` and `vendor_status`, product `is_active`, `admin_audit_log`, `order_ref_seq`, fulfilment columns, payment-operation tables, `payment_events` (with process columns), and do not include TOTP columns.

### Hosting commands (example)

```bash
# Backup first
mysqldump -u USER -p --single-transaction ecommerce_2026A_chol_thiong > campuslink_backup.sql

# Fresh schema (empty database)
mysql -u USER -p ecommerce_2026A_chol_thiong < database/campuslink.sql

# Or upgrade an older DB that already has data:
php includes/order_service.php --migrate

# Verify
mysql -u USER -p -e "SHOW COLUMNS FROM ecommerce_2026A_chol_thiong.payment_events LIKE 'process_status';"
```

No administrator account is inserted. After you register through the site, set that account's `role` to `admin` in phpMyAdmin.

Connection settings live in the project-root `.env` file and are read by `config/db.php`. The XAMPP defaults are host `localhost`, user `root`, an empty password, database `ecommerce_2026A_chol_thiong`, and port `3306`.
