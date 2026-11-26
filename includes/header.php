<?php
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/ui.php';

$initials = '';
if (is_logged_in()) {
    $parts    = explode(' ', trim(current_user_name()));
    $initials = strtoupper(substr($parts[0], 0, 1) . (isset($parts[1]) ? substr($parts[1], 0, 1) : ''));
}

$role = is_logged_in() ? current_user_role() : '';

$navCartQty = 0;
$showNavCart = !is_logged_in() || $role === 'buyer';
if ($showNavCart) {
    require_once __DIR__ . '/cart.php';
    if (!is_logged_in()) {
        $navCartQty = guest_cart_count();
    } elseif (isset($conn) && $conn instanceof mysqli) {
        $navCartQty = cart_quantity_total($conn, current_user_id());
    }
}

// Current path relative to the app base, used for active-state highlighting.
$script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
$rel    = $script;
if (APP_BASE_URL !== '' && strpos($script, APP_BASE_URL) === 0) {
    $rel = substr($script, strlen(APP_BASE_URL));
}
$rel = '/' . ltrim($rel, '/');
$isActive = static fn(string $path): string => $path === $rel ? ' is-active' : '';
$isNavActive = static function (string $path, array $also = []) use ($rel): string {
    if ($path === $rel || in_array($rel, $also, true)) {
        return ' is-active';
    }
    return '';
};
$isHomeActive = in_array($rel, ['/', '/index.php', '/public/index.php'], true);

// Workspace (seller / admin) detection.
$isDash   = ui_is_dashboard();
$dashArea = ui_dashboard_area();

$navCategories = [];
if (!$isDash && isset($conn) && $conn instanceof mysqli) {
    require_once __DIR__ . '/catalog.php';
    $navCategories = catalog_categories($conn);
}
$navCategory = trim((string)($_GET['category'] ?? ''));

