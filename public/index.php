<?php
// CampusLink – Marketplace homepage
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/catalog.php';
require_once __DIR__ . '/../includes/payment_gateway.php';

$stats     = catalog_stats($conn);
$cats      = catalog_categories($conn);
$featured  = catalog_products($conn, [
    'availability' => 'in',
    'has_image'    => true,
    'sort'         => 'newest',
    'limit'        => 8,
]);
$recent    = catalog_products($conn, [
    'sort'    => 'newest',
    'exclude' => array_map(static fn(array $row): int => (int)$row['id'], $featured),
    'limit'   => 8,
]);
$popular   = catalog_popular($conn, 8);
$sellers   = catalog_sellers($conn, 8);

$canSell  = !is_logged_in() || current_user_role() === 'seller';
$sellHref = !is_logged_in()
    ? APP_BASE_URL . '/auth/register.php?role=seller'
    : APP_BASE_URL . '/seller/add_product.php';
$hasListings = $stats['listings'] > 0;

$pageTitle = 'CampusLink';

$heroSlides = [
    ['slug' => 'electronics', 'label' => 'Electronics', 'alt' => 'Laptop and campus electronics ready to trade'],
    ['slug' => 'clothing',    'label' => 'Clothing',    'alt' => 'Clothing and fashion items for campus life'],
    ['slug' => 'furniture',   'label' => 'Furniture',   'alt' => 'Comfortable furniture suited to student rooms'],
    ['slug' => 'stationery',  'label' => 'Stationery',  'alt' => 'Notebooks and stationery for study'],
    ['slug' => 'textbooks',   'label' => 'Textbooks',   'alt' => 'Textbooks and study reading materials'],
    ['slug' => 'campus',      'label' => 'Campus goods','alt' => 'Everyday campus study essentials'],
];
?>
<?php require_once __DIR__ . '/../includes/header.php'; ?>

<section class="hero">
  <div class="hero-copy">
    <span class="hero-badge"><?= ui_icon('map-pin', 'icn-xs') ?> University of Juba marketplace</span>
    <h1>CampusLink</h1>
    <p>Buy and sell textbooks, electronics, clothing and everyday campus goods with students and staff on campus.</p>
    <div class="hero-btns">
      <a href="<?= APP_BASE_URL ?>/public/browse.php" class="btn btn-accent btn-lg">
        Browse Products <?= ui_icon('arrow-right', 'icn-sm') ?>
      </a>
      <?php if ($canSell): ?>
        <a href="<?= e($sellHref) ?>" class="btn btn-white btn-lg">Sell on CampusLink</a>
      <?php endif; ?>
    </div>
    <?php
      $heroStats = array_filter([
          $stats['listings'] > 0 ? [$stats['listings'], 'Products'] : null,
          $stats['in_stock'] > 0 ? [$stats['in_stock'], 'In stock'] : null,
          $stats['sellers'] > 0 ? [$stats['sellers'], 'Vendors'] : null,
          $stats['buyers'] > 0 ? [$stats['buyers'], 'Customers'] : null,
      ]);
    ?>
    <?php if ($heroStats): ?>
      <div class="hero-stats">
        <?php foreach ($heroStats as [$count, $label]): ?>
          <div class="hero-stat"><div class="n num"><?= number_format($count) ?></div><div class="l"><?= e($label) ?></div></div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <div class="hero-art">
    <div class="hero-frame hero-carousel" data-hero-carousel data-interval="5500" aria-roledescription="carousel" aria-label="Campus marketplace photo highlights">
      <div class="hero-slides">
        <?php foreach ($heroSlides as $i => $slide):
          $webp = APP_BASE_URL . '/assets/img/hero/slide-' . $slide['slug'] . '.webp';
          $jpg  = APP_BASE_URL . '/assets/img/hero/slide-' . $slide['slug'] . '.jpg';
          $active = $i === 0;
        ?>
          <figure class="hero-slide<?= $active ? ' is-active' : '' ?>" data-slide="<?= (int)$i ?>"<?= $active ? '' : ' aria-hidden="true"' ?>>
            <picture>
              <source type="image/webp" srcset="<?= e($webp) ?>">
              <img src="<?= e($jpg) ?>"
                   alt="<?= e($slide['alt']) ?>"
                   width="1400" height="1050"
                   decoding="async"
                   <?= $active ? 'fetchpriority="high"' : 'loading="lazy"' ?>>
            </picture>
            <figcaption class="hero-slide-caption"><?= e($slide['label']) ?></figcaption>
          </figure>
        <?php endforeach; ?>
      </div>

      <button type="button" class="hero-nav hero-nav-prev" data-hero-prev aria-label="Previous photo">
        <?= ui_icon('arrow-left', 'icn-sm') ?>
      </button>
      <button type="button" class="hero-nav hero-nav-next" data-hero-next aria-label="Next photo">
        <?= ui_icon('arrow-right', 'icn-sm') ?>
      </button>

      <div class="hero-dots" role="tablist" aria-label="Choose hero photo">
        <?php foreach ($heroSlides as $i => $slide): ?>
          <button type="button"
                  class="hero-dot<?= $i === 0 ? ' is-on' : '' ?>"
                  role="tab"
                  data-hero-dot="<?= (int)$i ?>"
                  aria-label="<?= e($slide['label']) ?>"
                  aria-selected="<?= $i === 0 ? 'true' : 'false' ?>"></button>
        <?php endforeach; ?>
      </div>
      <p class="sr-only" data-hero-status aria-live="polite">Showing Electronics</p>
    </div>
  </div>
