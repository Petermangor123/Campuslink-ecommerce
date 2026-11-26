<?php
// Shared page footer + mobile drawer + scripts.
$role = is_logged_in() ? current_user_role() : '';
?>
<?php if (!empty($isDash)): ?>
    </div><!-- /.dash-content -->
  </div><!-- /.dash-main -->
</div><!-- /.dash -->
<?php else: ?>
</main>

<div class="drawer-scrim" data-drawer-scrim></div>
<aside class="drawer" id="navDrawer" role="dialog" aria-modal="true" aria-label="Site menu" tabindex="-1">
  <div class="drawer-head">
    <a class="brand" href="<?= APP_BASE_URL ?>/" aria-label="CampusLink home">
      <?= ui_brand_logo() ?>
    </a>
    <button type="button" class="nav-icon-btn" data-drawer-close aria-label="Close menu"><?= ui_icon('x') ?></button>
  </div>

  <div class="drawer-search">
    <form role="search" action="<?= APP_BASE_URL ?>/public/browse.php" method="get">
      <label class="sr-only" for="drawerSearch">Search products</label>
      <?= ui_icon('search') ?>
      <input type="search" id="drawerSearch" name="q" placeholder="Search products…"
             value="<?= htmlspecialchars($_GET['q'] ?? '') ?>" autocomplete="off">
      <button type="submit" class="btn btn-primary btn-sm">Go</button>
    </form>
  </div>

  <nav class="drawer-nav" aria-label="Site">
    <a href="<?= APP_BASE_URL ?>/"><?= ui_icon('home') ?><span>Home</span></a>
    <a href="<?= APP_BASE_URL ?>/public/browse.php"><?= ui_icon('compass') ?><span>Browse Products</span></a>
    <a href="<?= APP_BASE_URL ?>/public/track.php"><?= ui_icon('truck') ?><span>Track Order</span></a>
    <?php if ($showNavCart ?? false): ?>
      <a href="<?= APP_BASE_URL ?>/buyer/cart.php"><?= ui_icon('cart') ?><span>My Cart<?= ($navCartQty ?? 0) > 0 ? ' (' . (int)$navCartQty . ')' : '' ?></span></a>
    <?php endif; ?>

    <?php if (!empty($navCategories)): ?>
    <div class="drawer-sep"></div>
    <span class="drawer-label">Categories</span>
    <?php foreach ($navCategories as $navCat): ?>
      <a href="<?= APP_BASE_URL ?>/public/browse.php?category=<?= rawurlencode($navCat['name']) ?>"><?= ui_icon(ui_category_icon($navCat['name'])) ?><span><?= e($navCat['name']) ?></span></a>
    <?php endforeach; ?>
    <?php endif; ?>

    <div class="drawer-sep"></div>
    <span class="drawer-label">Account</span>
    <?php if (is_logged_in()): ?>
      <?php if ($role === 'buyer'): ?>
        <a href="<?= APP_BASE_URL ?>/buyer/dashboard.php"><?= ui_icon('dashboard') ?><span>Home</span></a>
        <a href="<?= APP_BASE_URL ?>/buyer/orders.php"><?= ui_icon('orders') ?><span>My Orders</span></a>
        <a href="<?= APP_BASE_URL ?>/buyer/profile.php"><?= ui_icon('user') ?><span>My Profile</span></a>
      <?php elseif ($role === 'seller'): ?>
        <a href="<?= APP_BASE_URL ?>/seller/dashboard.php"><?= ui_icon('dashboard') ?><span>Vendor Dashboard</span></a>
        <a href="<?= APP_BASE_URL ?>/seller/add_product.php"><?= ui_icon('plus') ?><span>Add Product</span></a>
      <?php elseif ($role === 'admin'): ?>
        <a href="<?= APP_BASE_URL ?>/admin/dashboard.php"><?= ui_icon('dashboard') ?><span>Admin Dashboard</span></a>
      <?php endif; ?>
      <a href="<?= APP_BASE_URL ?>/auth/logout.php"><?= ui_icon('logout') ?><span>Sign Out</span></a>
    <?php else: ?>
      <a href="<?= APP_BASE_URL ?>/auth/login.php"><?= ui_icon('login') ?><span>Sign in</span></a>
      <a href="<?= APP_BASE_URL ?>/auth/register.php"><?= ui_icon('user') ?><span>Create account</span></a>
      <a href="<?= APP_BASE_URL ?>/auth/register.php?role=seller"><?= ui_icon('store') ?><span>Become a Vendor</span></a>
    <?php endif; ?>
  </nav>
</aside>
<?php endif; ?>

