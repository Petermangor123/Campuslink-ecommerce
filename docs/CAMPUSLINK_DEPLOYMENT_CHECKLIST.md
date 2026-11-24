# CampusLink – Production Deployment Checklist

**Date:** 24 Nov 2026 (UTC)  
**Scope:** Current Apache deployment and security checks.  
**Rule:** Never paste real passwords or secret keys into tickets, chat, or git.

---

## 0. Deployment security gate

- [ ] **Deny HTTP access** to `scripts/` and `includes/` (Apache example below).  
- [ ] **Delete or deny** root file `-b` (curl cookie jar containing a session id).  
- [ ] Confirm host honors `.htaccess` (`AllowOverride All` for the vhost / or translate rules to nginx).  
- [ ] Confirm `/.env`, `/config/`, `/database/`, `/storage/`, `/backups/`, `/docs/`, and `/internal/` return **403**.  
- [ ] Confirm `/scripts/anything.php` returns **403** (not 200).  

### Suggested Apache additions (root `.htaccess` or vhost)

Extend the existing sensitive-directory rewrite (already denies `backups|database|storage|vendor|node_modules`) to also deny application internals and tooling:

```apache
RewriteRule ^(backups|database|storage|vendor|node_modules|scripts|includes|docs|internal)(/|$) - [F,L]
```

Also deny odd junk files if present:

```apache
<FilesMatch "^(-b)$">
    Require all denied
</FilesMatch>
```

Alternatively place empty `scripts/.htaccess` and `includes/.htaccess` containing only:

```apache
Require all denied
```

Keep these rules in the deployed root `.htaccess`.

---

## 1. Backup local environment

- [ ] Export MySQL: `mysqldump -u … -p campuslink > campuslink_backup_YYYYMMDD.sql`  
- [ ] Zip project files **excluding** `storage/ratelimit/*` noise if desired; **include** `assets/uploads` if you need existing images  
- [ ] Store backups **off** the public web root  

---

## 2. Prepare production database

- [ ] Create empty database + dedicated DB user (least privilege: SELECT/INSERT/UPDATE/DELETE on `ecommerce_2026A_chol_thiong` only; no FILE privilege)  
- [ ] Import `database/campuslink.sql`  
- [ ] **Then apply migrations in order** (critical for Paystack):

| Order | File | Why |
|---|---|---|
| 1 | `migrations/006_payment_events.sql` | Creates `payment_events` (**missing from dump**) |
| 2 | `migrations/008_payment_events_process_status.sql` | Webhook claim/retry columns |
| 3 | Others only if upgrading an older DB | See `database/README.md` |

Fresh dump already includes `users.is_active`, `products.is_active`, `admin_audit_log`, fulfilment columns — do **not** blindly re-run destructive reverses.

- [ ] Verify: `SHOW TABLES` includes `payment_events`  
- [ ] Verify: `SHOW COLUMNS FROM payment_events LIKE 'process_status'`  

---

## 3. Configure production `.env`

Copy from `.env.example`, then set (values illustrative):

```ini
CAMPUSLINK_ENV=production
CAMPUSLINK_DB_HOST=YOUR_DB_HOST
CAMPUSLINK_DB_USER=YOUR_DB_USER
CAMPUSLINK_DB_PASS=YOUR_DB_PASSWORD
CAMPUSLINK_DB_NAME=ecommerce_2026A_chol_thiong
CAMPUSLINK_DB_PORT=3306

# Path under the domain. Empty or omit concept: APP_BASE_URL is auto-detected
# from document root. If the app lives at domain root, detection yields "".
# Fallback in code is /CampusLink_Gr_17 — prefer deploying so detection is correct.

CAMPUSLINK_PUBLIC_ORIGIN=https://your.domain.tld

CAMPUSLINK_ALLOWED_HOSTS=your.domain.tld,www.your.domain.tld
CAMPUSLINK_ENFORCE_HOST=1
# Only if behind a trusted reverse proxy that sets X-Forwarded-Proto:
# CAMPUSLINK_TRUST_PROXY=1

PAYSTACK_SECRET_KEY=sk_test_...   # use sk_live_... only when going live
PAYSTACK_PUBLIC_KEY=pk_test_...
PAYSTACK_BASE_URL=https://api.paystack.co
PAYSTACK_CURRENCY=GHS
CAMPUSLINK_CURRENCY=GHS
CAMPUSLINK_CURRENCY_NAME=Ghana Cedi
```

- [ ] File permissions: `.env` readable by PHP only; not world-writable  
- [ ] Confirm web GET `/.env` → **403**  

**Note:** `CAMPUSLINK_BASE_URL` in `.env.example` is **not** consumed by `includes/session.php` today. Base path comes from filesystem detection relative to `DOCUMENT_ROOT`.

---

## 4. Application URL & HTTPS

- [ ] Site opens as `https://your.domain.tld/...`  
- [ ] HTTP → HTTPS redirect at host/CDN  
- [ ] After HTTPS, confirm response includes `Strict-Transport-Security` and cookie `Secure`  
- [ ] Paystack callback constructed as:  
  `{CAMPUSLINK_PUBLIC_ORIGIN}{APP_BASE_URL}/public/paystack_callback.php`  

