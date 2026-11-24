# CampusLink

University marketplace for Group 17, prepared for the University of Juba, South Sudan.

The project is maintained by Group 17.

## Project description

CampusLink is a campus marketplace where students can browse listings, create a buyer or seller account, sell their own items, add products to a cart, and place an order. An administrator manages users, products, and order status.

## Problem statement

Students need a single place to find and offer textbooks, electronics, clothing, furniture, and other campus items. A public storefront is not enough on its own: sellers need to manage their own listings, buyers need a cart and an order record, and staff need a way to confirm payment and update delivery status.

## Proposed solution

CampusLink is a PHP and MySQL web application that runs on XAMPP. Visitors browse products without an account. Registered buyers and guests can check out. Sellers manage their own products only after an administrator approves their vendor account. When Paystack keys are set, checkout is verified with Paystack (amounts are shown as GHC and charged as GHS). Without those keys, an administrator confirms campus payment by hand.

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

- PHP 8 with the `mysqli` and `fileinfo` extensions
- MySQL 8, or the MariaDB server shipped with XAMPP
- Apache with `.htaccess` support
- HTML, CSS, and one vanilla JavaScript file
- Google Fonts (Inter and Space Grotesk) when the browser is online

## Project folder structure

```
CampusLink_Gr_17/
├── index.php                 # Opens the storefront (public/index.php)
├── README.md
├── .htaccess
├── .env                      # Runtime database settings; not for Git
├── .gitignore
├── public/
│   ├── index.php             # Homepage
│   ├── browse.php
│   ├── product.php
│   ├── track.php
│   ├── pay.php               # Starts Paystack checkout
│   ├── paystack_callback.php
│   └── paystack_webhook.php
├── auth/                     # Login, registration, logout
├── buyer/                    # Profile, cart, checkout, orders
├── seller/
├── admin/
├── includes/
├── config/db.php
├── database/
│   ├── campuslink.sql
│   └── README.md
├── assets/
│   ├── css/
│   ├── js/
│   ├── img/
│   └── uploads/
├── storage/                  # Private logs and rate-limit data
└── docs/                     # Brand and deployment notes
```