</section>

<form method="GET" action="<?= APP_BASE_URL ?>/public/browse.php" class="explore-bar" role="search">
  <label class="sr-only" for="home-q">Search products</label>
  <span class="explore-search">
    <?= ui_icon('search', 'icn-sm') ?>
    <input type="search" id="home-q" name="q" placeholder="Search by product, description or seller" autocomplete="off">
  </span>
  <label class="sr-only" for="home-cat">Category</label>
  <select id="home-cat" name="category">
    <option value="">All categories</option>
    <?php foreach ($cats as $c): ?>
      <option value="<?= e($c['name']) ?>"><?= e($c['name']) ?></option>
    <?php endforeach; ?>
  </select>
  <button type="submit" class="btn btn-primary"><?= ui_icon('search', 'icn-sm') ?> Search</button>
</form>

<?php if ($cats): ?>
<section class="section">
  <div class="sec-hdr">
    <div>
      <h2>Shop by category</h2>
      <p>Categories currently listed on CampusLink.</p>
    </div>
    <a href="<?= APP_BASE_URL ?>/public/browse.php">All products <?= ui_icon('arrow-right', 'icn-xs') ?></a>
  </div>
  <div class="cat-grid">
    <?php foreach ($cats as $c): ?>
      <a class="cat-tile cat-tile-photo" href="<?= APP_BASE_URL ?>/public/browse.php?category=<?= rawurlencode($c['name']) ?>">
        <span class="cat-tile-media" aria-hidden="true">
          <?= ui_category_picture($c['name'], 'cat-tile-picture', '') ?>
        </span>
        <span class="cat-tile-copy">
          <span class="cat-tile-name"><?= e($c['name']) ?></span>
          <span class="cat-tile-count"><?= (int)$c['listings'] === 1 ? '1 product' : (int)$c['listings'] . ' products' ?></span>
        </span>
      </a>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<?php if ($featured): ?>
<section class="section">
  <div class="sec-hdr">
    <div>
      <h2>Featured products</h2>
      <p>In-stock products that include a photo.</p>
    </div>
    <a href="<?= APP_BASE_URL ?>/public/browse.php?availability=in">In stock <?= ui_icon('arrow-right', 'icn-xs') ?></a>
  </div>
  <div class="product-grid">
    <?php foreach ($featured as $p) echo ui_product_card($p); ?>
  </div>
