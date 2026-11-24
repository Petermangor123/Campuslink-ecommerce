# CampusLink

University marketplace for Group 17, prepared for the University of Juba, South Sudan.

The project is maintained by Group 17.

## Project overview

CampusLink is a multi-vendor campus marketplace where students can browse listings, create a buyer or seller account, sell their own items, add products to a cart, and place an order. Administrators manage vendor approval, users, products, orders, payments, and marketplace financial records.

The application is a PHP and MySQL project maintained by Group 17 for the University of Juba, South Sudan. It is suitable for a local XAMPP environment or a compatible Apache/MySQL hosting environment after deployment configuration is completed.

## Problem statement

Students need a single place to find and offer textbooks, electronics, clothing, furniture, stationery, and other campus items. A public storefront is not enough on its own: sellers need to manage their own listings, buyers need a cart and an order record, and staff need a way to confirm payment and update delivery status.

CampusLink addresses these needs with role-specific workflows, server-calculated order totals, vendor approval, order tracking, and auditable payment and fulfilment operations.

## Objectives

- Provide a campus-focused catalog for browsing and searching products.
- Support buyer, seller, and administrator workflows with role-based access control.
- Allow registered buyers and guests to place orders and track their status.
- Require administrator approval before a seller can manage marketplace listings.
- Support optional Paystack checkout and an administrator-reviewed campus payment workflow.
- Keep order, payment, refund, dispute, settlement, and ledger records traceable.
- Protect credentials, private runtime data, and application internals from source control and direct web access.

## Proposed solution

CampusLink combines a public storefront with authenticated buyer, seller, and administrator areas. Visitors can browse without an account. Registered buyers and guests can check out. Sellers manage their own products and fulfil their own order lines only after an administrator approves their vendor account.

When valid Paystack keys are configured, checkout is verified with Paystack; amounts are displayed as GHC and sent to the gateway as GHS. Without those keys, the application uses manual payment review and an administrator confirms campus payment.

## User roles

| Role | Capabilities |
| --- | --- |
| Visitor | Browse the catalog, search and filter products, view product details, and open the guest checkout flow. |
| Guest buyer | Place an order without registering and track it using the order reference and private tracking token. |
| Buyer | Maintain a cart and profile, place orders, view order history, and open owned order details. |
| Seller / vendor | After administrator approval, create and edit owned products, review owned order lines, and update fulfilment status. |
| Administrator | Manage users, vendor approvals, products, orders, payments, refunds, disputes, settlements, ledger entries, and reports. |

## Key features

- Public storefront, category browse, and product pages
- Buyer, seller, and administrator accounts
- Shopping cart and checkout, including guest checkout
- Order history and guest order tracking
- Seller product create, edit, and order view after administrator approval
- Administrator vendor approval, rejection, suspension, user, product, and order management
- CSRF checks, prepared SQL statements, upload checks, and role-based access control
- Partial/full refunds, vendor commission snapshots, offline settlement records, disputes/chargebacks, and balanced financial audit entries
- Admin and seller CSV statements with spreadsheet-formula neutralization

## Technology stack

- PHP 8.0 or newer; PHP 8.1/8.2 is recommended by the deployment checklist
- PHP extensions: `mysqli`, `curl`, `openssl`, `gd`, `fileinfo`, `mbstring`, `json`, and `session`
- MySQL 8 or MariaDB
- Apache with `mod_rewrite` and `.htaccess` support, or an equivalent manually configured nginx/PHP-FPM deployment
- HTML, CSS, and vanilla JavaScript; no Composer dependency or frontend build step
- Paystack REST API through PHP/cURL when online payment is configured
- PHP `mail()` for order and status email, dependent on host mail configuration
- Google Fonts (Inter and Space Grotesk) when the browser is online

## Project folder structure

