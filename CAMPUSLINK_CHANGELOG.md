# CampusLink changelog

Record of verified changes prepared for the GitHub submission after commit `a71b959` (`Final submission: CampusLink E-Commerce Marketplace`, 24 November 2026, branch `main`).

The project directory had no `.git` metadata. This record is based on a file comparison with that GitHub commit. Line-ending-only differences were ignored. Seventy-eight tracked files differed only by CRLF versus LF and are not listed as functional changes.

## Added

- `admin/commission.php` — administrator screen to view and save the marketplace commission rate.
- `includes/commission_settings.php` — validates the signed-in administrator, writes `marketplace_settings`, and records `commission_rate_update` in `admin_audit_log`.
- `database/migrations/013_marketplace_commission.sql` — creates `marketplace_settings` if it is missing. It does not update orders, refunds, payouts, or ledger rows.
- `CAMPUSLINK_CHANGELOG.md` — this record.

`database/campuslink.sql` and `setup_database.php` now create the same `marketplace_settings` table for a fresh install.

## Changed behaviour

- Commission for a new order line uses `marketplace_settings.commission_bps` when that row is a valid integer. Otherwise it uses `CAMPUSLINK_COMMISSION_BPS`, then 0. Existing order lines are not rewritten.
- Accepted commission input is 0 to 100 percent, with at most two decimal places. 5% is stored as 500 basis points.
- Deleting a product that already appears on an order deactivates it instead. Unused products are deleted, their cart rows are removed, and the upload file is removed only when no other product uses that path.
- Replacing a product image deletes the previous file only when it is unshared and stays inside `assets/uploads/`. A failed edit removes the newly stored file.
- Sellers can activate or deactivate their own listings from My Products.
- An administrator cannot deactivate the last active administrator account or change that account to another role.
- A Paystack refund webhook posts the financial refund once, when the refund first becomes `processed`. A repeated `processed` event does not post it again.
- The public search field uses the shorter placeholder “Search products...” and the desktop navigation tightens between 1181px and 1400px so the search box keeps a readable width.
- `config/db.php` reads database settings through `sec_env()`. A failed connection is written to the PHP error log. The browser and CLI still receive only “Database connection failed.”

## Security corrections made before this submission

- The local `.env.example` contained a real database username and password. Those values were replaced with `YOUR_DB_USER` and `YOUR_DB_PASSWORD`. The populated `.env` file remains gitignored and is not part of the commit.
- The local root `.htaccess` had stopped denying HTTP access to `config/`, `deploy/`, and `scripts/`, and had dropped the rule that blocks a nested `CampusLink_Gr_17/` directory. Those rules were restored from the previous submission.
- `deploy/.htaccess` was missing locally. It was restored so the commit does not delete the existing “deny all” rule for that directory.

## What was already present and was not rewritten

Buyer registration and login, catalog search, cart, guest checkout, order tracking, Paystack hosted checkout, server-side verification, webhook HMAC checks, webhook claim/apply/fail handling, manual payment review, seller approval, seller order fulfilment, admin vendor management, refunds, disputes, settlements, the financial ledger, and reports remain the implementations from commit `a71b959`, except for the refund-posting guard and the product-removal changes above.

The homepage loads featured products, recent products, popular products, category counts, and seller counts with SQL. It does not use hardcoded product IDs. The hero slides are static image assets with category labels.

Customer-facing currency stays GHC. Paystack and stored payment currency stay GHS. A disposable import of `database/campuslink.sql` created `payments.currency` with default `GHS`.

## Database check

On 26 November 2026 a disposable database was created from `database/campuslink.sql` with the database name replaced so the configured application database was not created or altered. The import produced these tables:

`admin_audit_log`, `cart`, `categories`, `disputes`, `financial_batches`, `financial_entries`, `marketplace_settings`, `notifications`, `orders`, `order_events`, `order_items`, `order_ref_seq`, `payments`, `payment_events`, `payouts`, `payout_allocations`, `products`, `refunds`, `refund_allocations`, `users`.

Six category rows were present. `marketplace_settings` was empty. Migration 013 was applied twice and both runs succeeded. The disposable database was then dropped.

The configured application database name is not present on this MySQL server. These local databases were inspected read-only and were not migrated:

- `campuslink` — 12 tables, including `payments.currency` default `GHS` and `payment_events.process_status`. It has no `marketplace_settings`, refunds, disputes, or ledger tables, and `users` has `is_active` but not `vendor_status`.
- `campuslink_cl_test` — same generation of tables with a `cl_` prefix. The application does not use a table prefix.
- `campuslink_juba` — a WordPress/WooCommerce database, not this PHP application.

No migration was applied to those databases.

## Tests and results

| Check | Result |
| --- | --- |
| `php -l` on every project PHP file, PHP 8.2.12 | 68 files, 0 syntax errors |
| Commission percent parser (`0`, `5`, `5.5`, `5.50`, `10.01`, `100`, `100.00`, `100.0`, and rejected values `-1`, `101`, `5.555`, `abc`, `100.01`, empty) | Pass |
| `__DIR__` require targets | Pass; no missing files |
| Expected buyer, seller, admin, auth, and public page files | Present |
| Disposable schema import and migration 013 twice | Pass; database dropped afterwards |
| `scripts/test_admin_vendor_management.php` | Not run. It inserts and updates product and audit rows, and no disposable database matching the current unprefixed schema was left in place. |
| Apache `http://127.0.0.1/CampusLink_Gr_17_Final_Beyond_class/CampusLink_Gr_17/` | `/`, `/public/index.php`, `/public/browse.php`, `/auth/login.php`, `/auth/register.php`, and `/admin/commission.php` returned HTTP 500 with the body `Database connection failed.` |
| Apache denial checks | HTTP 403 for `/.env`, `/config/db.php`, `/database/campuslink.sql`, `/scripts/create_admin.php`, `/includes/session.php`, `/setup_database.php`, `/deploy/nginx-campuslink.conf.example`, and `/docs/CAMPUSLINK_DEPLOYMENT_CHECKLIST.md` |
| `/assets/css/style.css` | HTTP 200 |
| Paystack checkout, webhook delivery, cart checkout, and role dashboards in a browser | Not executed. Every page that opens `config/db.php` stops because MySQL rejected `chol.thiong`@`localhost`. |

## Known limitations

- This XAMPP copy cannot serve the storefront until `.env` points at a MySQL account and database that exist. Do not commit that file.
- An older database needs migration 013 before an administrator can save a commission rate. Until then the environment fallback is used.
- Email still depends on PHP `mail()` and the host.
- nginx does not read Apache `.htaccess`. Use `deploy/nginx-campuslink.conf.example` as a starting point.
- There is no full browser automation suite in the repository.