<footer class="site-footer">
  <div class="footer-grid">
    <div class="footer-brand">
      <div class="fn"><?= ui_brand_logo(true) ?></div>
      <p>The University of Juba marketplace. Buy and sell with students and staff on campus.</p>
    </div>
    <div class="fcol">
      <h4>Marketplace</h4>
      <ul>
        <li><a href="<?= APP_BASE_URL ?>/">Home</a></li>
        <li><a href="<?= APP_BASE_URL ?>/public/browse.php">Browse Products</a></li>
        <?php foreach (array_slice($navCategories ?? [], 0, 6) as $navCat): ?>
          <li><a href="<?= APP_BASE_URL ?>/public/browse.php?category=<?= rawurlencode($navCat['name']) ?>"><?= e($navCat['name']) ?></a></li>
        <?php endforeach; ?>
      </ul>
    </div>
    <div class="fcol">
      <h4>Account</h4>
      <ul>
        <?php if ($role === 'buyer'): ?>
          <li><a href="<?= APP_BASE_URL ?>/buyer/dashboard.php">Home</a></li>
          <li><a href="<?= APP_BASE_URL ?>/buyer/orders.php">My Orders</a></li>
          <li><a href="<?= APP_BASE_URL ?>/buyer/cart.php">My Cart</a></li>
          <li><a href="<?= APP_BASE_URL ?>/buyer/profile.php">My Profile</a></li>
        <?php elseif ($role === 'seller'): ?>
          <li><a href="<?= APP_BASE_URL ?>/seller/dashboard.php">Vendor Dashboard</a></li>
          <li><a href="<?= APP_BASE_URL ?>/seller/my_products.php">My Products</a></li>
          <li><a href="<?= APP_BASE_URL ?>/seller/orders.php">Orders</a></li>
          <li><a href="<?= APP_BASE_URL ?>/seller/sales.php">Sales &amp; Earnings</a></li>
        <?php elseif ($role === 'admin'): ?>
          <li><a href="<?= APP_BASE_URL ?>/admin/dashboard.php">Admin Dashboard</a></li>
          <li><a href="<?= APP_BASE_URL ?>/admin/orders.php">Orders</a></li>
          <li><a href="<?= APP_BASE_URL ?>/admin/vendors.php">Vendor Management</a></li>
          <li><a href="<?= APP_BASE_URL ?>/admin/users.php">Users</a></li>
          <li><a href="<?= APP_BASE_URL ?>/admin/commission.php">Commission</a></li>
        <?php else: ?>
          <li><a href="<?= APP_BASE_URL ?>/auth/login.php">Sign in</a></li>
          <li><a href="<?= APP_BASE_URL ?>/auth/register.php">Create account</a></li>
          <li><a href="<?= APP_BASE_URL ?>/buyer/cart.php">My Cart</a></li>
        <?php endif; ?>
        <li><a href="<?= APP_BASE_URL ?>/public/track.php">Track Order</a></li>
      </ul>
    </div>
    <div class="fcol">
      <h4>Sell</h4>
      <ul>
        <?php if ($role === 'seller'): ?>
          <li><a href="<?= APP_BASE_URL ?>/seller/add_product.php">Add Product</a></li>
          <li><a href="<?= APP_BASE_URL ?>/seller/dashboard.php">Vendor Dashboard</a></li>
          <li><a href="<?= APP_BASE_URL ?>/seller/orders.php">Fulfil Orders</a></li>
        <?php elseif ($role === 'admin'): ?>
          <li><a href="<?= APP_BASE_URL ?>/admin/vendors.php">Vendor Management</a></li>
          <li><a href="<?= APP_BASE_URL ?>/admin/products.php">Products</a></li>
        <?php elseif ($role === 'buyer'): ?>
          <li><a href="<?= APP_BASE_URL ?>/auth/register.php?role=seller">Become a Vendor</a></li>
          <li><a href="<?= APP_BASE_URL ?>/public/browse.php">Browse Products</a></li>
        <?php else: ?>
          <li><a href="<?= APP_BASE_URL ?>/auth/register.php?role=seller">Become a Vendor</a></li>
          <li><a href="<?= APP_BASE_URL ?>/auth/login.php">Vendor sign in</a></li>
        <?php endif; ?>
      </ul>
    </div>
  </div>
  <div class="footer-bottom">
    <span>&copy; <?= date('Y') ?> CampusLink &mdash; CS 442 Team 17</span>
  </div>
</footer>

<?php
  $appJsPath = dirname(__DIR__) . '/assets/js/app.js';
  $appJsVer  = is_file($appJsPath) ? (string)filemtime($appJsPath) : '1';
?>
<script src="<?= APP_BASE_URL ?>/assets/js/app.js?v=<?= htmlspecialchars($appJsVer, ENT_QUOTES, 'UTF-8') ?>" defer></script>
</body>
</html>