```text
CampusLink_Gr_17-university-deploy/
├── index.php                         # Opens the public storefront
├── README.md
├── .env.example                      # Placeholder configuration template
├── .gitignore
├── .htaccess                         # Apache access and routing protections
├── setup_database.php                # CLI-only schema diagnostics/repair and maintenance
├── setup_admin.php                   # CLI-only payment maintenance commands
├── public/
│   ├── index.php                     # Storefront homepage
│   ├── browse.php
│   ├── product.php
│   ├── track.php
│   ├── pay.php                       # Starts or retries Paystack checkout
│   ├── paystack_callback.php
│   └── paystack_webhook.php
├── auth/                             # Login, registration, logout
├── buyer/                            # Profile, cart, checkout, orders
├── seller/                           # Products, sales, orders, fulfilment
├── admin/                            # Users, vendors, orders, finance, reports
├── includes/                         # Shared services, guards, cart, catalog, payment logic
├── config/
│   └── db.php                        # Environment-aware MySQL connection
├── database/
│   ├── campuslink.sql                # Current schema and seed categories
│   ├── README.md                     # Import and migration guidance
│   └── migrations/                   # Ordered upgrades for older databases
├── assets/
│   ├── css/
│   ├── js/
│   ├── img/
│   └── uploads/                      # Runtime product images; binaries are ignored by Git
├── storage/                          # Private logs and rate-limit state; ignored by Git
├── scripts/                          # CLI administration, maintenance, and fixture-based checks
├── deploy/                           # Example nginx configuration
└── docs/                             # Deployment, UX, implementation, and brand notes
```

## Installation

### Requirements

1. Install PHP 8.0+ with the extensions listed in the technology stack.
2. Install MySQL 8 or MariaDB and start the database service.
3. Install Apache with `mod_rewrite` enabled and `AllowOverride All` for the CampusLink document root. XAMPP is a common local option on Windows.
4. Ensure outbound HTTPS access to `api.paystack.co` if Paystack will be used.

### Local setup

1. Clone the repository and enter the project root:

   ```bash
   git clone https://github.com/Petermangor123/Campuslink-ecommerce.git
   cd Campuslink-ecommerce
   ```

2. Place the project root under the web server document root, such as `C:\xampp\htdocs\CampusLink` for XAMPP, or configure a virtual host whose document root is the project root.
3. Copy the environment template. On Windows Command Prompt use `copy .env.example .env`; on macOS/Linux use `cp .env.example .env`.
4. Configure the database and import the schema as described below.
5. Make `assets/uploads/`, `storage/logs/`, and `storage/ratelimit/` writable by PHP. Keep application code and `.env` read-only to the web server where practical.
6. Create an administrator account using the CLI command below.
7. Open the site through Apache, for example `http://localhost/CampusLink/`. The root `index.php` opens `public/index.php`.

No Composer installation or asset compilation is required.

## Database setup

1. Create a dedicated MySQL/MariaDB user with the privileges needed to create or use the application schema. Production deployments should grant only the required `SELECT`, `INSERT`, `UPDATE`, and `DELETE` privileges on the application database.
2. Configure `CAMPUSLINK_DB_HOST`, `CAMPUSLINK_DB_USER`, `CAMPUSLINK_DB_PASS`, `CAMPUSLINK_DB_NAME`, and `CAMPUSLINK_DB_PORT` in `.env`.
3. Import `database/campuslink.sql` once. The supplied schema creates the database `ecommerce_2026A_chol_thiong`, current tables, and seed categories.

   MySQL client example:

   ```bash
   mysql -u YOUR_DB_USER -p < database/campuslink.sql
   ```

   Alternatively, import `database/campuslink.sql` through phpMyAdmin.

4. For a fresh installation, verify that `payment_events` exists and includes `process_status`.
5. For an older database, apply the migrations in numeric order according to `database/README.md`. Do not run destructive reverse migrations against production data.

The schema does not insert an administrator account or default administrator credentials.

## Environment configuration

Copy `.env.example` to `.env` and replace placeholders locally or on the server. Never commit the populated file.