</section>
<?php endif; ?>

<?php if ($recent): ?>
<section class="section">
  <div class="sec-hdr">
    <div>
      <h2>Recently added</h2>
      <p>The newest products from campus vendors.</p>
    </div>
    <a href="<?= APP_BASE_URL ?>/public/browse.php">See all <?= ui_icon('arrow-right', 'icn-xs') ?></a>
  </div>
  <div class="product-grid">
    <?php foreach ($recent as $p) echo ui_product_card($p); ?>
  </div>
</section>
<?php endif; ?>

<?php if ($popular): ?>
<section class="section">
  <div class="sec-hdr">
    <div>
      <h2>Popular products</h2>
      <p>Ranked by quantity sold on paid and fulfilled orders.</p>
    </div>
  </div>
  <div class="product-grid">
    <?php foreach ($popular as $p) echo ui_product_card($p); ?>
  </div>
</section>
<?php endif; ?>

<?php if ($sellers): ?>
<section class="section">
  <div class="sec-hdr">
    <div>
      <h2>Vendors on campus</h2>
      <p>Stores with at least one product.</p>
    </div>
  </div>
  <div class="seller-grid">
    <?php foreach ($sellers as $seller):
        $name = (string)$seller['name'];
        $initial = strtoupper(substr(trim($name), 0, 1));
        $count = (int)$seller['listings'];
    ?>
      <a class="seller-tile" href="<?= APP_BASE_URL ?>/public/browse.php?seller=<?= rawurlencode($name) ?>">
        <span class="avatar" aria-hidden="true"><?= e($initial !== '' ? $initial : 'V') ?></span>
        <span>
          <span class="seller-name"><?= e($name) ?></span>
          <span class="seller-count"><?= $count === 1 ? '1 product' : $count . ' products' ?></span>
        </span>
        <?= ui_icon('chevron-right', 'icn-sm') ?>
      </a>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<?php if (!$hasListings): ?>
  <?= ui_empty(
        'store',
        'No products yet',
        'When vendors publish products, they will appear here.',
        $canSell ? '<a class="btn btn-primary" href="' . e($sellHref) . '">Add Product</a>' : ''
      ) ?>
<?php endif; ?>

<section class="section">
  <div class="feature-band">
    <div class="feature">
      <span class="fi"><?= ui_icon('search', 'icn') ?></span>
      <div>
        <h3>Search the catalogue</h3>
        <p>Find products by name, description, category or vendor, then narrow by price and availability.</p>
      </div>
    </div>
    <div class="feature">
      <span class="fi"><?= ui_icon('shield', 'icn') ?></span>
      <div>
        <h3>Campus checkout</h3>
        <?php if (payment_gateway_mode() === 'paystack'): ?>
          <p>Orders are recorded immediately. Paystack confirms the payment before vendors start fulfilment. Prices are shown in GHC.</p>
        <?php else: ?>
          <p>Orders are recorded immediately. Payment stays pending until an administrator confirms campus payment.</p>
        <?php endif; ?>
      </div>
    </div>
    <div class="feature">
      <span class="fi"><?= ui_icon('truck', 'icn') ?></span>
      <div>
        <h3>Track Order</h3>
        <p>Use your order reference and private tracking link to follow fulfilment.</p>
      </div>
    </div>
  </div>
</section>

<section class="cta-band section">
  <div>
    <h2>Selling to the University of Juba community</h2>
    <p>Create a vendor account, add a photo and a price, and your product appears in the marketplace.</p>
  </div>
  <div class="cta-actions">
    <?php if ($canSell): ?>
      <a href="<?= e($sellHref) ?>" class="btn btn-accent btn-lg">Add Product</a>
    <?php endif; ?>
    <a href="<?= APP_BASE_URL ?>/public/browse.php" class="btn btn-white btn-lg">Browse Products</a>
  </div>
</section>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