---

## 5. File / folder permissions

| Path | Need |
|---|---|
| `assets/uploads/` | Writable by PHP; PHP execution denied (existing uploads `.htaccess`) |
| `storage/logs/` | Writable |
| `storage/ratelimit/` | Writable |
| Code dirs | Not writable by web user if possible |
| `.env` | Readable by app only |

- [ ] Upload a test product image as vendor  
- [ ] Confirm uploaded file is not executable as PHP  

---

## 6. PHP / hosting requirements

- [ ] PHP **8.0+** (8.1/8.2 preferred)  
- [ ] Extensions: `mysqli`, `curl`, `openssl`, `gd`, `fileinfo`, `mbstring`, `json`, `session`  
- [ ] `upload_max_filesize` / `post_max_size` ≥ product image limit (app uses up to ~5MB class limits)  
- [ ] Outbound HTTPS allowed to `api.paystack.co`  
- [ ] No Composer install step required  

---

## 7. Paystack configuration

### Test mode first (recommended tonight)

- [ ] Dashboard → Test keys in `.env`  
- [ ] Callback URL matches production HTTPS callback  
- [ ] Webhook URL:  
  `https://your.domain.tld{APP_BASE_URL}/public/paystack_webhook.php`  
- [ ] Events at least: `charge.success` (and failed variants if available)  
- [ ] Currency **GHS** (never send GHC to Paystack)  

### Going live later

- [ ] Switch to `sk_live_` / `pk_live_`  
- [ ] Re-test one small live payment  
- [ ] Keep admin “mark paid” for campus cash only under written policy  

---

## 8. Email

- [ ] Configure host SMTP or sendmail so PHP `mail()` works, **or** plan manual order notification process  
- [ ] Place a test order and confirm customer email attempt (check `storage/logs/php-error.log` if fails)  

---

## 9. Bootstrap administrator

- [ ] Register a normal account via UI  
- [ ] In DB: `UPDATE users SET role='admin' WHERE email='…' LIMIT 1;`  
- [ ] Sign out / sign in → Admin Dashboard  
- [ ] Deactivate a test vendor account and confirm login blocked  

---

## 10. Functional smoke (post-deploy)

### Customer

- [ ] Register / login / logout  
- [ ] Browse Products → product → add to cart → update qty  
- [ ] Checkout (test Paystack) → return to Track Order  
- [ ] Payment badge shows Successful / paid after verify  
- [ ] My Orders shows purchase  

### Vendor

- [ ] Register vendor / login  
- [ ] Add Product + image  
- [ ] Edit stock  
- [ ] Accept paid order line → Processing → Ready / Dispatched  

### Admin

- [ ] Dashboard loads live counts  
- [ ] Vendor Management: add product for vendor (ownership = vendor)  
- [ ] Products deactivate hides from catalog  
- [ ] Payments + Orders screens  
- [ ] Analytics & Reports  

### Security

- [ ] Customer A cannot open Customer B’s `order_detail` by id  
- [ ] Vendor B cannot fulfil Vendor A’s lines  
- [ ] Webhook with bad signature → 401  
- [ ] `scripts/` → 403  

---

## 11. Error logging & monitoring

- [ ] `storage/logs/php-error.log` writable  
- [ ] `storage/logs/security.log` receiving events  
- [ ] Paystack dashboard webhook delivery log reviewed after first test pay  

---

## 12. Backup & recovery (production)

- [ ] Nightly DB dump scheduled  
- [ ] Uploads directory included in file backup  
- [ ] Document restore steps (import SQL + restore uploads + `.env`)  
- [ ] Test restore on a staging copy when possible  

---

## 13. Go-live decision tree

1. All **§0 CRITICAL** boxes checked?  
2. DB has `payment_events.process_status`?  
3. Test Paystack payment verified server-side (not redirect-only)?  
4. Webhook applied at least once successfully?  
5. Admin/vendor/customer smoke passed?

| If | Then |
|---|---|
| Yes to all | Proceed with **soft launch** (campus pilot) |
| Any CRITICAL open | **Do not** expose publicly |
| Paystack untested on host | Stay on test keys; campus cash via admin confirm only |

---

## Files that need attention (no secrets)

| File | Action |
|---|---|
| `.env` | Create on server; never commit |
| `.env.example` | Template only — incomplete vs production (`CAMPUSLINK_ENV`, hosts) |
| `.htaccess` | Ensure denies + extend for `scripts`/`includes` |
| `config/db.php` | Reads env; no edit if `.env` correct |
| `includes/session.php` | Sets `APP_BASE_URL` via detection |
| `includes/security.php` | Headers / CSP / host enforce |
| `includes/payment_gateway.php` | Callback origin from `CAMPUSLINK_PUBLIC_ORIGIN` |
| `database/campuslink.sql` + migrations `006`,`008` | Production schema path |
| `public/paystack_webhook.php` | Public HTTPS endpoint |
| `public/paystack_callback.php` | Return URL |
| `assets/uploads/`, `storage/` | Permissions |

---

*End of deployment checklist.*