if ($isDash) {
    $dashDefaults = [
        '/seller/dashboard.php'    => 'Dashboard',
        '/seller/my_products.php'  => 'My Products',
        '/seller/add_product.php'  => 'Add Product',
        '/seller/edit_product.php' => 'Edit Product',
        '/seller/orders.php'       => 'Orders',
        '/seller/sales.php'        => 'Sales & Earnings',
        '/seller/profile.php'      => 'Store Profile',
        '/admin/dashboard.php'     => 'Dashboard',
        '/admin/products.php'      => 'Products',
        '/admin/add_product.php'   => 'Add Product',
        '/admin/edit_product.php'  => 'Edit Product',
        '/admin/orders.php'        => 'Orders',
        '/admin/order_view.php'    => 'Order Detail',
        '/admin/users.php'         => 'Users',
        '/admin/user_view.php'     => 'User Detail',
        '/admin/vendors.php'       => 'Vendor Management',
        '/admin/vendor_view.php'   => 'Vendor Detail',
        '/admin/sellers.php'       => 'Vendor Accounts',
        '/admin/reports.php'       => 'Analytics & Reports',
        '/admin/payments.php'      => 'Payments',
        '/admin/profile.php'       => 'My Profile',
    ];
    $dashTitle = $dashTitle ?? ($dashDefaults[$rel] ?? 'Workspace');
    $dashSub   = $dashSub   ?? '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars(($pageTitle ?? 'CampusLink') === 'CampusLink' ? 'CampusLink – University Marketplace' : ($pageTitle ?? 'CampusLink') . ' · CampusLink') ?></title>
  <meta name="theme-color" content="#075B45">
  <meta name="description" content="CampusLink — the University of Juba marketplace for students, staff and campus sellers.">
  <?php if (!empty($noindex) || $isDash): ?><meta name="robots" content="noindex"><?php endif; ?>
  <?php
    $imgDir = dirname(__DIR__) . '/assets/img';
    $favSvgVer = is_file("$imgDir/favicon.svg") ? (string)filemtime("$imgDir/favicon.svg") : '1';
    $favIcoVer = is_file("$imgDir/favicon.ico") ? (string)filemtime("$imgDir/favicon.ico") : '1';
    $favPngVer = is_file("$imgDir/favicon-32.png") ? (string)filemtime("$imgDir/favicon-32.png") : '1';
    $appleVer  = is_file("$imgDir/apple-touch-icon.png") ? (string)filemtime("$imgDir/apple-touch-icon.png") : '1';
  ?>
  <link rel="icon" href="<?= APP_BASE_URL ?>/assets/img/favicon.svg?v=<?= htmlspecialchars($favSvgVer, ENT_QUOTES, 'UTF-8') ?>" type="image/svg+xml">
  <link rel="alternate icon" href="<?= APP_BASE_URL ?>/assets/img/favicon.ico?v=<?= htmlspecialchars($favIcoVer, ENT_QUOTES, 'UTF-8') ?>" sizes="any">
  <link rel="apple-touch-icon" href="<?= APP_BASE_URL ?>/assets/img/apple-touch-icon.png?v=<?= htmlspecialchars($appleVer, ENT_QUOTES, 'UTF-8') ?>">
  <link rel="icon" type="image/png" sizes="32x32" href="<?= APP_BASE_URL ?>/assets/img/favicon-32.png?v=<?= htmlspecialchars($favPngVer, ENT_QUOTES, 'UTF-8') ?>">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700&family=Sora:wght@500;600;700&display=swap">
  <?php
    $styleCssPath   = dirname(__DIR__) . '/assets/css/style.css';
    $premiumCssPath = dirname(__DIR__) . '/assets/css/premium.css';
    $styleCssVer    = is_file($styleCssPath) ? (string)filemtime($styleCssPath) : '1';
    $premiumCssVer  = is_file($premiumCssPath) ? (string)filemtime($premiumCssPath) : '1';
  ?>
  <link rel="stylesheet" href="<?= APP_BASE_URL ?>/assets/css/style.css?v=<?= htmlspecialchars($styleCssVer, ENT_QUOTES, 'UTF-8') ?>">
  <link rel="stylesheet" href="<?= APP_BASE_URL ?>/assets/css/premium.css?v=<?= htmlspecialchars($premiumCssVer, ENT_QUOTES, 'UTF-8') ?>">
</head>
<body<?= $isDash ? ' class="has-dash"' : '' ?>>

<a class="skip-link" href="#main">Skip to content</a>

<?php if (!$isDash): ?><div class="site-head"><?php endif; ?>
<header class="navbar<?= $isDash ? '' : ' navbar-market' ?>">
  <a class="brand" href="<?= APP_BASE_URL ?>/" aria-label="CampusLink home">
    <?= ui_brand_logo(!$isDash) ?>
  </a>

  <?php if (!$isDash): ?>
  <a class="nav-link desk-only nav-home<?= $isHomeActive ? ' is-active' : '' ?>" href="<?= APP_BASE_URL ?>/">
    <?= ui_icon('home', 'icn icn-sm') ?>Home
  </a>
  <form class="nav-search" role="search" action="<?= APP_BASE_URL ?>/public/browse.php" method="get">
    <label class="sr-only" for="navSearch">Search products</label>
    <?= ui_icon('search') ?>
    <input type="search" id="navSearch" name="q" placeholder="Search products..."
           value="<?= htmlspecialchars($_GET['q'] ?? '') ?>" autocomplete="off">
    <button type="submit">Search</button>
  </form>
  <?php endif; ?>

  <div class="nav-actions">
    <?php if (!$isDash): ?>
      <?php if ($role === 'buyer' || $role === ''): ?>
        <a class="nav-link desk-only<?= $isActive('/public/browse.php') ?>" href="<?= APP_BASE_URL ?>/public/browse.php">
          <?= ui_icon('compass', 'icn icn-sm') ?>Browse Products
        </a>
        <?php if ($role === 'buyer'): ?>
          <a class="nav-link desk-only<?= $isActive('/buyer/orders.php') ?>" href="<?= APP_BASE_URL ?>/buyer/orders.php">
            <?= ui_icon('orders', 'icn icn-sm') ?>My Orders
          </a>
        <?php endif; ?>
        <a class="nav-link desk-only<?= $isActive('/public/track.php') ?>" href="<?= APP_BASE_URL ?>/public/track.php">
          <?= ui_icon('truck', 'icn icn-sm') ?>Track Order
        </a>
      <?php else: ?>
        <a class="nav-link desk-only<?= $isActive('/public/browse.php') ?>" href="<?= APP_BASE_URL ?>/public/browse.php">
          <?= ui_icon('compass', 'icn icn-sm') ?>View Marketplace
        </a>
      <?php endif; ?>
    <?php endif; ?>

    <?php if ($role === 'seller'): ?>
      <a class="btn btn-primary btn-sm desk-only nav-sell" href="<?= APP_BASE_URL ?>/seller/add_product.php">
        <?= ui_icon('plus', 'icn icn-sm') ?>Add Product
      </a>
    <?php elseif ($role === ''): ?>
      <a class="btn btn-primary btn-sm desk-only nav-sell" href="<?= APP_BASE_URL ?>/auth/register.php?role=seller">
        <?= ui_icon('plus', 'icn icn-sm') ?>Sell on CampusLink
      </a>
    <?php endif; ?>

    <?php if ($showNavCart): ?>
      <a class="nav-icon-btn nav-cart<?= $isActive('/buyer/cart.php') ?>" href="<?= APP_BASE_URL ?>/buyer/cart.php"
         aria-label="Cart<?= $navCartQty > 0 ? ', ' . (int)$navCartQty . ' items' : '' ?>">
        <?= ui_icon('cart') ?>
        <?php if ($navCartQty > 0): ?>
          <span class="nav-cart-count"><?= $navCartQty > 99 ? '99+' : (int)$navCartQty ?></span>
        <?php endif; ?>
      </a>
    <?php endif; ?>

    <?php if (is_logged_in()): ?>
      <div class="acct" data-acct>
        <button type="button" class="acct-btn" data-acct-toggle aria-haspopup="true" aria-expanded="false">
          <span class="avatar" aria-hidden="true"><?= htmlspecialchars($initials) ?></span>
          <span class="acct-name desk-only"><?= htmlspecialchars(current_user_name()) ?></span>
          <?= ui_icon('chevron-down', 'icn icn-sm desk-only') ?>
        </button>
        <div class="acct-menu" data-acct-menu role="menu" aria-label="Account">
          <div class="acct-head">
            <div class="n"><?= htmlspecialchars(current_user_name()) ?></div>
            <?php if (!empty($_SESSION['user_email'])): ?>
              <div class="e"><?= htmlspecialchars($_SESSION['user_email']) ?></div>
            <?php endif; ?>
            <span class="acct-role"><?= htmlspecialchars($role === 'seller' ? 'Vendor' : ($role === 'buyer' ? 'Customer' : 'Admin')) ?></span>
          </div>
          <div class="acct-section" role="none">
          <?php if ($role === 'buyer'): ?>
            <a class="acct-item" role="menuitem" href="<?= APP_BASE_URL ?>/buyer/dashboard.php"><?= ui_icon('dashboard', 'icn icn-sm') ?>My Account</a>
            <a class="acct-item" role="menuitem" href="<?= APP_BASE_URL ?>/buyer/orders.php"><?= ui_icon('orders', 'icn icn-sm') ?>My Orders</a>
            <a class="acct-item" role="menuitem" href="<?= APP_BASE_URL ?>/buyer/cart.php"><?= ui_icon('cart', 'icn icn-sm') ?>My Cart</a>
            <a class="acct-item" role="menuitem" href="<?= APP_BASE_URL ?>/buyer/profile.php"><?= ui_icon('user', 'icn icn-sm') ?>My Profile</a>
          <?php elseif ($role === 'seller'): ?>
            <a class="acct-item" role="menuitem" href="<?= APP_BASE_URL ?>/seller/dashboard.php"><?= ui_icon('dashboard', 'icn icn-sm') ?>Dashboard</a>
            <a class="acct-item" role="menuitem" href="<?= APP_BASE_URL ?>/seller/my_products.php"><?= ui_icon('package', 'icn icn-sm') ?>My Products</a>
            <a class="acct-item" role="menuitem" href="<?= APP_BASE_URL ?>/seller/orders.php"><?= ui_icon('orders', 'icn icn-sm') ?>Orders</a>
            <a class="acct-item" role="menuitem" href="<?= APP_BASE_URL ?>/seller/sales.php"><?= ui_icon('dollar', 'icn icn-sm') ?>Sales &amp; Earnings</a>
            <a class="acct-item" role="menuitem" href="<?= APP_BASE_URL ?>/seller/profile.php"><?= ui_icon('user', 'icn icn-sm') ?>My Profile</a>
          <?php elseif ($role === 'admin'): ?>
            <a class="acct-item" role="menuitem" href="<?= APP_BASE_URL ?>/admin/dashboard.php"><?= ui_icon('dashboard', 'icn icn-sm') ?>Dashboard</a>
            <a class="acct-item" role="menuitem" href="<?= APP_BASE_URL ?>/admin/orders.php"><?= ui_icon('orders', 'icn icn-sm') ?>Orders</a>
            <a class="acct-item" role="menuitem" href="<?= APP_BASE_URL ?>/admin/payments.php"><?= ui_icon('card', 'icn icn-sm') ?>Payments</a>
            <a class="acct-item" role="menuitem" href="<?= APP_BASE_URL ?>/admin/vendors.php"><?= ui_icon('store', 'icn icn-sm') ?>Vendor Management</a>
            <a class="acct-item" role="menuitem" href="<?= APP_BASE_URL ?>/admin/users.php"><?= ui_icon('users', 'icn icn-sm') ?>Users</a>
            <a class="acct-item" role="menuitem" href="<?= APP_BASE_URL ?>/admin/profile.php"><?= ui_icon('user', 'icn icn-sm') ?>My Profile</a>
          <?php endif; ?>
          </div>
          <div class="acct-sep" role="separator"></div>
          <div class="acct-section" role="none">
            <a class="acct-item" role="menuitem" href="<?= APP_BASE_URL ?>/public/browse.php"><?= ui_icon('compass', 'icn icn-sm') ?>View Marketplace</a>
            <?php if ($role !== 'admin'): ?>
              <a class="acct-item" role="menuitem" href="<?= APP_BASE_URL ?>/public/track.php"><?= ui_icon('truck', 'icn icn-sm') ?>Track Order</a>
            <?php endif; ?>
          </div>
          <div class="acct-sep" role="separator"></div>
          <a class="acct-item danger" role="menuitem" href="<?= APP_BASE_URL ?>/auth/logout.php"><?= ui_icon('logout', 'icn icn-sm') ?>Sign Out</a>
        </div>
      </div>
    <?php else: ?>
      <a class="nav-link desk-only nav-signin" href="<?= APP_BASE_URL ?>/auth/login.php">
        <?= ui_icon('login', 'icn icn-sm') ?>Sign in
      </a>
      <a class="btn btn-secondary btn-sm desk-only nav-register" href="<?= APP_BASE_URL ?>/auth/register.php">
        <?= ui_icon('user', 'icn icn-sm') ?>Register
      </a>
    <?php endif; ?>

    <?php if (!$isDash): ?>
      <button type="button" class="nav-icon-btn nav-toggle" data-drawer-open aria-label="Open menu" aria-controls="navDrawer" aria-expanded="false">
        <?= ui_icon('menu') ?>
      </button>
    <?php endif; ?>
  </div>

  <?php if (!$isDash): ?>
  <form class="nav-search-mobile" role="search" action="<?= APP_BASE_URL ?>/public/browse.php" method="get">
    <label class="sr-only" for="navSearchM">Search products</label>
    <?= ui_icon('search') ?>
    <input type="search" id="navSearchM" name="q" placeholder="Search products..."
           value="<?= htmlspecialchars($_GET['q'] ?? '') ?>" autocomplete="off">
    <button type="submit" aria-label="Search"><?= ui_icon('search', 'icn-sm') ?></button>
  </form>
  <?php endif; ?>
</header>
<?php if (!$isDash): ?>
  <?php if ($navCategories): ?>
  <nav class="catnav" aria-label="Categories">
    <a href="<?= APP_BASE_URL ?>/public/browse.php"<?= $navCategory === '' && $rel === '/public/browse.php' ? ' class="is-active"' : '' ?>>All</a>
    <?php foreach ($navCategories as $navCat): ?>
      <a href="<?= APP_BASE_URL ?>/public/browse.php?category=<?= rawurlencode($navCat['name']) ?>"<?= $navCategory === $navCat['name'] ? ' class="is-active"' : '' ?>>
        <?= htmlspecialchars($navCat['name']) ?>
      </a>
    <?php endforeach; ?>
  </nav>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php ui_flash(); ?>

<?php if ($isDash): ?>
<div class="dash">
  <aside class="dash-side" id="dashSide">
    <div class="dash-side-header">
      <a class="brand brand-light" href="<?= APP_BASE_URL ?>/" aria-label="CampusLink home">
        <?= ui_brand_logo(true) ?>
      </a>
      <button type="button" class="nav-icon-btn dash-close" data-dash-close aria-label="Close menu"><?= ui_icon('x') ?></button>
    </div>

    <span class="dash-area"><?= $dashArea === 'admin' ? 'Administration' : 'Vendor Dashboard' ?></span>

    <nav class="dash-nav" aria-label="<?= $dashArea === 'admin' ? 'Administration' : 'Vendor' ?> navigation">
      <?php foreach (ui_dash_nav($dashArea) as $item): ?>
        <?php if ($item[0] === 'Group'): ?>
          <span class="dash-nav-label"><?= htmlspecialchars($item[1]) ?></span>
        <?php else: ?>
          <a href="<?= $item[1] ?>"<?= $isNavActive($item[3], $item[4] ?? []) ?>>
            <?= ui_icon($item[2]) ?><span><?= htmlspecialchars($item[0]) ?></span>
          </a>
        <?php endif; ?>
      <?php endforeach; ?>
    </nav>

    <div class="dash-side-foot">
      <div class="dash-user">
        <span class="avatar" aria-hidden="true"><?= htmlspecialchars($initials) ?></span>
        <span class="info">
          <span class="nm"><?= htmlspecialchars(current_user_name()) ?></span>
          <span class="rl"><?= htmlspecialchars($role === 'seller' ? 'Vendor' : ($role === 'admin' ? 'Admin' : $role)) ?></span>
        </span>
      </div>
    </div>
  </aside>

  <div class="dash-scrim" data-dash-scrim></div>

  <div class="dash-main">
    <div class="dash-topbar">
      <button type="button" class="nav-icon-btn dash-menu-btn" data-dash-open aria-label="Open workspace menu" aria-controls="dashSide" aria-expanded="false">
        <?= ui_icon('menu') ?>
      </button>
      <div>
        <h1 class="dash-title"><?= htmlspecialchars($dashTitle) ?></h1>
        <?php if (!empty($dashSub)): ?><span class="dash-topbar-sub"><?= htmlspecialchars($dashSub) ?></span><?php endif; ?>
      </div>
      <div class="dash-topbar-actions"><?= $dashActions ?? '' ?></div>
    </div>
    <div class="dash-content" id="main" tabindex="-1">
<?php else: ?>
<main class="container" id="main" tabindex="-1">
<?php endif; ?>