| Variable | Purpose |
| --- | --- |
| `CAMPUSLINK_ENV` | Runtime environment label, such as `production`. |
| `CAMPUSLINK_DB_HOST` | MySQL/MariaDB host. |
| `CAMPUSLINK_DB_USER` | Dedicated database username. |
| `CAMPUSLINK_DB_PASS` | Database password; use a placeholder only in `.env.example`. |
| `CAMPUSLINK_DB_NAME` | Application database name. |
| `CAMPUSLINK_DB_PORT` | Database port, normally `3306`. |
| `CAMPUSLINK_PUBLIC_ORIGIN` | Public HTTPS origin used for payment and tracking links. |
| `CAMPUSLINK_ALLOWED_HOSTS` | Comma-separated hostnames accepted by host validation. |
| `CAMPUSLINK_ENFORCE_HOST` | Enables host-header enforcement when set to `1`. |
| `CAMPUSLINK_TRUST_PROXY` | Set to `1` only behind a trusted reverse proxy that manages forwarded scheme information. |
| `PAYSTACK_SECRET_KEY` | Paystack test or live secret key; leave placeholder/empty for manual review mode. |
| `PAYSTACK_PUBLIC_KEY` | Matching Paystack public key. |
| `PAYSTACK_BASE_URL` | Gateway API base URL; normally `https://api.paystack.co`. |
| `PAYSTACK_CURRENCY` | Gateway currency, normally `GHS`. |
| `CAMPUSLINK_CURRENCY` | Application currency code, normally `GHS`. |
| `CAMPUSLINK_CURRENCY_NAME` | Display currency name, normally `Ghana Cedi`. |
| `CAMPUSLINK_COMMISSION_BPS` | Marketplace commission rate in basis points. |

The application detects its base path from the document root. `CAMPUSLINK_BASE_URL` appears in the template but is not consumed by the current session/base-path implementation; configure the document root and `CAMPUSLINK_PUBLIC_ORIGIN` correctly instead.

## Administrator setup

No administrator is seeded by the schema. After configuring `.env` and importing the database, create one from the command line in the project root:

```bash
php scripts/create_admin.php admin@example.com "Administrator"
```

Replace the example email with the intended administrator address. The script generates a random password, stores only its hash, and prints the plaintext password once. Keep that output private and do not add it to Git, documentation, or issue trackers.

The script is CLI-only and returns `404` when invoked through the web server. The root `.htaccess` also denies HTTP access to `scripts/` and the setup/provisioning files.

## Payment integration

CampusLink selects its payment mode from the configured environment:

- **Paystack mode:** valid non-placeholder secret and public keys enable hosted checkout. The application initializes payment, verifies the transaction server-side on return, validates webhook HMAC signatures, and applies payment state only after verification.
- **Manual review mode:** absent or placeholder Paystack keys disable online card checkout. An administrator confirms campus payment from the administration area.

For Paystack, use test keys first and configure these public HTTPS endpoints in the Paystack dashboard:

```text
Callback: {CAMPUSLINK_PUBLIC_ORIGIN}{APP_BASE_URL}/public/paystack_callback.php
Webhook:  {CAMPUSLINK_PUBLIC_ORIGIN}{APP_BASE_URL}/public/paystack_webhook.php
```

`APP_BASE_URL` is the detected path under the host and is empty when CampusLink is deployed at the domain root. Configure `charge.success` and `paymentrequest.success` delivery; the receiver also handles refund and dispute events implemented by the application. Gateway currency must be `GHS`.

Paystack requires outbound HTTPS access from the PHP host. A successful browser redirect alone is not treated as proof of payment.

## Security considerations

- `.env` is excluded by `.gitignore`, blocked by Apache rules, and must contain real credentials only on the local machine or server.
- `.env.example` contains placeholders, not usable credentials.
- The root `.htaccess` disables directory indexes and denies dotfiles, SQL/log/environment files, private directories, setup scripts, and nested application paths.
- `storage/` is denied to HTTP clients. Uploaded PHP-like files are denied in `assets/uploads/`.
- Authentication uses hashed passwords, role guards, CSRF checks on state-changing forms, prepared SQL statements, rate limiting, security logging, and upload validation.
- Guest tracking links contain a private token and should not be shared publicly.
- Use HTTPS, a least-privilege database account, restricted file permissions, and a trusted proxy configuration in production.
- Keep backups and populated `.env` files outside the web root and source repository.

