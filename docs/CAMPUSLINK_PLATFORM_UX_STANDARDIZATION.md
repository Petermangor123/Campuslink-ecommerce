# CampusLink – Platform Navigation, Terminology & UX Standardization

**Date:** 22 Nov 2026 (Atlantic/Reykjavik / UTC)  
**Scope:** Admin, Vendor, and Customer dashboards; public marketplace; checkout/tracking; shared UI components.

---

## 1. Existing navigation and terminology problems

Before this pass, CampusLink mixed marketplace language across roles:

| Problem | Impact |
|---|---|
| Parallel **Vendors** and **Sellers** admin entries | Duplicate destinations; unclear which screen owned vendor admin |
| **Track Order** in Admin navigation | Tracking belongs with Orders / secure public links, not admin chrome |
| **My Listings / Add Listing / Seller Workspace** | Inconsistent with customer-facing “product” language |
| **Storefront** vs **Browse** vs **Explore** | Same marketplace destination, different labels |
| **Sales Revenue** without settlement clarity | Risk of implying vendors already received payouts |
| Payment vs fulfilment labels mixed | `paid` shown as if delivery completed; `shipped` vs customer expectations |
| Footer / drawer still said “Seller dashboard”, “List an item” | Broke the unified marketplace vocabulary |
| Oversized account menus with role-specific clutter | Harder scanning on tablet/mobile |

Internal DB roles (`seller`, `buyer`), columns (`seller_id`, `payment_status='paid'`), and state-machine values were left unchanged.

---

## 2. Final Admin navigation

**MAIN MENU**

1. Dashboard  
2. Orders  
3. Payments  
4. Products  
5. Vendor Management  
6. Users  
7. Analytics & Reports  

**MARKETPLACE**

8. View Marketplace  

**ACCOUNT**

9. My Profile  
10. Sign Out  

Notes:

- `admin/sellers.php` now redirects to **Vendor Management** (`admin/vendors.php`) for URL compatibility.
- Account activate/deactivate for vendors lives on Vendor Management (merged from the old Sellers directory).
- Track Order is **not** in Admin nav; order detail remains available under Orders.
- Add Product is available from Products and Vendor Management (with correct vendor ownership).

---

## 3. Final Vendor navigation

**MAIN MENU**

1. Dashboard  
2. My Products  
3. Add Product  
4. Orders  
5. Sales & Earnings  
6. Store Profile  

**ACCOUNT**

7. My Profile  
8. View Marketplace  
9. Sign Out  

Notes:

- Role in session remains `seller`; UI labels say **Vendor**.
- Sales & Earnings separates **Paid Sales**, refunds, **Net Sales**, and **Outstanding Settlement** (not an automatic bank payout).
- Vendors only see their own products, order lines, and finance summary.

---

## 4. Final Customer navigation

**MAIN MENU** (public top nav / account flows)

1. Home  
2. Browse Products  
3. My Orders  
4. Track Order  
5. My Cart  

**ACCOUNT**

6. My Profile  
7. Sign Out  

Notes:

- Registered customers use **My Orders** as the primary purchase history.
- Guests keep secure tracking via `public/track.php` tokens.
- Payment status and fulfilment status are shown as separate concepts.

---

## 5. Terminology standardization table

| Existing / inconsistent | Preferred (UI) | Preserved internally |
|---|---|---|
| Add Listing | Add Product | — |
| My Listings | My Products | — |
| Track an Order / Track Order | Track Order | — |
| Storefront | View Marketplace | — |
| Seller Workspace | Vendor Dashboard | path `/seller/` |
| Seller (participant) | Vendor | DB role `seller`, `seller_id` |
| Buyer (participant) | Customer | DB role `buyer` |
| Product Listing | Product | — |
| Reports | Analytics & Reports | file `reports.php` |
| Sales Revenue | Paid Sales (where appropriate) | calculation helpers unchanged |
| payment `paid` (display) | Successful | enum `paid` |
| fulfilment `shipped` (display) | Dispatched | enum `shipped` |

---

## 6. Pages and components updated

### Shared

- `includes/ui.php` — `ui_dash_nav`, `ui_payment_label`, `ui_badge` / role labels  
- `includes/header.php` — top nav, account menu, dash titles, search placeholders  
- `includes/footer.php` — drawer + footer link labels  
- `includes/order_service.php` — `fulfilment_label` display map (prior pass)

### Admin

- `admin/dashboard.php`, `admin/vendors.php`, `admin/vendor_view.php`, `admin/sellers.php` (redirect)  
- `admin/products.php`, `admin/add_product.php`, `admin/edit_product.php`  
- `admin/reports.php`, `admin/order_view.php`, `admin/user_view.php`  
- **New:** `admin/profile.php`

### Vendor

- `seller/dashboard.php`, `seller/my_products.php`, `seller/add_product.php`, `seller/edit_product.php`  
- `seller/orders.php`  
- **New:** `seller/sales.php`, `seller/profile.php`

### Customer / public

- `buyer/dashboard.php`, `buyer/orders.php`, `buyer/order_detail.php`, `buyer/cart.php`, `buyer/checkout.php`, `buyer/profile.php`  
- `public/index.php`, `public/browse.php`, `public/product.php`, `public/track.php`

---

## 7. Files changed

Primary implementation files (non-exhaustive of every string touch):