Apache protections depend on `.htaccess` being honored. nginx deployments must apply equivalent deny rules; `deploy/nginx-campuslink.conf.example` is a starting point, not an automatically applied configuration.

## Testing instructions

### Static PHP syntax checks

Run from the project root with PHP available on `PATH`.

PowerShell:

```powershell
Get-ChildItem -Recurse -Filter *.php | ForEach-Object { php -l $_.FullName }
```

macOS/Linux:

```bash
find . -name '*.php' -print0 | xargs -0 -n1 php -l
```

### Fixture-based administration check

The repository includes `scripts/test_admin_vendor_management.php`. It requires a configured test database, an administrator, and seller fixtures. It creates and updates a temporary product and audit rows before cleaning them up, and includes assumptions about existing fixture data. Run it only against a disposable test database, never production:

```bash
php scripts/test_admin_vendor_management.php
```

### Manual smoke checks

On a configured local or staging environment, verify at least:

1. Public catalog browsing, search, category filters, and product detail pages.
2. Buyer registration, login, logout, cart quantity updates, and checkout.
3. Guest checkout and private order tracking.
4. Seller registration, administrator approval, product creation/editing, and image upload.
5. Per-vendor order acceptance and fulfilment status changes.
6. Paystack test checkout and webhook processing, or administrator manual payment confirmation.
7. Administrator user/vendor/product/order/payment screens and financial reports.
8. Negative access checks: one buyer cannot open another buyer's order, and one seller cannot fulfil another seller's lines.
9. HTTP denial checks for `.env`, `config/`, `database/`, `storage/`, `scripts/`, `includes/`, `docs/`, and setup scripts.

No test results are asserted by this README; record results from the environment where the checks are executed.

## Deployment instructions

1. Review `docs/CAMPUSLINK_DEPLOYMENT_CHECKLIST.md` before exposing the application.
2. Deploy only the contents of this package to the intended document root; do not include the original canonical working directory or backup archives.
3. Configure Apache with `AllowOverride All` and `mod_rewrite`, or adapt `deploy/nginx-campuslink.conf.example` for the target nginx/PHP-FPM host.
4. Create the production database and dedicated database user, import the current schema, and apply only the migrations required for an existing database.
5. Create a server-only `.env` from `.env.example`; set the public origin, allowed hosts, database credentials, and Paystack keys as appropriate.
6. Configure HTTPS at the host or edge and ensure the Paystack callback/webhook URLs use the final public origin.
7. Make only `assets/uploads/`, `storage/logs/`, and `storage/ratelimit/` writable by PHP. Deny script execution in the upload directory.
8. Configure the host's mail transport if order/status email is required.
9. Create the administrator through the CLI, then complete the manual smoke and security checks above.
10. Schedule database and upload backups outside the public web root and document a restore procedure.

This repository does not contact or deploy to a university server, import a live database, or claim a completed production deployment.

## Known limitations

- Paystack integration depends on an external provider, valid credentials, supported currency configuration, and outbound network access. Without it, payment confirmation is manual.
- Email uses PHP `mail()` and depends on host-level mail/SMTP configuration; there is no in-application SMTP settings screen.
- Apache `.htaccess` protections do not apply to nginx unless equivalent rules are configured manually.
- Google Fonts require browser internet access; local font fallbacks are used when unavailable.
- The included administration test is fixture-dependent, and the repository does not contain a comprehensive automated end-to-end browser test suite.
- No live `.env`, Paystack credentials, database password, or administrator password is shipped.
- Base-path detection depends on a correct document-root layout; deployments in unusual subpath arrangements require verification of generated links and callback URLs.

## Additional documentation

- `database/README.md` — schema contents and migration order
- `docs/CAMPUSLINK_DEPLOYMENT_CHECKLIST.md` — production and security checklist
- `docs/CAMPUSLINK_PLATFORM_UX_STANDARDIZATION.md` — interface conventions
- `docs/CAMPUSLINK_PREMIUM_UI_IMPLEMENTATION.md` — UI implementation notes
- `assets/img/ATTRIBUTION.md` — image attribution information
- `deploy/nginx-campuslink.conf.example` — example nginx protections and PHP routing