```
includes/ui.php
includes/header.php
includes/footer.php
includes/order_service.php   (display labels)
admin/dashboard.php
admin/vendors.php
admin/vendor_view.php
admin/sellers.php
admin/products.php
admin/add_product.php
admin/edit_product.php
admin/reports.php
admin/order_view.php
admin/user_view.php
admin/profile.php            (new)
seller/dashboard.php
seller/my_products.php
seller/add_product.php
seller/edit_product.php
seller/orders.php
seller/sales.php             (new)
seller/profile.php           (new)
buyer/dashboard.php
buyer/orders.php
buyer/order_detail.php
buyer/cart.php
buyer/checkout.php
buyer/profile.php
public/index.php
public/browse.php
public/product.php
public/track.php
docs/CAMPUSLINK_PLATFORM_UX_STANDARDIZATION.md
```

---

## 8. Reusable components introduced or improved

| Component | Role |
|---|---|
| `ui_dash_nav($area)` | Single source for Admin / Vendor sidebar groups |
| `ui_payment_label()` | Maps DB payment enums → Pending / Successful / Failed / Refunded (+ Cancelled) |
| `fulfilment_label()` | Maps fulfilment enums → Awaiting Payment, Processing, Ready for Pickup, **Dispatched**, Delivered, Cancelled, etc. |
| `ui_badge()` / `ui_status_label()` | Consistent pills; role badges show Vendor / Customer |
| `ui_stat`, `ui_shortcut`, `ui_empty`, dash-welcome / settle-grid | Shared dashboard patterns |
| Account dropdown + footer/drawer | Aligned marketplace wording without a second design system |

---

## 9. Security and functionality preserved

- Authentication, CSRF, and `require_role` gates unchanged.  
- Vendor product ownership (`seller_id`) unchanged; admin Add Product still assigns the selected vendor.  
- Paystack initiation, server-side verification, webhooks, and duplicate-order / checkout-key behaviour untouched.  
- Vendors cannot manually confirm Paystack payments.  
- Secure order tracking tokens remain on the public track flow.  
- Paid-sales / settlement maths via `vendor_sales_summary()` unchanged — UI clarifies they are not automatic payouts.  
- Soft deactivate / activate and audit paths for Vendor Management retained; Sellers URL redirects only.  
- No database schema renames; no removal of useful endpoints without redirects/compatibility.

---

## 10. Responsive testing results

Manual layout review against existing CampusLink CSS breakpoints (`style.css` dash / nav / table-wrap / drawer patterns):

| Surface | Desktop | Tablet | Mobile |
|---|---|---|---|
| Admin sidebar groups | Pass — grouped MAIN / MARKETPLACE / ACCOUNT | Pass — sidebar scroll | Pass — dash shell / hamburger |
| Vendor sidebar + Sales/Profile | Pass | Pass | Pass — long settle grids wrap |
| Customer top nav | Pass — Home + Browse + Orders + Track | Pass | Pass — drawer labels updated |
| Account dropdown | Pass — trimmed Admin Track link | Pass | Pass |
| Product grids / tables | Pass | Pass — `.table-wrap` scroll | Pass — no intentional horizontal page overflow |
| Forms (Add Product, profiles) | Pass | Pass | Pass — full-width fields |

Automated browser matrix was not run in this environment; CSS/layout patterns reuse the existing responsive dash shell. Outstanding: visual QA in a live browser at 375 / 768 / 1280 widths after deploy.

---

## 11. Outstanding issues

1. **DB role name `seller`** remains for compatibility; only UI says Vendor.  
2. **Vendor “My Profile” and “Store Profile”** currently share `seller/profile.php` (acceptable for now; split later if account vs storefront fields diverge).  
3. **Internal variable names** (`listing_count`, `$listings`, POST `listing_action`) intentionally retained.  
4. **Public hero CTA** still uses “Sell on CampusLink” for guests — intentional marketing phrasing.  
5. **Full visual responsive QA** in Chrome/Firefox device mode still recommended after XAMPP deploy.  
6. Repository path is not a git repo in this workspace; commit/push must be done from the team’s version-controlled copy if required.

---

## Final navigation map

```
ADMIN
├── Main menu
│   ├── Dashboard          → /admin/dashboard.php
│   ├── Orders             → /admin/orders.php (+ order_view)
│   ├── Payments           → /admin/payments.php
│   ├── Products           → /admin/products.php (+ add/edit)
│   ├── Vendor Management  → /admin/vendors.php (+ vendor_view)
│   │                         /admin/sellers.php → redirect
│   ├── Users              → /admin/users.php
│   └── Analytics & Reports→ /admin/reports.php
├── Marketplace
│   └── View Marketplace   → /public/browse.php
└── Account
    ├── My Profile         → /admin/profile.php
    └── Sign Out           → /auth/logout.php

VENDOR (session role: seller)
├── Main menu
│   ├── Dashboard          → /seller/dashboard.php
│   ├── My Products        → /seller/my_products.php
│   ├── Add Product        → /seller/add_product.php
│   ├── Orders             → /seller/orders.php
│   ├── Sales & Earnings   → /seller/sales.php
│   └── Store Profile      → /seller/profile.php
└── Account
    ├── My Profile         → /seller/profile.php
    ├── View Marketplace   → /public/browse.php
    └── Sign Out           → /auth/logout.php

CUSTOMER (session role: buyer) + guests
├── Main menu
│   ├── Home               → / or /buyer/dashboard.php
│   ├── Browse Products    → /public/browse.php
│   ├── My Orders          → /buyer/orders.php   (signed-in)
│   ├── Track Order        → /public/track.php
│   └── My Cart            → /buyer/cart.php
└── Account
    ├── My Profile         → /buyer/profile.php
    └── Sign Out           → /auth/logout.php
```

---

*End of CampusLink platform UX standardization report.*
